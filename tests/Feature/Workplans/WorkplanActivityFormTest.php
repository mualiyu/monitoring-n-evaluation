<?php

/**
 * The work-plan forms, read as MARKUP and then fed back through validation.
 *
 * Livewire::test() sets properties directly: it never renders an <option> and
 * never crosses HTTP, so a record picker that submits a row's NAME where an id
 * is expected passes a green suite and fails on the first real click. That
 * defect has shipped on this platform once already (see
 * tests/Feature/Projects/SelectOptionValueTest). The work-plan activity form
 * carries FOUR record pickers — project, output indicator, owner, dependency —
 * so every one of them is read out of the rendered HTML here and posted back
 * exactly as the browser would post it.
 *
 * The tenancy rules on those same fields are asserted alongside, because
 * BelongsToCurrentTenant is what stops a hand-edited payload naming another
 * ministry's project.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Workplans\WorkplanBuilder;
use App\Livewire\Tenant\Workplans\WorkplanCreate;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Every [value => label] pair of one named <select> in a chunk of markup.
 * The placeholder (value="") is dropped — it is chrome, not a choice.
 *
 * Named for this file: tests/Feature/Projects/SelectOptionValueTest declares
 * its own helpers globally, and Pest loads both files into one process.
 *
 * @return array<string, string>
 */
function workplanSelectOptions(string $html, string $name): array
{
    preg_match('/<select\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $block);

    preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/s', $block[1] ?? '', $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(fn (array $m): array => [
            html_entity_decode($m[1], ENT_QUOTES) => html_entity_decode(trim($m[2]), ENT_QUOTES),
        ])
        ->reject(fn (string $label, string $value): bool => $value === '')
        ->all();
}

beforeEach(function () {
    Queue::fake();
    seedPermissions();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 9));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->admin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);

    $this->plan = Workplan::factory()->forYear(2026)->ownedBy($this->officer)->by($this->officer)->create();

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    // Pinned to the same project: IndicatorFactory would otherwise create its
    // own, and the project picker's options are asserted exactly below.
    $this->indicator = Indicator::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'Kilometres of road resurfaced',
        'is_active' => true,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The activity form's markup, opened the way a user opens it. */
function activityFormHtml(User $actor, Workplan $plan): string
{
    return Livewire::actingAs($actor)
        ->test(WorkplanBuilder::class, ['workplan' => $plan])
        ->call('startAdding')
        ->html();
}

/* -------------------------------------------------------------------------- */
/* Option values */
/* -------------------------------------------------------------------------- */

it('renders every record picker on the activity form keyed by id, not by name', function () {
    $html = activityFormHtml($this->officer, $this->plan);

    expect(workplanSelectOptions($html, 'indicatorId'))
        ->toBe([(string) $this->indicator->id => 'Kilometres of road resurfaced'])
        ->and(workplanSelectOptions($html, 'projectId'))
        ->toBe([(string) $this->project->id => 'Township Road Rehabilitation'])
        ->and(workplanSelectOptions($html, 'activityOwnerId'))
        ->toBe([
            (string) $this->admin->id => 'Amina Bello',
            (string) $this->officer->id => 'Chidi Okafor',
        ]);
});

it('renders the owner picker on the create form keyed by id, over real HTTP', function () {
    $html = (string) $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans/create'))
        ->assertOk()
        ->getContent();

    expect(workplanSelectOptions($html, 'ownerId'))->toBe([
        (string) $this->admin->id => 'Amina Bello',
        (string) $this->officer->id => 'Chidi Okafor',
    ]);
});

it('renders the dependency picker keyed by id, excluding the row being edited', function () {
    $first = WorkplanActivity::factory()->forWorkplan($this->plan, 1)->create(['title' => 'Map the stakeholders']);
    $second = WorkplanActivity::factory()->forWorkplan($this->plan, 2)->create(['title' => 'Draft the indicators']);

    $adding = activityFormHtml($this->officer, $this->plan);

    expect(workplanSelectOptions($adding, 'dependsOnId'))->toBe([
        (string) $first->id => 'Map the stakeholders',
        (string) $second->id => 'Draft the indicators',
    ]);

    // An activity may not wait on itself, so it is not offered to itself.
    $editing = Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('editActivity', $second->ulid)
        ->html();

    expect(workplanSelectOptions($editing, 'dependsOnId'))
        ->toBe([(string) $first->id => 'Map the stakeholders']);
});

