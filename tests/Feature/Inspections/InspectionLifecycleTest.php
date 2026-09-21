<?php

/**
 * The inspection lifecycle through its chokepoint,
 * App\Actions\Inspections\TransitionInspectionStatus — the ONE writer of
 * SiteInspection::$status.
 *
 * tests/Unit/InspectionStatusTransitionTest.php already proves the TABLE in
 * isolation (what is structurally possible for everyone). This file proves the
 * rest of the chokepoint, which the enum cannot: that each legal step is made
 * by the right actor and stamps the right chain, that the append-only ledger
 * records it, that the domain preconditions bite, and that the separation
 * guard holds for the one person the permission matrix cannot stop — an M&E
 * officer, who holds both `inspections.conduct` and `inspections.review`.
 *
 * Every rule that reads a statutory number reads it from the SettingsRepository
 * the Action reads, never from a literal: `inspections.report_due_days` and
 * `inspections.require_photo_evidence` are policy, and a test that hard-codes
 * them passes against a platform that has stopped honouring them.
 */

use App\Actions\Documents\AttachDocument;
use App\Actions\Inspections\CancelInspection;
use App\Actions\Inspections\RecordChecklistResponses;
use App\Actions\Inspections\ReviewInspectionReport;
use App\Actions\Inspections\ScheduleInspection;
use App\Actions\Inspections\StartInspection;
use App\Actions\Inspections\SubmitInspectionReport;
use App\Actions\Inspections\TransitionInspectionStatus;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Exceptions\Inspections\InvalidInspectionTransition;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Inspections\InspectionCancelled;
use App\Notifications\Inspections\InspectionOutcomeEscalated;
use App\Notifications\Inspections\InspectionReportSubmitted;
use App\Notifications\Inspections\InspectionScheduled;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
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

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    $this->settings = app(SettingsRepository::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A photograph in the evidence vault — what
 * `inspections.require_photo_evidence` asks for before a report may be filed.
 *
 * `->image()` and not `->create()`: a zero-byte fake sniffs as
 * application/x-empty and is (correctly) refused by the server-side mime check.
 */
function attachSitePhoto(SiteInspection $inspection, User $actor, string $name = 'site.jpg'): void
{
    app(AttachDocument::class)(
        $inspection,
        'inspection_photos',
        UploadedFile::fake()->image($name, 800, 600),
        $actor,
    );
}

/** A visit under way, with its evidence in place, ready to be filed. */
function visitUnderWay(Project $project, User $inspector): SiteInspection
{
    $inspection = SiteInspection::factory()
        ->forProject($project)
        ->ledBy($inspector)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $inspector);

    attachSitePhoto($inspection, $inspector);

    return SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();
}

/* -------------------------------------------------------------------------- */
/* Every allowed transition, by the actor who owns it */
/* -------------------------------------------------------------------------- */

it('puts a visit in the diary and opens the ledger with who scheduled it', function () {
    $inspection = app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now()->addDays(2),
        leadInspector: $this->monitor,
        actor: $this->officer,
    );

    expect($inspection->status)->toBe(InspectionStatus::Scheduled)
        ->and($inspection->scheduled_by_id)->toBe($this->officer->id)
        ->and($inspection->generated_by)->toBe('manual')
        // Objectives pre-filled from the visit type: "objectives: (blank)" is
        // the first thing that makes a Field Trip Report worthless.
        ->and($inspection->objectives)->toBe(InspectionType::Routine->purpose());

    $event = SiteInspectionEvent::query()->where('site_inspection_id', $inspection->id)->sole();

    expect($event->isCreation())->toBeTrue()
        ->and($event->to_status)->toBe(InspectionStatus::Scheduled)
        ->and($event->actor_id)->toBe($this->officer->id);

    Notification::assertSentTo($this->monitor, InspectionScheduled::class);
});

it('starts the visit when the inspector opens the form, stamping the visit time and the report clock', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        // Three days late to the site: the report is dated by the VISIT, not
        // by the plan, or the record is falsified.
        ->scheduled(CarbonImmutable::now()->subDays(3))
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);

    $opened = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();
    $dueDays = $this->settings->int('inspections', 'report_due_days', 3);

    expect($opened->status)->toBe(InspectionStatus::InProgress)
        ->and($opened->started_at?->toDateTimeString())->toBe(Carbon::now()->toDateTimeString())
        ->and($opened->conducted_at?->toDateTimeString())->toBe(Carbon::now()->toDateTimeString())
        ->and($opened->report_due_at?->toDateTimeString())
        ->toBe(Carbon::now()->addDays($dueDays)->endOfDay()->toDateTimeString());
});

