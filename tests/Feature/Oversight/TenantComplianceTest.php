<?php

/**
 * The compliance drill-down (progress-reporting.md §6, `/compliance/{tenant}`).
 *
 * The board ranks entities; this screen has to explain a rank without ever
 * contradicting it. So the assertions below are mostly about AGREEMENT: the
 * same obligations, the same definitions (fulfilment at submission, waived
 * excluded from the base), the same numbers.
 *
 * The binding gets its own test. `{tenant:slug}` handed a tenant_id is a
 * shipped defect this project has already had once — every drill-down row
 * 404'd — and the only assertion that catches it is one that follows the link
 * the board actually renders.
 */

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Actions\Oversight\BuildTenantComplianceDetail;
use App\Enums\Role;
use App\Livewire\Oversight\Reporting\ComplianceBoard;
use App\Livewire\Oversight\Reporting\TenantCompliance;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->lastMonth = ReportingPeriod::factory()->monthly(now()->subMonth()->year, now()->subMonth()->month)->create();

    // Works: four owed this month — two on time, one late, one still open —
    // plus one filed last month. Enough for a rate that is not 0 or 100.
    $this->current->runAs($this->works, function () {
        $roads = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'reference' => 'WKS/2026/001',
        ]);

        $bridge = Project::factory()->ongoing()->create([
            'title' => 'Oke-Ado Bridge Repairs',
            'reference' => 'WKS/2026/002',
        ]);

        ReportObligation::factory()->forProject($roads)->forPeriod($this->period)->fulfilled()->create();
        ReportObligation::factory()->forProject($bridge)->forPeriod($this->period)->fulfilled()->create();
        ReportObligation::factory()->forProject($roads)->forPeriod($this->period)->fulfilled(late: true)->create();
        ReportObligation::factory()->forProject($bridge)->forPeriod($this->period)->create();

        ReportObligation::factory()->forProject($roads)->forPeriod($this->lastMonth)->missed()->create();
    });

    $this->current->runAs($this->health, function () {
        $clinic = Project::factory()->ongoing()->create([
            'title' => 'Cottage Hospital Rewiring',
            'reference' => 'HLT/2026/007',
        ]);

        ReportObligation::factory()->forProject($clinic)->forPeriod($this->period)->missed()->create();
    });

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
/* The binding, and the link that uses it */
/* -------------------------------------------------------------------------- */

it('dispatches /compliance/{slug} to the drill-down for that entity', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance/works'))
        ->assertOk()
        ->assertSeeLivewire(TenantCompliance::class)
        ->assertSee('Ministry of Works');
});

it('binds by slug, so an entity id in the URL is a 404 and never someone else’s record', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance/'.$this->works->id))
        ->assertNotFound();
});

it('links the board to the drill-down with the slug the binding expects', function () {
    // Follows the link the board actually renders. A board that rendered an id
    // here would pass every "the route exists" test and 404 every real click.
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance'))
        ->assertOk()
        ->assertSee(oversightUrl('/compliance/works'), escape: false)
        ->assertSee(oversightUrl('/compliance/health'), escape: false);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance/works'))
        ->assertOk();
});

/* -------------------------------------------------------------------------- */
/* The record it explains */
/* -------------------------------------------------------------------------- */

it('breaks one entity’s record down window by window', function () {
    $detail = Livewire::actingAs($this->stateAdmin)
        ->test(TenantCompliance::class, ['tenant' => $this->works])
        ->instance()
        ->detail;

    expect($detail['tenant']['slug'])->toBe('works')
        ->and($detail['totals']['expected'])->toBe(5)
        ->and($detail['totals']['submitted'])->toBe(3)
        ->and($detail['totals']['on_time'])->toBe(2)
        ->and($detail['totals']['late'])->toBe(1)
        ->and($detail['totals']['missed'])->toBe(1)
        ->and($detail['totals']['pending'])->toBe(1);

    // Newest window first.
    expect(array_column($detail['periods'], 'code'))
        ->toBe([$this->period->code, $this->lastMonth->code]);

    $thisMonth = $detail['periods'][0];

    expect($thisMonth['expected'])->toBe(4)
        ->and($thisMonth['submitted'])->toBe(3)
        ->and($thisMonth['on_time'])->toBe(2)
        ->and($thisMonth['on_time_rate'])->toBe(50.0);
});

