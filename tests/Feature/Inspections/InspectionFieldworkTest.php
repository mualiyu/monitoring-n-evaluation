<?php

/**
 * What happens on site: the checklist, the autosaved Field Trip Report, the
 * GPS fix and its geofence judgement.
 *
 * These are the two Actions the conduct form calls on every keystroke —
 * RecordChecklistResponses and SaveInspectionFieldNotes. The properties that
 * matter are the ones a flaky 3G connection exposes: one answer per item
 * however many times it is re-sent, an answer withdrawn is null rather than
 * zero, and everything freezes the moment the report is filed.
 *
 * `inspections.geofence_metres` is read from the SettingsRepository the Action
 * reads, never asserted against a literal: it is a policy number a state
 * retunes, and the judgement it produces is snapshotted so a later change
 * cannot rewrite a report printed years ago.
 */

use App\Actions\Inspections\RecordChecklistResponses;
use App\Actions\Inspections\SaveInspectionFieldNotes;
use App\Actions\Inspections\StartInspection;
use App\Enums\ChecklistResponseType;
use App\Enums\InspectionOutcome;
use App\Enums\Role;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Models\InspectionChecklistTemplate;
use App\Models\InspectionChecklistTemplateItem;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\SiteInspection;
use App\Models\SiteInspectionResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    // A yes/no that fails on "no", a 1–5 rating where 2 or below is a finding,
    // a descriptive number that is never a failure, and a written answer that
    // is never auto-judged. Every branch of the judging rule.
    $this->template = InspectionChecklistTemplate::factory()->withItems(4)->create();

    $this->inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($this->template)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($this->inspection, $this->monitor);

    $this->inspection = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    $this->items = $this->template->items()->orderBy('position')->get()
        ->keyBy(fn (InspectionChecklistTemplateItem $item): string => $item->response_type->value);

    $this->settings = app(SettingsRepository::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* The checklist */
/* -------------------------------------------------------------------------- */

it('records an answer of every shape into the column that shape belongs in', function () {
    $recorded = app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $this->items[ChecklistResponseType::YesNo->value]->id => ['value' => true],
        $this->items[ChecklistResponseType::Rating->value]->id => ['value' => '4'],
        $this->items[ChecklistResponseType::Numeric->value]->id => ['value' => 14],
        $this->items[ChecklistResponseType::Text->value]->id => ['value' => '  Laterite, passable in dry weather only.  '],
    ]);

    expect($recorded)->toBe(4);

    $answers = SiteInspectionResponse::query()
        ->where('site_inspection_id', $this->inspection->id)
        ->get()
        ->keyBy('inspection_checklist_template_item_id');

    $yesNo = $answers[$this->items[ChecklistResponseType::YesNo->value]->id];
    $rating = $answers[$this->items[ChecklistResponseType::Rating->value]->id];
    $numeric = $answers[$this->items[ChecklistResponseType::Numeric->value]->id];
    $text = $answers[$this->items[ChecklistResponseType::Text->value]->id];

    expect($yesNo->value_boolean)->toBeTrue()
        ->and($yesNo->answer())->toBeTrue()
        ->and($rating->value_number)->toBe('4.00')
        ->and($numeric->value_number)->toBe('14.00')
        ->and($text->value_text)->toBe('Laterite, passable in dry weather only.')
        ->and($text->value_number)->toBeNull();
});

it('snapshots the prompt and the response type as they read on the day of the visit', function () {
    $item = $this->items[ChecklistResponseType::YesNo->value];

    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $item->id => ['value' => true],
    ]);

    // The state rewords its instrument afterwards, as states do.
    $item->update(['prompt' => 'Does the executed work match the approved drawings?']);

    $response = SiteInspectionResponse::query()
        ->where('inspection_checklist_template_item_id', $item->id)
        ->sole();

    expect($response->prompt)->toBe('Is the work on site consistent with the approved drawings and specification?')
        ->and($response->response_type)->toBe(ChecklistResponseType::YesNo);
});

it('judges a "no" a finding, and refuses to record one with no explanation', function () {
    $item = $this->items[ChecklistResponseType::YesNo->value];

    expect(fn () => app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $item->id => ['value' => false],
    ]))->toThrow(InspectionRuleViolation::class, 'needs a note saying what is actually wrong');

    expect(SiteInspectionResponse::query()->count())->toBe(0);

    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $item->id => ['value' => false, 'note' => 'Blinding poured onto uncompacted fill on the eastern bay.'],
    ]);

    $response = SiteInspectionResponse::query()->sole();

    expect($response->is_finding)->toBeTrue()
        ->and($response->note)->toBe('Blinding poured onto uncompacted fill on the eastern bay.');
});