/* -------------------------------------------------------------------------- */
/* The rendered value, fed back through validation */
/* -------------------------------------------------------------------------- */

it('accepts the indicator, project and owner exactly as the rendered form submits them', function () {
    $html = activityFormHtml($this->officer, $this->plan);

    // Pulled out of the real markup rather than assumed. Cast because PHP
    // coerces numeric array keys to int on the way in; the attribute in the
    // HTML is a string either way.
    $indicatorId = (string) array_search('Kilometres of road resurfaced', workplanSelectOptions($html, 'indicatorId'), true);
    $projectId = (string) array_search('Township Road Rehabilitation', workplanSelectOptions($html, 'projectId'), true);
    $ownerId = (string) array_search('Amina Bello', workplanSelectOptions($html, 'activityOwnerId'), true);

    expect($indicatorId)->toBe((string) $this->indicator->id)
        ->and($projectId)->toBe((string) $this->project->id)
        ->and($ownerId)->toBe((string) $this->admin->id);

    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Resurface the Ondo–Akure corridor')
        ->set('indicatorId', $indicatorId)
        ->set('projectId', $projectId)
        ->set('activityOwnerId', $ownerId)
        ->set('plannedStart', '2026-02-01')
        ->set('plannedEnd', '2026-05-31')
        ->set('budgetAmount', '4500000.00')
        ->set('weight', '2')
        ->call('saveActivity')
        ->assertHasNoErrors();

    $activity = WorkplanActivity::query()->where('title', 'Resurface the Ondo–Akure corridor')->sole();

    expect($activity->indicator_id)->toBe($this->indicator->id)
        ->and($activity->project_id)->toBe($this->project->id)
        ->and($activity->owner_id)->toBe($this->admin->id)
        ->and($activity->budget_amount->toDecimalString())->toBe('4500000.00')
        ->and($activity->weight)->toBe(2)
        // Assigned by the Action under a lock, never posted by the form.
        ->and($activity->position)->toBe(1);
});