it('agrees with the league table row that led to it', function () {
    $board = (new BuildComplianceLeagueTable)($this->stateAdmin, $this->period);
    $row = collect($board['tenants'])->firstWhere('slug', 'works');

    $detail = (new BuildTenantComplianceDetail)($this->stateAdmin, $this->works);
    $window = collect($detail['periods'])->firstWhere('period_id', $this->period->id);

    expect($window['expected'])->toBe($row['expected'])
        ->and($window['submitted'])->toBe($row['submitted'])
        ->and($window['on_time'])->toBe($row['on_time'])
        ->and($window['on_time_rate'])->toBe($row['on_time_rate']);
});

it('excludes a waived obligation from the base rather than scoring it as a failure', function () {
    $this->current->runAs($this->works, function () {
        $extra = Project::factory()->ongoing()->create();

        ReportObligation::factory()->forProject($extra)->forPeriod($this->period)->waived()->create();
    });

    $this->current->forget();

    $detail = (new BuildTenantComplianceDetail)($this->stateAdmin, $this->works);
    $window = collect($detail['periods'])->firstWhere('period_id', $this->period->id);

    // Five expected, one waived → the rate is still measured over four.
    expect($window['expected'])->toBe(5)
        ->and($window['waived'])->toBe(1)
        ->and($window['on_time_rate'])->toBe(50.0);
});

/* -------------------------------------------------------------------------- */
/* Per-window drill-down */
/* -------------------------------------------------------------------------- */

it('names the projects behind a window’s figures, and only that entity’s', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(TenantCompliance::class, ['tenant' => $this->works])
        ->assertOk();

    expect($component->instance()->period->id)->toBe($this->period->id)
        ->and($component->instance()->obligations->total())->toBe(4);

    $component->assertSee('Township Road Rehabilitation')
        ->assertSee('Oke-Ado Bridge Repairs')
        ->assertDontSee('Cottage Hospital Rewiring');
});

it('switches to an earlier window on demand', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(TenantCompliance::class, ['tenant' => $this->works])
        ->call('showPeriod', $this->lastMonth->id);

    expect($component->instance()->period->id)->toBe($this->lastMonth->id)
        ->and($component->instance()->obligations->total())->toBe(1);
});

it('persists the chosen window in the query string', function () {
    Livewire::withQueryParams(['period' => (string) $this->lastMonth->id])
        ->actingAs($this->stateAdmin)
        ->test(TenantCompliance::class, ['tenant' => $this->works])
        ->assertOk()
        ->assertSet('periodId', (string) $this->lastMonth->id);
});

it('never lets one entity’s drill-down reach another entity’s obligations', function () {
    $works = (new BuildTenantComplianceDetail)($this->stateAdmin, $this->works);
    $health = (new BuildTenantComplianceDetail)($this->stateAdmin, $this->health);

    expect($works['totals']['expected'])->toBe(5)
        ->and($health['totals']['expected'])->toBe(1);

    $healthObligations = (new BuildTenantComplianceDetail)
        ->obligations($this->stateAdmin, $this->health, $this->period);

    expect($healthObligations->pluck('tenant_id')->unique()->values()->all())->toBe([$this->health->id]);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance/health'))
        ->assertOk()
        ->assertSee('Cottage Hospital Rewiring')
        ->assertDontSee('Township Road Rehabilitation');
});

it('renders an honest empty record for an entity that has never owed anything', function () {
    $new = Tenant::factory()->create(['name' => 'Ministry of Education', 'slug' => 'education']);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance/'.$new->slug))
        ->assertOk()
        ->assertSee('Nothing has ever been owed here');
});

/* -------------------------------------------------------------------------- */
/* Authority */
/* -------------------------------------------------------------------------- */

it('lets a read-only oversight role see the record', function () {
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/compliance/works'))
        ->assertOk()
        ->assertSee('Ministry of Works');
});

it('refuses the drill-down to a workspace user, however senior in their own ministry', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/compliance/works'))
        ->assertForbidden();
});

it('refuses the drill-down component itself to a workspace user', function () {
    Livewire::actingAs(User::query()->whereKey($this->mdaAdmin->id)->firstOrFail())
        ->test(TenantCompliance::class, ['tenant' => $this->works])
        ->assertForbidden();
});

it('refuses the cross-tenant read itself to anyone without oversight authority', function () {
    expect(fn () => (new BuildTenantComplianceDetail)($this->mdaAdmin, $this->works))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new BuildTenantComplianceDetail)->obligations($this->mdaAdmin, $this->works, $this->period))
        ->toThrow(AuthorizationException::class);
});

it('keeps the board itself reachable from the drill-down', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/compliance/works'))
        ->assertOk()
        ->assertSee(oversightUrl('/compliance'), escape: false);

    Livewire::actingAs($this->stateAdmin)->test(ComplianceBoard::class)->assertOk();
});
