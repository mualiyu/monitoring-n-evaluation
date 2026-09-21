<?php

use App\Actions\Analytics\BuildWorkspaceSummary;
use App\Actions\Projects\AssignProjectMember;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Oversight\Dashboard\PortfolioChart;
use App\Livewire\Oversight\Dashboard\StateKpis;
use App\Livewire\Tenant\Dashboard\DeliveryChart;
use App\Livewire\Tenant\Dashboard\WorkspaceKpis;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

/**
 * The dashboard KPIs used to be literals labelled "sample figure". These tests
 * are what stops them regressing to that: every tile is asserted against
 * records this workspace actually holds, and against a second workspace's
 * records that must never reach it.
 */
beforeEach(function () {
    seedPermissions();

    $this->tenant = Tenant::factory()->create(['slug' => 'works']);
    $this->other = Tenant::factory()->create(['slug' => 'health']);
});

it('counts only the active delivery window, not drafts or finished work', function () {
    actingOnTenant($this->tenant);

    Project::factory()->for($this->tenant)->count(3)->create(['status' => ProjectStatus::InProgress]);
    Project::factory()->for($this->tenant)->create(['status' => ProjectStatus::Awarded]);
    Project::factory()->for($this->tenant)->create(['status' => ProjectStatus::Draft]);
    Project::factory()->for($this->tenant)->create(['status' => ProjectStatus::Closed]);
    // A suspended project is exactly the one a director must not see counted
    // as active work.
    Project::factory()->for($this->tenant)->create(['status' => ProjectStatus::Suspended]);

    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);
    $summary = (new BuildWorkspaceSummary)($admin);

    expect($summary['active_projects'])->toBe(4)
        ->and($summary['project_count'])->toBe(7);
});

it('sums contract value and expenditure as money, never as floats', function () {
    actingOnTenant($this->tenant);

    Project::factory()->for($this->tenant)->create([
        'status' => ProjectStatus::InProgress,
    ])->forceFill([
        'contract_value_total' => Money::fromDecimalString('1250000000.50'),
        'expenditure_to_date' => Money::fromDecimalString('400000000.25'),
    ])->save();

    Project::factory()->for($this->tenant)->create([
        'status' => ProjectStatus::InProgress,
    ])->forceFill([
        'contract_value_total' => Money::fromDecimalString('750000000.50'),
        'expenditure_to_date' => Money::fromDecimalString('100000000.75'),
    ])->save();

    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);
    $summary = (new BuildWorkspaceSummary)($admin);

    // Billions in kobo overflow a float's exact range and a SUM() that came
    // back in scientific notation used to be unparseable — both are why this
    // asserts on the exact string.
    expect($summary['contract_value'])->toBeInstanceOf(Money::class)
        ->and($summary['contract_value']->toDecimalString())->toBe('2000000001.00')
        ->and($summary['expenditure']->toDecimalString())->toBe('500000001.00');
});

it('never counts another MDA in a workspace summary', function () {
    actingOnTenant($this->other);
    Project::factory()->for($this->other)->count(5)->create(['status' => ProjectStatus::InProgress]);

    actingOnTenant($this->tenant);
    Project::factory()->for($this->tenant)->count(2)->create(['status' => ProjectStatus::InProgress]);

    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);

    expect((new BuildWorkspaceSummary)($admin)['project_count'])->toBe(2);
});

it('narrows a consultant to the projects they are assigned to', function () {
    actingOnTenant($this->tenant);

    $assigned = Project::factory()->for($this->tenant)->create(['status' => ProjectStatus::InProgress]);
    Project::factory()->for($this->tenant)->count(4)->create(['status' => ProjectStatus::InProgress]);

    // Assigned BY an authorised officer, never by the consultant themselves —
    // AssignProjectMember authorises the actor, and a contractor putting
    // themselves on a project is exactly what it refuses.
    $consultant = actingAsMember(Role::Consultant, $this->tenant);
    $admin = memberOf(User::factory()->create(), $this->tenant, Role::MdaAdmin);

    (new AssignProjectMember)(
        $assigned,
        $consultant,
        ProjectRole::Consultant,
        User::query()->whereKey($admin->getKey())->firstOrFail(),
    );

    expect((new BuildWorkspaceSummary)($consultant)['project_count'])->toBe(1);
});

it('puts the live KPI row on the workspace dashboard, with no placeholder figures left', function () {
    actingOnTenant($this->tenant);
    Project::factory()->for($this->tenant)->count(2)->create(['status' => ProjectStatus::InProgress]);

    actingAsMember(Role::MdaAdmin, $this->tenant);

    // The tile row is LAZY, so the first paint is its skeleton — asserting on
    // the figures here would assert on a placeholder. What this test pins
    // down is that the dashboard mounts the live component and that the
    // "sample figure" literals are gone for good; the numbers themselves are
    // asserted against the Action above and rendered below.
    $this->get(tenantUrl($this->tenant, '/'))
        ->assertOk()
        ->assertSeeLivewire('tenant.dashboard.workspace-kpis')
        ->assertDontSee('sample figure')
        ->assertDontSee('₦8.6bn');
});

