<?php

/**
 * The work-plan screens (PROJECT_PLAN §8): the register, the create form, the
 * builder, the Gantt and the state-wide board.
 *
 * tests/Unit/WorkplanStatusTransitionTest and tests/Unit/WorkplanProgressTest
 * already prove the chain table and the roll-up arithmetic. What is unproven
 * until here is the SURFACE: that each screen renders a real record over real
 * HTTP for the role that owns it, that the roll-up the table prints is the one
 * WorkplanProgress computes, and that a role without the permission is stopped
 * — at the route AND at the Livewire endpoint behind it.
 *
 * Every screen is asserted BOTH ways. A suite of denials proves only that
 * nothing works.
 */

use App\Actions\Oversight\ListWorkplansAcrossTenants;
use App\Enums\Role;
use App\Enums\WorkplanStatus;
use App\Livewire\Oversight\Workplans\WorkplanBoard;
use App\Livewire\Tenant\Workplans\WorkplanBuilder;
use App\Livewire\Tenant\Workplans\WorkplanCreate;
use App\Livewire\Tenant\Workplans\WorkplanGantt;
use App\Livewire\Tenant\Workplans\WorkplanIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    // Pinned: the create form defaults its year to "now", and a suite that
    // runs across a New Year boundary would otherwise open a plan for a
    // different year than the fixtures.
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 9));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    // ResolveTenant sets this on a real request; a Livewire-only test never
    // runs that middleware, so route('tenant.*') would have no subdomain.
    URL::defaults(['tenant' => $this->works->slug]);

    $this->admin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(['name' => 'Bola Adeyemi']), $this->works, Role::Consultant);

    $this->plan = Workplan::factory()
        ->forYear(2026)
        ->ownedBy($this->officer)
        ->by($this->officer)
        ->create(['title' => 'Annual Work Plan & Budget 2026']);

    WorkplanActivity::factory()
        ->forWorkplan($this->plan, 1)
        ->withIndicator()
        ->create(['title' => 'Conduct implementation monitoring field visits', 'budget_amount' => '4000000.00']);

    WorkplanActivity::factory()
        ->forWorkplan($this->plan, 2)
        ->withoutIndicator()
        ->create(['title' => 'Hold quarterly M&E review meetings', 'budget_amount' => '1000000.00']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* Authorized renders — one real HTTP request per screen */
/* -------------------------------------------------------------------------- */

it('renders the work-plan register with the entity’s plans over real HTTP', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans'))
        ->assertOk()
        ->assertSee('Annual Work Plan & Budget 2026')
        ->assertSee('Chidi Okafor')
        ->assertSee(WorkplanStatus::Draft->label())
        // The manual's rule, counted on screen: one of the two lines carries
        // no output indicator.
        ->assertSee('1 without an output indicator');
});

it('renders the create form with this workspace’s members in the owner picker', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans/create'))
        ->assertOk()
        ->assertSee('Amina Bello')
        ->assertSee('Chidi Okafor');
});

it('renders the builder for one plan, its activities and its budget', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans/'.$this->plan->ulid))
        ->assertOk()
        ->assertSee('Annual Work Plan & Budget 2026')
        ->assertSee('Conduct implementation monitoring field visits')
        ->assertSee('Hold quarterly M&E review meetings');
});

it('renders the Gantt for one plan with every activity described in words', function () {
    $response = $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans/'.$this->plan->ulid.'/gantt'))
        ->assertOk()
        ->assertSee('Conduct implementation monitoring field visits');

    // Status is never colour or position alone: each bar carries its own
    // dates, status and percentage as text.
    expect($response->getContent())->toContain('0% complete');
});

it('renders the state-wide board with every entity’s plan', function () {
    $this->current->runAs($this->health, fn () => Workplan::factory()
        ->forYear(2026)
        ->create(['title' => 'Health AWPB 2026']));

    actingWithoutTenant();

    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    $this->actingAs($stateAdmin)
        ->get(oversightUrl('/workplans'))
        ->assertOk()
        ->assertSee('Annual Work Plan & Budget 2026')
        ->assertSee('Health AWPB 2026')
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health');
});

/* -------------------------------------------------------------------------- */
/* The register's own reads */
/* -------------------------------------------------------------------------- */

it('prints the roll-up WorkplanProgress computes, not a second formula', function () {
    $component = Livewire::actingAs($this->officer)->test(WorkplanIndex::class)->assertOk();

    expect($component->instance()->stats())->toMatchArray([
        'count' => 1,
        'live' => 0,
        'awaiting' => 0,
        'unlinked' => 1,
        'budget' => '5000000.00',
    ]);
});

it('filters the register down to the plans breaking the output-indicator rule', function () {
    $clean = Workplan::factory()->forYear(2025)->create(['title' => 'Fully linked plan 2025']);
    WorkplanActivity::factory()->forWorkplan($clean)->withIndicator()->create();

    Livewire::actingAs($this->officer)
        ->test(WorkplanIndex::class)
        ->assertSee('Fully linked plan 2025')
        ->set('unlinked', true)
        ->assertSee('Annual Work Plan & Budget 2026')
        ->assertDontSee('Fully linked plan 2025');
});

it('offers only the years this workspace actually has plans for', function () {
    Workplan::factory()->forYear(2024)->create();

    $component = Livewire::actingAs($this->officer)->test(WorkplanIndex::class);

    expect(array_keys($component->instance()->yearOptions()))->toBe([2026, 2024]);
});

it('refuses to sort the register by a column that is not whitelisted', function () {
    Livewire::actingAs($this->officer)
        ->test(WorkplanIndex::class)
        ->call('sortBy', 'title; drop table workplans')
        ->assertSet('sort', 'year');
});