it('does not move the visit time when the form is opened twice', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);
    $firstOpened = $inspection->conducted_at;

    // A refresh, a second tab, a reconnect after the 3G dropped.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 14:00:00'));

    app(StartInspection::class)($inspection, $this->monitor);

    $again = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect($again->conducted_at?->toDateTimeString())->toBe($firstOpened?->toDateTimeString())
        ->and(SiteInspectionEvent::query()->where('site_inspection_id', $inspection->id)->count())->toBe(1);
});

it('files the report, freezes the record and tells the officers who must sign it off', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);

    app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::Satisfactory,
        [
            'findings' => 'Sub-base laid across 1.2 km of the northern section; drainage cast at three of five crossings.',
            'recommendations' => 'Cover stored reinforcement before the next visit.',
            'risk_flags' => ['behind_schedule'],
        ],
    );

    $filed = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect($filed->status)->toBe(InspectionStatus::Submitted)
        ->and($filed->submitted_by_id)->toBe($this->monitor->id)
        ->and($filed->submitted_at?->toDateTimeString())->toBe(Carbon::now()->toDateTimeString())
        ->and($filed->outcome)->toBe(InspectionOutcome::Satisfactory)
        ->and($filed->risk_flags)->toBe(['behind_schedule'])
        ->and($filed->report_late)->toBeFalse()
        ->and($filed->isEditable())->toBeFalse();

    Notification::assertSentTo($this->officer, InspectionReportSubmitted::class);
    Notification::assertSentTo($this->admin, InspectionReportSubmitted::class);
    // The actor is never told what they just did.
    Notification::assertNotSentTo($this->monitor, InspectionReportSubmitted::class);
});

it('escalates a failing site to the people who can halt a payment', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);

    app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::WorkStopped,
        ['findings' => 'Work has halted on site; the contractor’s plant was withdrawn on the 14th.'],
    );

    Notification::assertSentTo($this->admin, InspectionOutcomeEscalated::class);
    // An M&E officer is not an MDA admin: they get the sign-off notice, not
    // the escalation.
    Notification::assertNotSentTo($this->officer, InspectionOutcomeEscalated::class);
});

it('signs off a filed report and closes the lifecycle', function () {
    // Scheduled through the Action, not the factory, so the ledger carries the
    // whole story — the creation row included.
    $inspection = app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now(),
        leadInspector: $this->monitor,
        actor: $this->officer,
    );

    app(StartInspection::class)($inspection, $this->monitor);
    attachSitePhoto($inspection, $this->monitor);

    $inspection = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::MinorIssues,
        ['findings' => 'Housekeeping at the site store; otherwise to specification.'],
    );

    app(ReviewInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->officer,
        'Findings accepted. Housekeeping item carried to the issues register.',
    );

    $signed = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect($signed->status)->toBe(InspectionStatus::Reviewed)
        ->and($signed->reviewed_by_id)->toBe($this->officer->id)
        ->and($signed->review_notes)->toBe('Findings accepted. Housekeeping item carried to the issues register.')
        ->and($signed->status->isTerminal())->toBeTrue();

    // The whole history, in order, in the append-only ledger.
    $chain = SiteInspectionEvent::query()
        ->where('site_inspection_id', $inspection->id)
        ->orderBy('id')
        ->get();

    expect($chain->pluck('to_status')->all())->toBe([
        InspectionStatus::Scheduled,
        InspectionStatus::InProgress,
        InspectionStatus::Submitted,
        InspectionStatus::Reviewed,
    ])->and($chain->last()->actor_id)->toBe($this->officer->id);
});

it('cancels a visit from the diary, with the reason on the record', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    app(CancelInspection::class)(
        $inspection,
        $this->officer,
        'Access road impassable after three days of rain; visit deferred to the next cycle.',
    );

    $cancelled = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect($cancelled->status)->toBe(InspectionStatus::Cancelled)
        ->and($cancelled->cancelled_by_id)->toBe($this->officer->id)
        ->and($cancelled->cancellation_reason)->toContain('Access road impassable');

    // The inspector learns their visit is off, and why — otherwise they drive
    // to a site that has been cancelled.
    Notification::assertSentTo($this->monitor, InspectionCancelled::class);
});

it('cancels a visit that is already under way', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);

    app(CancelInspection::class)(
        $inspection,
        $this->officer,
        'Project suspended overnight by the Ministry; the visit cannot proceed.',
    );

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Cancelled);
});

