<?php

/**
 * The values the inspection forms actually RENDER, fed back through the
 * validation that receives them.
 *
 * Why this file exists: Livewire::test() sets a property directly and never
 * renders an <option> or crosses HTTP, so a select that submits a LABEL where
 * the validator expects an enum value — or a record picker that submits a NAME
 * where an id is expected — passes a green suite and is unusable in a browser.
 * That exact defect shipped here once already (see
 * tests/Feature/Projects/SelectOptionValueTest.php). So every case below reads
 * the markup a browser gets and submits the value it finds there, not a value
 * the test invented.
 *
 * The two forms that matter for this module are the schedule form and the
 * conduct form — the second being the one filled in standing on a building
 * site, where a rejected submission costs an hour of observation.
 */

use App\Actions\Documents\AttachDocument;
use App\Enums\ChecklistResponseType;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\Role;
use App\Livewire\Tenant\Inspections\InspectionConduct;
use App\Livewire\Tenant\Inspections\InspectionSchedule;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\SiteInspection;
use App\Models\SiteInspectionResponse;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Every [value => label] pair inside a chunk of <option> markup. */
function inspectionOptionPairs(string $html): array
{
    preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/s', $html, $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(fn (array $m): array => [
            html_entity_decode($m[1], ENT_QUOTES) => html_entity_decode(trim($m[2]), ENT_QUOTES),
        ])
        ->all();
}

/**
 * The options of ONE named <select> in a page, so an assertion cannot pass on
 * a coincidental match elsewhere in the markup. The placeholder (value="") is
 * dropped — it is chrome, not a choice.
 *
 * @return array<string, string>
 */
function inspectionSelectOptions(string $html, string $name): array
{
    preg_match('/<select\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $block);

    return collect(inspectionOptionPairs($block[1] ?? ''))
        ->reject(fn (string $label, string $value): bool => $value === '')
        ->all();
}

/**
 * The values of one named radio group — the yes/no answer the conduct form
 * renders as two large targets rather than a dropdown.
 *
 * @return list<string>
 */
function inspectionRadioValues(string $html, string $name): array
{
    preg_match_all(
        '/<input\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*\bvalue="([^"]*)"/s',
        $html,
        $matches,
    );

    return array_values($matches[1] ?? []);
}

/* -------------------------------------------------------------------------- */
/* The schedule form */
/* -------------------------------------------------------------------------- */

it('renders the visit-type select as enum values, not as their labels', function () {
    $html = (string) $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections/create'))
        ->assertOk()
        ->getContent();

    expect(inspectionSelectOptions($html, 'type'))->toBe(
        collect(InspectionType::cases())
            ->mapWithKeys(fn (InspectionType $case): array => [$case->value => $case->label()])
            ->all(),
    );
});

it('schedules a visit using exactly the values the rendered form submits', function () {
    $html = (string) $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections/create'))
        ->assertOk()
        ->getContent();

    // Pulled out of the real markup rather than assumed. The inspector picker
    // is an id-keyed map (->pluck('name', 'id')), which is the shape that once
    // rendered the NAME as the option value and failed `integer` validation.
    $project = (string) array_search(
        'Township Road Rehabilitation',
        inspectionSelectOptions($html, 'projectUlid'),
        true,
    );
    $inspector = (string) array_search(
        $this->monitor->name,
        inspectionSelectOptions($html, 'leadInspectorId'),
        true,
    );
    $type = array_key_first(inspectionSelectOptions($html, 'type'));

    expect($project)->toBe($this->project->ulid)
        ->and($inspector)->toBe((string) $this->monitor->id);

    Livewire::actingAs($this->officer)
        ->test(InspectionSchedule::class)
        ->set('projectUlid', $project)
        ->set('leadInspectorId', $inspector)
        ->set('type', $type)
        ->set('scheduledDate', CarbonImmutable::now()->addDay()->toDateString())
        ->call('schedule')
        ->assertHasNoErrors();

    $inspection = SiteInspection::query()->sole();

    expect($inspection->lead_inspector_id)->toBe($this->monitor->id)
        ->and($inspection->project_id)->toBe($this->project->id)
        ->and($inspection->type->value)->toBe($type);
});

it('still rejects an inspector’s name submitted in place of their id', function () {
    // The guard that produced the original failure must keep biting — the fix
    // is the option value, not a loosened rule.
    Livewire::actingAs($this->officer)
        ->test(InspectionSchedule::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('leadInspectorId', $this->monitor->name)
        ->set('scheduledDate', CarbonImmutable::now()->addDay()->toDateString())
        ->call('schedule')
        ->assertHasErrors('leadInspectorId');

    expect(SiteInspection::query()->count())->toBe(0);
});

it('offers only the sites of the project actually chosen', function () {
    // An id-keyed map again, and the one the form resolves THROUGH the project
    // — a location id belonging to another project must match nothing.
    $component = Livewire::actingAs($this->officer)
        ->test(InspectionSchedule::class)
        ->set('projectUlid', $this->project->ulid);

    $location = ProjectLocation::factory()
        ->for($this->project)
        ->primary()
        ->create(['site_name' => 'Northern Section']);

    $component->set('projectUlid', '')->set('projectUlid', $this->project->ulid);

    expect(inspectionSelectOptions($component->html(), 'locationId'))
        ->toBe([(string) $location->id => 'Northern Section']);
});

/* -------------------------------------------------------------------------- */
/* The conduct form */
/* -------------------------------------------------------------------------- */

it('files the report with the verdict the rendered conduct form offers', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    // The real request: it opens the form, which starts the visit.
    $html = (string) $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertOk()
        ->getContent();

    $verdicts = inspectionSelectOptions($html, 'outcome');

    expect($verdicts)->toBe(
        collect(InspectionOutcome::cases())
            ->mapWithKeys(fn (InspectionOutcome $case): array => [$case->value => $case->label()])
            ->all(),
    );

    $inspection = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    app(AttachDocument::class)(
        $inspection,
        'inspection_photos',
        // ->image(), not ->create(): a zero-byte fake sniffs as
        // application/x-empty and is correctly refused by the mime check.
        UploadedFile::fake()->image('site.jpg', 800, 600),
        $this->monitor,
    );

    $submitted = (string) array_search(InspectionOutcome::MinorIssues->label(), $verdicts, true);

    Livewire::actingAs($this->monitor)
        ->test(InspectionConduct::class, ['inspection' => $inspection])
        ->set('outcome', $submitted)
        ->set('findings', 'Sub-base laid across 1.2 km of the northern section; drainage cast at three of five crossings.')
        ->call('submit')
        ->assertHasNoErrors();

    $filed = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    expect($filed->status)->toBe(InspectionStatus::Submitted)
        ->and($filed->outcome)->toBe(InspectionOutcome::from($submitted));
});

it('refuses a verdict label where the form submits a verdict value', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->inProgress()
        ->create();

    Livewire::actingAs($this->monitor)
        ->test(InspectionConduct::class, ['inspection' => $inspection])
        ->set('outcome', InspectionOutcome::MinorIssues->label())
        ->set('findings', 'Sub-base laid across 1.2 km of the northern section.')
        ->call('submit')
        ->assertHasErrors('outcome');

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::InProgress);
});