it('shows the register’s empty state with a call to action when nothing is planned', function () {
    Workplan::query()->delete();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans'))
        ->assertOk()
        ->assertSee('No work plan opened yet')
        ->assertSee('Open a work plan');
});

/* -------------------------------------------------------------------------- */
/* Authorization matrix — one case per role per surface */
/* -------------------------------------------------------------------------- */

it('lets an M&E officer open a plan, and an MDA admin too', function () {
    foreach ([$this->officer, $this->admin] as $user) {
        $this->actingAs($user)
            ->get(tenantUrl($this->works, '/workplans/create'))
            ->assertOk();
    }
});

it('refuses the create form to a consultant, who may read plans but not write them', function () {
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/workplans/create'))
        ->assertForbidden();

    // …and at the Livewire endpoint, which route middleware does not protect.
    Livewire::actingAs($this->consultant)->test(WorkplanCreate::class)->assertForbidden();
});

it('narrows a consultant to the plans they own a line in', function () {
    $mine = Workplan::factory()->forYear(2025)->create(['title' => 'Plan with my activity']);
    WorkplanActivity::factory()->forWorkplan($mine)->ownedBy($this->consultant)->create();

    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/workplans'))
        ->assertOk()
        ->assertSee('Plan with my activity')
        // The entity's whole programme is not a field role's business.
        ->assertDontSee('Annual Work Plan & Budget 2026');
});

it('refuses a consultant the plan they own no line in, even by its ULID', function () {
    // The ULID resolves — it is this workspace's plan — and the narrowing in
    // Workplan::scopeVisibleTo is what stops them, so this is a 403 rather
    // than a 404. A foreign MDA's ULID is the 404 case, proven in the
    // isolation suite.
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/workplans/'.$this->plan->ulid))
        ->assertForbidden();

    Livewire::actingAs($this->consultant)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->assertForbidden();
});

it('refuses the whole register to somebody with no workspace membership', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(tenantUrl($this->works, '/workplans'))
        ->assertForbidden();
});

it('refuses the state board to an MDA admin, however they arrive', function () {
    actingWithoutTenant();

    $this->actingAs($this->admin)
        ->get(oversightUrl('/workplans'))
        ->assertForbidden();

    Livewire::actingAs($this->admin)->test(WorkplanBoard::class)->assertForbidden();
});

it('opens the state board to every oversight role that holds workplans.view', function () {
    actingWithoutTenant();

    foreach ([Role::ExecutiveViewer, Role::DataQualityReviewer] as $role) {
        $user = userWithRole($role);
        $user->forceFill(['two_factor_required_at' => now()])->save();

        $this->actingAs($user)
            ->get(oversightUrl('/workplans'))
            ->assertOk()
            ->assertSee('Annual Work Plan & Budget 2026');
    }
});

it('refuses the cross-MDA read itself to anyone without workplans.view in the global team', function () {
    actingWithoutTenant();

    // The Action is the authority, not the route: an MDA admin holds
    // workplans.view inside their own workspace and holds it globally nowhere.
    expect(fn () => (new ListWorkplansAcrossTenants)($this->admin))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new ListWorkplansAcrossTenants)->years($this->admin))
        ->toThrow(AuthorizationException::class);
});

it('refuses the builder and the Gantt at the Livewire endpoint for a role without workplans.view', function () {
    $outsider = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    actingOnTenant($this->works);

    Livewire::actingAs($outsider)
        ->test(WorkplanBuilder::class, ['workplan' => $this->plan])
        ->assertForbidden();

    Livewire::actingAs($outsider)
        ->test(WorkplanGantt::class, ['workplan' => $this->plan])
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* The Gantt's geometry */
/* -------------------------------------------------------------------------- */

it('draws one column per month of the plan period and positions each bar in it', function () {
    $plan = Workplan::factory()->forYear(2026)->create(['title' => 'Positioned plan']);

    WorkplanActivity::factory()
        ->forWorkplan($plan)
        ->scheduled(CarbonImmutable::create(2026, 3, 1), CarbonImmutable::create(2026, 5, 31))
        ->create(['title' => 'March to May activity']);

    $component = Livewire::actingAs($this->officer)
        ->test(WorkplanGantt::class, ['workplan' => $plan])
        ->assertOk();

    $rows = $component->instance()->rows();

    expect($component->instance()->columns())->toHaveCount(12)
        // CSS grid is 1-based: March is column 3, and the bar spans 3 months.
        ->and($rows[0]['start'])->toBe(3)
        ->and($rows[0]['span'])->toBe(3)
        // 15 June 2026 is the pinned clock — the sixth column.
        ->and($component->instance()->todayColumn())->toBe(6);
});

it('switches the Gantt to weekly columns on demand', function () {
    $component = Livewire::actingAs($this->officer)
        ->test(WorkplanGantt::class, ['workplan' => $this->plan])
        ->call('setScale', 'week');

    expect($component->get('scale'))->toBe('week')
        ->and(count($component->instance()->columns()))->toBeGreaterThan(40);
});

it('ignores a Gantt scale nobody offered', function () {
    Livewire::actingAs($this->officer)
        ->test(WorkplanGantt::class, ['workplan' => $this->plan])
        ->call('setScale', 'decade')
        ->assertSet('scale', 'month');
});

it('shows the Gantt’s empty state rather than a blank grid', function () {
    $plan = Workplan::factory()->forYear(2025)->create(['title' => 'Nothing planned yet']);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/workplans/'.$plan->ulid.'/gantt'))
        ->assertOk()
        ->assertSee('Nothing to draw yet');
});