it('judges a rating against the item’s threshold and never judges prose', function () {
    $rating = $this->items[ChecklistResponseType::Rating->value];
    $text = $this->items[ChecklistResponseType::Text->value];

    // Threshold is 2.00 on the factory's rating item: at or below is a finding.
    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $rating->id => ['value' => '2', 'note' => 'Workmanship poor on the eastern bay.'],
        // Prose a keyword match would happily misread.
        $text->id => ['value' => 'No defects of any kind were observed.'],
    ]);

    $answers = SiteInspectionResponse::query()->get()->keyBy('inspection_checklist_template_item_id');

    expect($answers[$rating->id]->is_finding)->toBeTrue()
        ->and($answers[$text->id]->is_finding)->toBeFalse();

    // One point higher is not a finding, and needs no note.
    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $rating->id => ['value' => '3'],
    ]);

    expect(SiteInspectionResponse::query()->whereKey($answers[$rating->id]->id)->value('is_finding'))
        ->toBeFalse();
});

it('keeps the stored judgement when the state retunes the instrument afterwards', function () {
    $rating = $this->items[ChecklistResponseType::Rating->value];

    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $rating->id => ['value' => '2', 'note' => 'Workmanship poor on the eastern bay.'],
    ]);

    // A retuned threshold must not rewrite what a report from two years ago
    // says was wrong.
    $rating->update(['finding_threshold' => '1.00']);

    expect(SiteInspectionResponse::query()->sole()->is_finding)->toBeTrue();
});

it('keeps exactly one answer per item however many times a flaky connection re-sends it', function () {
    $item = $this->items[ChecklistResponseType::Numeric->value];

    foreach (['11', '12', '13'] as $value) {
        app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
            $item->id => ['value' => $value],
        ]);
    }

    expect(SiteInspectionResponse::query()->where('inspection_checklist_template_item_id', $item->id)->count())
        ->toBe(1)
        ->and(SiteInspectionResponse::query()->sole()->value_number)->toBe('13.00');
});

it('treats a cleared field as an answer withdrawn, not as a zero', function () {
    $item = $this->items[ChecklistResponseType::Numeric->value];

    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $item->id => ['value' => '14'],
    ]);

    app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $item->id => ['value' => '   '],
    ]);

    $response = SiteInspectionResponse::query()->sole();

    expect($response->value_number)->toBeNull()
        ->and($response->isAnswered())->toBeFalse();
});

it('refuses an item that is not on the instrument this visit is being conducted against', function () {
    // The template table is GLOBAL, shared across every MDA, so an unchecked
    // item id is the one identifier here a caller could supply from outside
    // their own workspace. It would leak nothing and corrupt everything.
    $foreign = InspectionChecklistTemplate::factory()->withItems(1)->create();

    expect(fn () => app(RecordChecklistResponses::class)($this->inspection, $this->monitor, [
        $foreign->items->first()->id => ['value' => true],
    ]))->toThrow(InspectionRuleViolation::class, 'does not belong to the instrument');

    expect(SiteInspectionResponse::query()->count())->toBe(0);
});

it('refuses to record answers once the report has been filed', function () {
    $filed = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($this->template)
        ->submitted($this->monitor)
        ->create();

    expect(fn () => app(RecordChecklistResponses::class)($filed, $this->monitor, [
        $this->items[ChecklistResponseType::YesNo->value]->id => ['value' => true],
    ]))->toThrow(InspectionRuleViolation::class, 'Open the conduct form first');
});

it('refuses checklist answers from a consultant', function () {
    expect(fn () => app(RecordChecklistResponses::class)($this->inspection, $this->consultant, [
        $this->items[ChecklistResponseType::YesNo->value]->id => ['value' => true],
    ]))->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* The autosaved report */
/* -------------------------------------------------------------------------- */

it('autosaves the narrative onto the inspection row and stamps when it happened', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 11:45:00'));

    app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [
        'findings' => 'Sub-base laid across 1.2 km of the northern section.',
        'people_met' => 'Site engineer and the contractor’s project manager.',
        'physical_progress_observed' => '42.5',
        'outcome' => InspectionOutcome::MinorIssues,
        'risk_flags' => ['behind_schedule'],
    ]);

    $saved = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    expect($saved->findings)->toBe('Sub-base laid across 1.2 km of the northern section.')
        ->and($saved->physical_progress_observed)->toBe('42.50')
        ->and($saved->outcome)->toBe(InspectionOutcome::MinorIssues)
        ->and($saved->risk_flags)->toBe(['behind_schedule'])
        ->and($saved->autosaved_at?->toDateTimeString())->toBe('2026-09-21 11:45:00');
});

it('ignores anything the autosave is not allowed to write', function () {
    app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [
        'findings' => 'Observations as recorded.',
        // Chokepoint columns, smuggled in on the autosave payload.
        'status' => 'reviewed',
        'reviewed_by_id' => $this->officer->id,
        'report_late' => false,
        'latitude' => '9.0000000',
    ]);

    $saved = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    expect($saved->status->value)->toBe('in_progress')
        ->and($saved->reviewed_by_id)->toBeNull()
        ->and($saved->latitude)->toBeNull();
});