it('records a rating using the value its rendered select carries', function () {
    $template = InspectionChecklistTemplate::factory()->withItems(2)->create();

    $rating = $template->items()->where('response_type', ChecklistResponseType::Rating)->sole();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($template)
        ->scheduled(CarbonImmutable::now())
        ->create();

    $html = (string) $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertOk()
        ->getContent();

    $scale = inspectionSelectOptions($html, 'answers.'.$rating->id);

    // A 1–5 scale rendered as its own value, not as an array index. (PHP
    // coerces numeric array keys to int on the way in; the attribute in the
    // markup is a string either way.)
    expect(array_map(strval(...), array_keys($scale)))->toBe(['1', '2', '3', '4', '5']);

    $inspection = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    Livewire::actingAs($this->monitor)
        ->test(InspectionConduct::class, ['inspection' => $inspection])
        ->set('answers.'.$rating->id, '4')
        ->assertHasNoErrors();

    expect(SiteInspectionResponse::query()
        ->where('inspection_checklist_template_item_id', $rating->id)
        ->sole()
        ->value_number)->toBe('4.00');
});

it('records a yes/no answer using the value its rendered radios carry', function () {
    $template = InspectionChecklistTemplate::factory()->withItems(1)->create();
    $item = $template->items()->sole();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($template)
        ->scheduled(CarbonImmutable::now())
        ->create();

    $html = (string) $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertOk()
        ->getContent();

    $values = inspectionRadioValues($html, 'answer-'.$item->id);

    expect($values)->toBe(['1', '0']);

    $inspection = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    // The "no" value as the browser posts it — a string, which must land in
    // the boolean column as false and be judged a finding.
    Livewire::actingAs($this->monitor)
        ->test(InspectionConduct::class, ['inspection' => $inspection])
        ->set('notes.'.$item->id, 'Blinding poured onto uncompacted fill on the eastern bay.')
        ->set('answers.'.$item->id, $values[1])
        ->assertHasNoErrors();

    $response = SiteInspectionResponse::query()->sole();

    expect($response->value_boolean)->toBeFalse()
        ->and($response->is_finding)->toBeTrue();
});

it('shows the inspector the missing note against the item, not at the top of a long form', function () {
    $template = InspectionChecklistTemplate::factory()->withItems(1)->create();
    $item = $template->items()->sole();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($template)
        ->inProgress()
        ->create();

    Livewire::actingAs($this->monitor)
        ->test(InspectionConduct::class, ['inspection' => $inspection])
        ->set('answers.'.$item->id, '0')
        ->assertHasErrors('answers.'.$item->id);

    expect(SiteInspectionResponse::query()->count())->toBe(0);
});

it('warns on the rendered form that a photograph is required before filing', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    // The screen warns early; the control is in the chokepoint. Both halves
    // are asserted — a warning nobody enforces is a lie, and an enforcement
    // nobody warns about is a wasted trip to site.
    $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertOk()
        ->assertSee('A photograph is required');

    Livewire::actingAs($this->monitor)
        ->test(InspectionConduct::class, [
            'inspection' => SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        ])
        ->set('outcome', InspectionOutcome::Satisfactory->value)
        ->set('findings', 'Sub-base laid across 1.2 km of the northern section.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('At least one photograph is required');

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::InProgress);
});