it('renders the workspace KPI tiles with this workspace’s own figures', function () {
    // Each workspace's rows are created under its OWN bound context: the
    // fail-closed trait refuses a create whose tenant_id is not the bound one,
    // which is the guard, not an inconvenience.
    actingOnTenant($this->other);
    Project::factory()->for($this->other)->count(7)->create(['status' => ProjectStatus::InProgress]);

    actingOnTenant($this->tenant);
    Project::factory()->for($this->tenant)->count(2)->create(['status' => ProjectStatus::InProgress]);

    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);

    Livewire::actingAs($admin)
        ->test(WorkspaceKpis::class)
        ->assertOk()
        ->assertSee('Active projects')
        // 2 here, never the other workspace's 7.
        ->assertSee('2 in the register')
        ->assertDontSee('9 in the register');
});

it('puts the live state KPI row on the oversight dashboard', function () {
    $admin = userWithRole(Role::StateAdmin);
    $admin->forceFill(['two_factor_exempted_at' => now()])->save();

    $this->actingAs($admin)
        ->get(oversightUrl('/'))
        ->assertOk()
        ->assertSeeLivewire('oversight.dashboard.state-kpis');
});

it('renders the state KPI tiles across every MDA', function () {
    actingOnTenant($this->tenant);
    Project::factory()->for($this->tenant)->count(2)->create(['status' => ProjectStatus::InProgress]);
    actingOnTenant($this->other);
    Project::factory()->for($this->other)->count(3)->create(['status' => ProjectStatus::InProgress]);
    actingWithoutTenant();

    $admin = userWithRole(Role::StateAdmin);

    Livewire::actingAs($admin)
        ->test(StateKpis::class)
        ->assertOk()
        ->assertSee('Entities reporting')
        // Both workspaces are reporting, and the portfolio is the SUM — this
        // is the cross-tenant read the oversight Actions exist for.
        ->assertSee('2 / 2')
        ->assertSee('5');
});

/* -------------------------------------------------------------------------- */
/* Charts */
/* -------------------------------------------------------------------------- */

it('plots the register by lifecycle status, in lifecycle order and never by size', function () {
    actingOnTenant($this->tenant);

    Project::factory()->for($this->tenant)->count(4)->create(['status' => ProjectStatus::InProgress]);
    Project::factory()->for($this->tenant)->count(2)->create(['status' => ProjectStatus::Awarded]);
    Project::factory()->for($this->tenant)->create(['status' => ProjectStatus::Completed]);

    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);

    $rows = Livewire::actingAs($admin)->test(DeliveryChart::class)->instance()->rows();

    // Awarded before In progress before Completed — the pipeline reads left to
    // right, and sorting by count would reshuffle it every time a project moved.
    expect(array_column($rows, 'value'))->toBe([2, 4, 1])
        ->and($rows[0]['label'])->toBe(ProjectStatus::Awarded->label())
        // A status nothing has reached is not an empty bar on the axis.
        ->and(collect($rows)->pluck('label'))->not->toContain(ProjectStatus::Draft->label());
});

it('renders every chart with a table of the same figures, so the numbers are never gated behind JavaScript', function () {
    actingOnTenant($this->tenant);
    Project::factory()->for($this->tenant)->count(3)->create(['status' => ProjectStatus::InProgress]);

    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);

    // The <details> table is the accessibility path, the no-JavaScript path,
    // and the relief channel that makes the neutral chart steps legal at
    // 2.7:1 on the light surface. It is not optional decoration.
    Livewire::actingAs($admin)
        ->test(DeliveryChart::class)
        ->assertOk()
        ->assertSee('Show the figures as a table')
        ->assertSee(ProjectStatus::InProgress->label())
        ->assertSeeHtml('<caption class="sr-only">');
});

it('folds a long tail of entities into one honest row rather than dropping it', function () {
    // Ten entities, so the eight-bar cap bites. The total must still add up:
    // a chart that quietly loses two MDAs is worse than a crowded one.
    $tenants = collect(range(1, 10))->map(fn (int $i) => Tenant::factory()->create(['slug' => 'mda'.$i]));

    foreach ($tenants as $index => $tenant) {
        actingOnTenant($tenant);
        Project::factory()->for($tenant)->count(10 - $index)->create(['status' => ProjectStatus::InProgress]);
    }

    actingWithoutTenant();
    $stateAdmin = userWithRole(Role::StateAdmin);

    $rows = Livewire::actingAs($stateAdmin)->test(PortfolioChart::class)->instance()->rows();

    expect($rows)->toHaveCount(9)
        ->and($rows[8]['label'])->toBe('2 other entities')
        // 10+9+…+1 = 55 across ten entities; the last two hold 2 + 1.
        ->and(array_sum(array_column($rows, 'value')))->toBe(55)
        ->and($rows[8]['value'])->toBe(3);
});

it('gives a user without portfolio authority an empty chart rather than an exception', function () {
    actingOnTenant($this->tenant);
    Project::factory()->for($this->tenant)->count(3)->create(['status' => ProjectStatus::InProgress]);

    // An MDA admin holds projects.view in their WORKSPACE team and nothing at
    // all globally, so BuildPortfolioSummary refuses them. The chart is
    // dashboard chrome: it must answer with nothing rather than raise, and the
    // screens behind it are what actually refuse. The three projects above are
    // the proof that "empty" is a refusal and not an empty database.
    $mdaAdmin = memberOf(User::factory()->create(), $this->tenant, Role::MdaAdmin);
    actingWithoutTenant();

    expect($mdaAdmin->holdsGlobalPermission('oversight.portfolio.view'))->toBeFalse()
        ->and(Livewire::actingAs($mdaAdmin)->test(PortfolioChart::class)->instance()->rows())->toBe([]);
});