it('refuses an observed progress figure outside 0–100', function () {
    expect(fn () => app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [
        'physical_progress_observed' => '140',
    ]))->toThrow(InspectionRuleViolation::class, 'must be between 0 and 100')
        ->and(fn () => app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [
            'physical_progress_observed' => 'about half',
        ]))->toThrow(InspectionRuleViolation::class);
});

it('refuses to autosave once the report has been filed', function () {
    $filed = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create();

    expect(fn () => app(SaveInspectionFieldNotes::class)($filed, $this->monitor, [
        'findings' => 'Rewritten after the officer read it.',
    ]))->toThrow(InspectionRuleViolation::class, 'no longer editable');
});

/* -------------------------------------------------------------------------- */
/* The GPS fix and its geofence judgement */
/* -------------------------------------------------------------------------- */

it('records a fix at the site as decimal strings and inside the fence', function () {
    ProjectLocation::factory()
        ->for($this->project)
        ->primary()
        ->coordinates('7.2570000', '5.2050000')
        ->create();

    app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [], [
        'latitude' => 7.2572,
        'longitude' => 5.2048,
        'accuracy' => 12.6,
    ]);

    $saved = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    expect($saved->latitude)->toBe('7.2572000')
        ->and($saved->longitude)->toBe('5.2048000')
        // The Action stores what the browser reported, truncated to whole
        // metres; the conduct component is what rounds a float first.
        ->and($saved->gps_accuracy_metres)->toBe(12)
        ->and($saved->gps_captured_at?->toDateTimeString())->toBe(Carbon::now()->toDateTimeString())
        ->and($saved->geofence_distance_metres)->toBeLessThan(100)
        ->and($saved->geofence_breached)->toBeFalse();
});

it('flags a fix further from the site than the state allows', function () {
    $fence = $this->settings->int('inspections', 'geofence_metres', 2000);

    ProjectLocation::factory()
        ->for($this->project)
        ->primary()
        ->coordinates('7.2570000', '5.2050000')
        ->create();

    // Roughly a degree of latitude away — about 111 km, comfortably outside
    // any sane fence, whatever the instance has set.
    app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [], [
        'latitude' => 8.2570,
        'longitude' => 5.2050,
    ]);

    $saved = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    expect($saved->geofence_distance_metres)->toBeGreaterThan($fence)
        ->and($saved->geofence_breached)->toBeTrue();
});

it('lets an instance switch the fence off entirely', function () {
    // 0 disables the check, per config/platform.php — a state whose site
    // coordinates are unreliable must not train officers to ignore a flag.
    config()->set('platform.inspections.geofence_metres', 0);

    ProjectLocation::factory()
        ->for($this->project)
        ->primary()
        ->coordinates('7.2570000', '5.2050000')
        ->create();

    app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [], [
        'latitude' => 8.2570,
        'longitude' => 5.2050,
    ]);

    $saved = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    expect($saved->geofence_distance_metres)->toBeGreaterThan(0)
        ->and($saved->geofence_breached)->toBeFalse();
});

it('never flags a project that has no recorded coordinates to compare against', function () {
    ProjectLocation::factory()->for($this->project)->primary()->withoutCoordinates()->create();

    app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [], [
        'latitude' => 8.2570,
        'longitude' => 5.2050,
    ]);

    $saved = SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail();

    expect($saved->geofence_distance_metres)->toBeNull()
        ->and($saved->geofence_breached)->toBeFalse()
        ->and($saved->latitude)->toBe('8.2570000');
});

it('measures against the site the visit names, not the project’s primary one', function () {
    ProjectLocation::factory()
        ->for($this->project)
        ->primary()
        ->coordinates('7.2570000', '5.2050000')
        ->create();

    $annex = ProjectLocation::factory()
        ->for($this->project)
        ->coordinates('8.2570000', '5.2050000')
        ->create(['site_name' => 'Riverbank Section']);

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->inProgress()
        ->create(['project_location_id' => $annex->id]);

    app(SaveInspectionFieldNotes::class)($inspection, $this->monitor, [], [
        'latitude' => 8.2572,
        'longitude' => 5.2048,
    ]);

    $saved = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    // At the annex, which is 111 km from the primary site: measured against
    // the wrong one this would be a breach.
    expect($saved->geofence_breached)->toBeFalse()
        ->and($saved->geofence_distance_metres)->toBeLessThan(100);
});

it('refuses coordinates that are not coordinates', function () {
    expect(fn () => app(SaveInspectionFieldNotes::class)($this->inspection, $this->monitor, [], [
        'latitude' => 191.5,
        'longitude' => 5.2050,
    ]))->toThrow(InspectionRuleViolation::class, 'not a point on Earth');
});

it('refuses a position from anyone who may not conduct this visit', function () {
    expect(fn () => app(SaveInspectionFieldNotes::class)($this->inspection, $this->consultant, [], [
        'latitude' => 7.2572,
        'longitude' => 5.2048,
    ]))->toThrow(AuthorizationException::class);
});