/* -------------------------------------------------------------------------- */
/* Forbidden moves */
/* -------------------------------------------------------------------------- */

it('refuses to file a report for a visit that was never opened', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    expect(fn () => app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Everything in order, from the office.'],
    ))->toThrow(InvalidInspectionTransition::class);

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Scheduled);
});

it('refuses to cancel a filed report — a government record is corrected, never withdrawn', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create();

    expect(fn () => app(CancelInspection::class)($inspection, $this->admin, 'Filed in error.'))
        ->toThrow(InvalidInspectionTransition::class);

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Submitted);
});

it('refuses to reopen a signed-off inspection', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->reviewed($this->officer)
        ->create();

    expect(fn () => app(TransitionInspectionStatus::class)(
        $inspection,
        InspectionStatus::InProgress,
        $this->officer,
    ))->toThrow(InvalidInspectionTransition::class);
});

it('refuses a cancellation with no stated reason', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    expect(fn () => app(CancelInspection::class)($inspection, $this->officer, '   '))
        ->toThrow(InspectionRuleViolation::class, 'requires a stated reason');
});

it('refuses a transition whose row has already moved under a concurrent officer', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);

    app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    );

    // A stale in-memory copy — two officers with the detail screen open. The
    // second must find the row already moved rather than overwrite the first.
    $stale = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();
    $stale->setRawAttributes(['status' => InspectionStatus::InProgress->value] + $stale->getAttributes(), true);

    expect(fn () => app(SubmitInspectionReport::class)(
        $stale,
        $this->monitor,
        InspectionOutcome::MajorIssues,
        ['findings' => 'A second filing of the same visit.'],
    ))->toThrow(InvalidInspectionTransition::class);

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('outcome'))
        ->toBe(InspectionOutcome::Satisfactory);
});

/* -------------------------------------------------------------------------- */
/* Separation of duties */
/* -------------------------------------------------------------------------- */

it('refuses to let the lead inspector sign off their own report', function () {
    // The case the permission matrix cannot catch: an M&E officer holds BOTH
    // `inspections.conduct` and `inspections.review`, routinely conducts
    // visits in a small MDA, and would otherwise file and clear the same
    // report. The guard is a domain rule in the chokepoint, not a permission.
    $inspection = visitUnderWay($this->project, $this->officer);

    app(SubmitInspectionReport::class)(
        $inspection,
        $this->officer,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    );

    expect(fn () => app(ReviewInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->officer,
    ))->toThrow(InspectionRuleViolation::class, 'cannot sign off their own report');

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Submitted);
});

it('refuses to let whoever filed the report sign it off, even if they did not lead the visit', function () {
    // A team member types up the lead's report. Either identity is
    // disqualifying — both are "the person who already acted on this row".
    $inspection = visitUnderWay($this->project, $this->monitor);

    app(SubmitInspectionReport::class)(
        $inspection,
        $this->admin,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Typed up from the lead inspector’s field notes of the 19th.'],
    );

    expect(fn () => app(ReviewInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->admin,
    ))->toThrow(InspectionRuleViolation::class, 'cannot sign off their own report');

    // A second officer signs it off without difficulty.
    app(ReviewInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->officer,
    );

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Reviewed);
});

it('refuses sign-off to a field monitor, who never holds the review permission', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->admin)
        ->submitted($this->admin)
        ->create();

    expect(fn () => app(ReviewInspectionReport::class)($inspection, $this->monitor))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* Filing preconditions */
/* -------------------------------------------------------------------------- */

it('refuses to file a report with no verdict on the site', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);

    expect(fn () => app(TransitionInspectionStatus::class)(
        $inspection,
        InspectionStatus::Submitted,
        $this->monitor,
        null,
        ['findings' => 'Observations recorded, no verdict given.'],
    ))->toThrow(InspectionRuleViolation::class, 'without a verdict');
});

it('refuses to file a report with an empty findings section', function () {
    $inspection = visitUnderWay($this->project, $this->monitor);

    expect(fn () => app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => '   '],
    ))->toThrow(InspectionRuleViolation::class, 'without findings');
});

it('refuses to file a report while a required checklist item is unanswered', function () {
    $template = InspectionChecklistTemplate::factory()->withItems(2)->create();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($template)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);
    attachSitePhoto($inspection, $this->monitor);

    $inspection = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect(fn () => app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    ))->toThrow(InspectionRuleViolation::class, 'required item(s) still unanswered');

    // Answer both required items and the same filing goes through.
    $required = $template->items()->where('is_required', true)->get();

    app(RecordChecklistResponses::class)(
        $inspection,
        $this->monitor,
        $required->mapWithKeys(fn ($item): array => [$item->id => ['value' => true]])->all(),
    );

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    );

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Submitted);
});

