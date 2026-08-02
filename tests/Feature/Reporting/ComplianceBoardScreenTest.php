<?php

/**
 * The oversight compliance board (progress-reporting.md §3.1).
 *
 * The Action test already proves the aggregate counts correctly. What is
 * unproven until here is the SURFACE: that the board renders every entity for
 * the chosen window, that the ranking a user asks for is the ranking they get,
 * that the window toggle works — and that a workspace role cannot reach any of
 * it, however they arrive.
 *
 * Every route case is a real request to the oversight host, because the role
 * gate lives in middleware and a Livewire-only test would skip it entirely.
 */

use App\Enums\Role;
use App\Livewire\Oversight\Reporting\ComplianceBoard;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->lastMonth = ReportingPeriod::factory()->monthly(now()->subMonth()->year, now()->subMonth()->month)->create();

    // Works filed three of four, one of them late. Health filed one of three
    // and missed one outright — two visibly different records on one board.
    $this->current->runAs($this->works, function () {
        $project = Project::factory()->ongoing()->create();

        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled()->count(2)->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled(late: true)->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();

        // Last month, so the window toggle has something to toggle to.
        ReportObligation::factory()->forProject($project)->forPeriod($this->lastMonth)->fulfilled()->create();
    });

    $this->current->runAs($this->health, function () {
        $project = Project::factory()->ongoing()->create();

        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->missed()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();
    });

    // The oversight surface binds no tenant — every assertion runs in the
    // context the real requests run in.
    $this->current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);

    foreach ([$this->stateAdmin, $this->execViewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window
    }

    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->current->forget();
});

it('renders the board with every entity and its compliance record', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance'))
        ->assertOk()
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health')
        ->assertSee($this->period->label);
});

it('shows the state-wide totals for the window on screen', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(ComplianceBoard::class)
        ->assertOk();

    expect($component->instance()->board['totals'])->toBe([
        'expected' => 7, 'submitted' => 4, 'on_time' => 3, 'missed' => 1, 'waived' => 0,
    ]);
});

it('ranks the better-performing entity first, because the ranking is the sanction', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(ComplianceBoard::class)
        ->assertOk();

    // Works: 2 of 4 on time (50%). Health: 1 of 3 (33%).
    expect(collect($component->instance()->board['tenants'])->pluck('slug')->all())
        ->toBe(['works', 'health']);
});

it('re-ranks on demand, and flips the direction on a second click', function () {
    $component = Livewire::actingAs($this->stateAdmin)->test(ComplianceBoard::class);

    $component->call('sortBy', 'missed');

    expect($component->instance()->board['tenants'][0]['slug'])->toBe('health')  // 1 missed
        ->and($component->get('direction'))->toBe('desc');

    $component->call('sortBy', 'missed');

    expect($component->get('direction'))->toBe('asc')
        ->and($component->instance()->board['tenants'][0]['slug'])->toBe('works');   // 0 missed
});

it('sorts entity names A→Z rather than by score', function () {
    $component = Livewire::actingAs($this->stateAdmin)->test(ComplianceBoard::class);

    $component->call('sortBy', 'name');

    expect($component->get('direction'))->toBe('asc')
        ->and($component->instance()->board['tenants'][0]['name'])->toBe('Ministry of Health');
});

it('refuses to sort by a column that is not whitelisted', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ComplianceBoard::class)
        ->call('sortBy', 'name; drop table report_obligations')
        ->assertSet('sort', 'on_time_rate');
});

it('toggles to the previous window and reports it instead', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(ComplianceBoard::class)
        ->assertOk();

    expect($component->instance()->period->id)->toBe($this->period->id)
        ->and($component->instance()->previousPeriod->id)->toBe($this->lastMonth->id);

    $component->call('showPeriod', $this->lastMonth->id);

    // Only Works owed anything last month.
    expect($component->instance()->board['period']['code'])->toBe($this->lastMonth->code)
        ->and($component->instance()->board['totals']['expected'])->toBe(1)
        ->and(collect($component->instance()->board['tenants'])->pluck('slug')->all())->toBe(['works']);
});

it('persists the chosen window in the query string', function () {
    Livewire::withQueryParams(['period' => (string) $this->lastMonth->id])
        ->actingAs($this->stateAdmin)
        ->test(ComplianceBoard::class)
        ->assertOk()
        ->assertSet('periodId', (string) $this->lastMonth->id)
        ->assertSee($this->lastMonth->label);
});

it('lets a read-only oversight role see the board', function () {
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/compliance'))
        ->assertOk()
        ->assertSee('Ministry of Works');
});

it('refuses the board to a workspace user, however senior in their own ministry', function () {
    // Through the route, because the role gate is middleware: an MDA admin
    // holds no role at all in the GLOBAL team the oversight surface checks.
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/compliance'))
        ->assertForbidden();
});

it('refuses the board component itself to a workspace user', function () {
    // Belt and braces: the component authorizes in mount() as well, so a
    // direct Livewire update cannot reach it if the route group ever changes.
    Livewire::actingAs($this->mdaAdmin->fresh())
        ->test(ComplianceBoard::class)
        ->assertForbidden();
});

it('links the compliance board from the oversight sidebar, with a live queue badge', function () {
    // Two returns sitting unapproved across two different MDAs.
    $this->current->runAs($this->works, function () {
        ProgressReport::factory()
            ->forProject(Project::factory()->ongoing()->create())
            ->submitted()
            ->create();
    });

    $this->current->runAs($this->health, function () {
        ProgressReport::factory()
            ->forProject(Project::factory()->ongoing()->create())
            ->reviewed()
            ->create();
    });

    $this->current->forget();

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/'))
        ->assertOk()
        ->assertSee(oversightUrl('/compliance'))
        ->assertSee('Reporting compliance')
        ->assertSee('Reports awaiting review');
});

it('renders an honest empty board when no window has opened yet', function () {
    ReportObligation::query()->withoutTenancy()->forceDelete();
    ReportingPeriod::query()->delete();

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance'))
        ->assertOk()
        ->assertSee('No reporting window has opened yet');
});
