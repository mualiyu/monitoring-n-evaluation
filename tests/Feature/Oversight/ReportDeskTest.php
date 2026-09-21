<?php

/**
 * The cross-MDA reports desk (progress-reporting.md §6).
 *
 * Three things have to hold at once: the desk really does span every entity,
 * it shows only what an entity has actually handed over, and no workspace user
 * can reach any of it however they arrive. The last one is checked over real
 * HTTP, because the role gate is middleware and a Livewire-only test skips it
 * entirely.
 */

use App\Actions\Oversight\ListReportsAcrossTenants;
use App\Enums\ProgressReportStatus;
use App\Enums\Role;
use App\Livewire\Oversight\Reporting\ReportDesk;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Run the export and return the bytes it streams. The callback writes to
 * php://output, so an output buffer is what captures the real file.
 */
function oversightExportedCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->lastMonth = ReportingPeriod::factory()->monthly(now()->subMonth()->year, now()->subMonth()->month)->create();

    $this->current->runAs($this->works, function () {
        $author = memberOf(User::factory()->create(['name' => 'Bello Adeyemi']), $this->works, Role::MeOfficer);

        $roads = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'reference' => 'WKS/2026/001',
        ]);

        ProgressReport::factory()
            ->forProject($roads)
            ->forPeriod($this->period)
            ->by($author)
            ->submitted($author)
            ->create(['physical_progress_claimed' => '45.00', 'period_expenditure' => '12000000.00']);

        // Not handed over: a draft is unfinished typing, and a returned return
        // is back with its author. Neither belongs on a state desk.
        ProgressReport::factory()->forProject($roads)->forPeriod($this->lastMonth)->by($author)->draft()->create();
        ProgressReport::factory()->forProject($roads)->forPeriod($this->lastMonth)->by($author)->returned()->create();
    });

    $this->current->runAs($this->health, function () {
        $author = memberOf(User::factory()->create(['name' => 'Ngozi Okafor']), $this->health, Role::MeOfficer);

        $clinic = Project::factory()->ongoing()->create([
            'title' => 'Cottage Hospital Rewiring',
            'reference' => 'HLT/2026/007',
        ]);

        ProgressReport::factory()
            ->forProject($clinic)
            ->forPeriod($this->period)
            ->by($author)
            ->approved()
            ->create(['submitted_late' => true]);
    });

    // The oversight surface binds no tenant — every assertion below runs in
    // the context the real requests run in.
    $this->current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);

    foreach ([$this->stateAdmin, $this->execViewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window
    }

    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->current->forget();
});

/* -------------------------------------------------------------------------- */
/* The route reaches the screen, and the screen spans the state */
/* -------------------------------------------------------------------------- */

it('dispatches the oversight /reports to the state desk and lists every entity', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/reports'))
        ->assertOk()
        ->assertSeeLivewire(ReportDesk::class)
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Cottage Hospital Rewiring');
});

it('shows only what an entity has handed over — never a draft or a returned return', function () {
    $desk = Livewire::actingAs($this->stateAdmin)->test(ReportDesk::class);

    expect($desk->instance()->reports->total())->toBe(2)
        ->and($desk->instance()->reports->pluck('status')->map(fn ($status) => $status->value)->sort()->values()->all())
        ->toBe(['approved', 'submitted']);
});

it('summarises the list it is showing, entities included', function () {
    $stats = Livewire::actingAs($this->stateAdmin)->test(ReportDesk::class)->instance()->stats;

    expect($stats)->toBe(['total' => 2, 'late' => 1, 'on_time' => 1, 'entities' => 2]);
});

it('lets a read-only oversight role see the desk', function () {
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/reports'))
        ->assertOk()
        ->assertSee('Cottage Hospital Rewiring');
});

/* -------------------------------------------------------------------------- */
/* Filters */
/* -------------------------------------------------------------------------- */