it('still rejects an indicator name submitted in place of an id', function () {
    // The guard that would have caught the original defect must keep biting:
    // the fix is the option value, not a loosened rule.
    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Resurface the corridor')
        ->set('indicatorId', 'Kilometres of road resurfaced')
        ->set('plannedStart', '2026-02-01')
        ->set('plannedEnd', '2026-05-31')
        ->set('budgetAmount', '100000.00')
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors('indicatorId');

    expect(WorkplanActivity::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Validation the form owes the domain */
/* -------------------------------------------------------------------------- */

it('refuses a budget that numeric alone would accept and the money cast would 500 on', function (string $amount) {
    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Badly typed budget')
        ->set('plannedStart', '2026-02-01')
        ->set('plannedEnd', '2026-05-31')
        ->set('budgetAmount', $amount)
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors('budgetAmount');
})->with([
    'a trailing decimal point' => ['5.'],
    'scientific notation' => ['1e5'],
    'a negative sum' => ['-100'],
]);

it('refuses an activity scheduled outside its own plan’s year', function () {
    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Work from another year')
        ->set('plannedStart', '2025-11-01')
        ->set('plannedEnd', '2026-02-28')
        ->set('budgetAmount', '100000.00')
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors('title');

    expect(WorkplanActivity::query()->count())->toBe(0);
});

it('refuses an activity that ends before it starts', function () {
    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Time travel')
        ->set('plannedStart', '2026-05-01')
        ->set('plannedEnd', '2026-02-01')
        ->set('budgetAmount', '100000.00')
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors('plannedEnd');
});

it('refuses an owner who is not a member of this workspace', function () {
    $stranger = User::factory()->create(['name' => 'Someone Else']);

    $html = activityFormHtml($this->officer, $this->plan);

    // Not offered…
    expect(workplanSelectOptions($html, 'activityOwnerId'))
        ->not->toHaveKey((string) $stranger->id);

    // …and refused when the payload names them anyway.
    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Assigned to a stranger')
        ->set('activityOwnerId', (string) $stranger->id)
        ->set('plannedStart', '2026-02-01')
        ->set('plannedEnd', '2026-05-31')
        ->set('budgetAmount', '100000.00')
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors('activityOwnerId');
});

it('never offers — and never accepts — another MDA’s project or indicator', function () {
    [$foreignProject, $foreignIndicator] = $this->current->runAs($this->health, fn (): array => [
        Project::factory()->ongoing()->create(['title' => 'Cottage Hospital Upgrade']),
        Indicator::factory()->create(['name' => 'Immunisation coverage', 'is_active' => true]),
    ]);

    actingOnTenant($this->works);

    $html = activityFormHtml($this->officer, $this->plan);

    expect(workplanSelectOptions($html, 'projectId'))->not->toHaveKey((string) $foreignProject->id)
        ->and(workplanSelectOptions($html, 'indicatorId'))->not->toHaveKey((string) $foreignIndicator->id);

    // Rule::exists() would confirm these ids back to the form that posted
    // them — it runs on the query builder and never sees the TenantScope.
    // BelongsToCurrentTenant asks through the model instead.
    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Borrowed from another ministry')
        ->set('projectId', (string) $foreignProject->id)
        ->set('indicatorId', (string) $foreignIndicator->id)
        ->set('plannedStart', '2026-02-01')
        ->set('plannedEnd', '2026-05-31')
        ->set('budgetAmount', '100000.00')
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors(['projectId', 'indicatorId']);

    expect(WorkplanActivity::query()->count())->toBe(0);
});

it('refuses a dependency on an activity of a different plan', function () {
    $otherPlan = Workplan::factory()->forYear(2025)->create();
    $foreignRow = WorkplanActivity::factory()->forWorkplan($otherPlan)->create();

    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->call('startAdding')
        ->set('title', 'Waiting on last year')
        ->set('dependsOnId', (string) $foreignRow->id)
        ->set('plannedStart', '2026-02-01')
        ->set('plannedEnd', '2026-05-31')
        ->set('budgetAmount', '100000.00')
        ->set('weight', '1')
        ->call('saveActivity')
        ->assertHasErrors('dependsOnId');
});

/* -------------------------------------------------------------------------- */
/* The create form */
/* -------------------------------------------------------------------------- */

it('opens a plan from the create form and lands on its builder', function () {
    Livewire::actingAs($this->officer)
        ->test(WorkplanCreate::class)
        ->set('title', 'Annual Work Plan & Budget 2027')
        ->set('year', '2027')
        ->set('ownerId', (string) $this->officer->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $plan = Workplan::query()->where('year', 2027)->sole();

    expect($plan->period_start->toDateString())->toBe('2027-01-01')
        ->and($plan->period_end->toDateString())->toBe('2027-12-31')
        ->and($plan->owner_id)->toBe($this->officer->id)
        ->and($plan->created_by_id)->toBe($this->officer->id);
});

it('derives a financial year’s period from the basis the officer picks', function () {
    $component = Livewire::actingAs($this->officer)
        ->test(WorkplanCreate::class)
        ->set('year', '2027')
        ->set('yearBasis', 'financial');

    expect($component->get('periodStart'))->toBe('2027-04-01')
        ->and($component->get('periodEnd'))->toBe('2028-03-31');
});

it('refuses a second plan for a year this entity already plans for', function () {
    Livewire::actingAs($this->officer)
        ->test(WorkplanCreate::class)
        ->set('title', 'A second 2026 plan')
        ->set('year', '2026')
        ->set('ownerId', (string) $this->officer->id)
        ->call('save')
        ->assertHasErrors('year');

    expect(Workplan::query()->where('year', 2026)->count())->toBe(1);
});

it('refuses an owner who cannot open this workspace', function () {
    $stranger = User::factory()->create();

    Livewire::actingAs($this->officer)
        ->test(WorkplanCreate::class)
        ->set('title', 'Owned by an outsider')
        ->set('year', '2028')
        ->set('ownerId', (string) $stranger->id)
        ->call('save')
        ->assertHasErrors('ownerId');
});