it('blocks a filing with no photograph while the instance requires evidence', function () {
    // Read from the SettingsRepository the Action reads, not asserted against
    // a literal: this is a policy number a state may turn off.
    expect($this->settings->bool('inspections', 'require_photo_evidence', true))->toBeTrue();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);

    $inspection = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect(fn () => app(SubmitInspectionReport::class)(
        $inspection,
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    ))->toThrow(InspectionRuleViolation::class, 'At least one photograph is required');

    attachSitePhoto($inspection, $this->monitor);

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    );

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Submitted);
});

it('lets a state that inspects without mobile coverage file with no photograph at all', function () {
    config()->set('platform.inspections.require_photo_evidence', false);

    expect($this->settings->bool('inspections', 'require_photo_evidence', true))->toBeFalse();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Work proceeding to specification across the northern section.'],
    );

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Submitted);
});

/* -------------------------------------------------------------------------- */
/* The report deadline */
/* -------------------------------------------------------------------------- */

it('judges a report late exactly once, at filing, against the window in force at the visit', function () {
    $dueDays = $this->settings->int('inspections', 'report_due_days', 3);

    $inspection = visitUnderWay($this->project, $this->monitor);

    // A day past the snapshotted deadline.
    Carbon::setTestNow(CarbonImmutable::now()->addDays($dueDays + 1));

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Written up on return to the office, later than it should have been.'],
    );

    $filed = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect($filed->report_late)->toBeTrue()
        // Judged once and never recomputed: retuning the window afterwards
        // must not rescue a late report or condemn a timely one.
        ->and($filed->isReportOverdue())->toBeFalse();

    config()->set('platform.inspections.report_due_days', 30);

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('report_late'))->toBeTrue();
});

it('honours a longer reporting window set for the instance', function () {
    config()->set('platform.inspections.report_due_days', 10);

    $inspection = visitUnderWay($this->project, $this->monitor);

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('report_due_at')?->toDateTimeString())
        ->toBe(Carbon::now()->addDays(10)->endOfDay()->toDateTimeString());

    Carbon::setTestNow(CarbonImmutable::now()->addDays(5));

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Filed inside the longer window this instance allows.'],
    );

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('report_late'))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* Scheduling preconditions */
/* -------------------------------------------------------------------------- */

it('refuses to schedule a visit against a project with no site to inspect', function (ProjectStatus $status) {
    $project = Project::factory()->state(['status' => $status])->create();

    expect(fn () => app(ScheduleInspection::class)(
        project: $project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now()->addDay(),
        leadInspector: $this->monitor,
        actor: $this->officer,
    ))->toThrow(InspectionRuleViolation::class, 'cannot be scheduled for a site inspection');
})->with([
    'draft' => [ProjectStatus::Draft],
    'cancelled' => [ProjectStatus::Cancelled],
]);

it('schedules post-completion monitoring against a certified project, which is the point of step 6', function () {
    $certified = Project::factory()->certified()->create();

    $inspection = app(ScheduleInspection::class)(
        project: $certified,
        type: InspectionType::PostCompletion,
        scheduledDate: CarbonImmutable::now()->addMonths(6),
        leadInspector: $this->monitor,
        actor: $this->officer,
    );

    expect($inspection->type)->toBe(InspectionType::PostCompletion);
});

it('refuses a visit planned for a date already gone', function () {
    expect(fn () => app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now()->subDay(),
        leadInspector: $this->monitor,
        actor: $this->officer,
    ))->toThrow(InspectionRuleViolation::class, 'date that has already passed');
});

it('refuses to name a lead inspector who could never file the report', function () {
    expect(fn () => app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now()->addDay(),
        leadInspector: User::query()->whereKey($this->consultant->id)->firstOrFail(),
        actor: $this->officer,
    ))->toThrow(InspectionRuleViolation::class, 'does not hold `inspections.conduct`');
});

/* -------------------------------------------------------------------------- */
/* The ledger is append-only */
/* -------------------------------------------------------------------------- */

it('refuses to edit or delete a lifecycle event', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    $event = SiteInspectionEvent::factory()->forInspection($inspection)->by($this->officer)->create();

    expect(fn () => $event->update(['reason' => 'rewritten after the fact']))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});