it('narrows to one entity', function () {
    $desk = Livewire::actingAs($this->stateAdmin)
        ->test(ReportDesk::class)
        ->set('tenantId', (string) $this->health->id);

    expect($desk->instance()->reports->pluck('tenant_id')->unique()->values()->all())->toBe([$this->health->id]);

    $desk->assertSee('Cottage Hospital Rewiring')->assertDontSee('Township Road Rehabilitation');
});

it('narrows to one window, one chain status and one project', function () {
    $desk = Livewire::actingAs($this->stateAdmin)->test(ReportDesk::class);

    $desk->set('periodId', (string) $this->lastMonth->id);
    expect($desk->instance()->reports->total())->toBe(0);

    $desk->set('periodId', (string) $this->period->id)->set('status', ProgressReportStatus::Approved->value);
    expect($desk->instance()->reports->total())->toBe(1);

    $desk->set('status', '')->set('search', 'HLT/2026/007');
    expect($desk->instance()->reports->total())->toBe(1);
});

it('narrows to what was filed late, and to what was not', function () {
    $desk = Livewire::actingAs($this->stateAdmin)->test(ReportDesk::class);

    $desk->set('lateness', 'late');
    expect($desk->instance()->reports->pluck('tenant_id')->all())->toBe([$this->health->id]);

    $desk->set('lateness', 'on_time');
    expect($desk->instance()->reports->pluck('tenant_id')->all())->toBe([$this->works->id]);
});

it('refuses to widen the universe through the status filter', function () {
    // A draft is not reachable by asking for one: the filter narrows the
    // visible set, it never reaches past it.
    $reports = (new ListReportsAcrossTenants)($this->stateAdmin, ['status' => ProgressReportStatus::Draft]);

    expect($reports->total())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Export */
/* -------------------------------------------------------------------------- */

it('exports the list it is showing, across entities, under the filters in force', function () {
    $csv = oversightExportedCsv(
        Livewire::actingAs($this->stateAdmin)->test(ReportDesk::class)->instance()->export()
    );

    expect($csv)->toContain('Ministry of Works')
        ->and($csv)->toContain('Ministry of Health')
        ->and($csv)->toContain('WKS/2026/001')
        ->and($csv)->toContain('HLT/2026/007')
        // A draft never reaches a state export either.
        ->and(substr_count($csv, 'WKS/2026/001'))->toBe(1);

    $filteredCsv = oversightExportedCsv(
        Livewire::actingAs($this->stateAdmin)
            ->test(ReportDesk::class)
            ->set('tenantId', (string) $this->works->id)
            ->instance()
            ->export()
    );

    expect($filteredCsv)->toContain('WKS/2026/001')
        ->and($filteredCsv)->not->toContain('HLT/2026/007');
});

/* -------------------------------------------------------------------------- */
/* Authority */
/* -------------------------------------------------------------------------- */

it('refuses the desk to a workspace user, however senior in their own ministry', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/reports'))
        ->assertForbidden();
});

it('refuses the desk component itself to a workspace user', function () {
    // Belt and braces: the component authorizes in mount() as well, so a
    // direct Livewire update cannot reach it if the route group ever changes.
    Livewire::actingAs(User::query()->whereKey($this->mdaAdmin->id)->firstOrFail())
        ->test(ReportDesk::class)
        ->assertForbidden();
});

it('refuses the cross-tenant read itself to anyone without oversight authority', function () {
    expect(fn () => (new ListReportsAcrossTenants)($this->mdaAdmin))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new ListReportsAcrossTenants)->summarise($this->mdaAdmin))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new ListReportsAcrossTenants)->chunk($this->mdaAdmin, [], fn () => null))
        ->toThrow(AuthorizationException::class);
});

it('offers no decision on a state desk — the chain belongs to the entity', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/reports'))
        ->assertOk()
        ->assertDontSee('Mark as reviewed')
        ->assertDontSee('Approve this return');
});
