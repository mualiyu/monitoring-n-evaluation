<?php

/**
 * The secretariat's consolidation desk
 * (App\Livewire\Oversight\Consolidation\ConsolidationWorkspace).
 *
 * ⚠ ON TENANCY. ConsolidatedReport carries no tenant_id, deliberately: a state
 * roll-up spans every MDA and belongs to none (see the model). So the usual
 * "two tenants, assert A never sees B" isolation test has no subject here —
 * the isolation that DOES matter is that the workspace is unreachable from a
 * workspace role however senior, and that is asserted below. The per-artifact
 * cross-workspace gate is tested in ExportRegisterTest.
 */

use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use App\Enums\ReportingCadence;
use App\Enums\Role;
use App\Livewire\Oversight\Consolidation\ConsolidationWorkspace;
use App\Models\ConsolidatedReport;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->annual = ReportingPeriod::factory()->annual(2026)->create();
    $this->quarter = ReportingPeriod::factory()->quarterly(2026, 1)->create();

    // The oversight surface binds no tenant — every assertion below runs in
    // the context the real requests run in.
    app(CurrentTenant::class)->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
    $this->reviewer = userWithRole(Role::DataQualityReviewer);

    foreach ([$this->stateAdmin, $this->execViewer, $this->reviewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window
    }

    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    app(CurrentTenant::class)->forget();
});

/* -------------------------------------------------------------------------- */
/* The route reaches the screen */
/* -------------------------------------------------------------------------- */

it('renders the desk over real HTTP for the secretariat', function () {
    ConsolidatedReport::factory()
        ->forPeriod($this->annual)
        ->compiling(entities: 2, denominator: 2)
        ->create(['title' => 'Annual Performance Report — 2026']);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/consolidation'))
        ->assertOk()
        ->assertSeeLivewire(ConsolidationWorkspace::class)
        ->assertSee('Annual Performance Report — 2026')
        ->assertSee('Compiling');
});

it('lets a read-only oversight role see the desk', function () {
    ConsolidatedReport::factory()->forPeriod($this->annual)->create(['title' => 'Annual Performance Report — 2026']);

    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/consolidation'))
        ->assertOk()
        ->assertSee('Annual Performance Report — 2026');
});

it('shows a designed empty state before any roll-up has been opened', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/consolidation'))
        ->assertOk()
        ->assertSee('No consolidation has been opened yet');
});

/* -------------------------------------------------------------------------- */
/* Authorization matrix */
/* -------------------------------------------------------------------------- */

it('refuses the desk to a workspace user, however senior in their own ministry', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/consolidation'))
        ->assertForbidden();
});

it('refuses the component itself to a workspace user', function () {
    // Belt and braces: mount() authorizes as well, so a direct Livewire
    // request cannot reach it if the route group ever changes.
    Livewire::actingAs(User::query()->whereKey($this->mdaAdmin->id)->firstOrFail())
        ->test(ConsolidationWorkspace::class)
        ->assertForbidden();
});

it('offers no “open a consolidation” control to a role that may only read', function () {
    foreach ([$this->execViewer, $this->reviewer] as $user) {
        $this->actingAs($user)
            ->get(oversightUrl('/consolidation'))
            ->assertOk()
            ->assertDontSee('Open a consolidation');
    }
});

it('refuses to open a consolidation for an oversight role without secretariat authority', function () {
    foreach ([$this->execViewer, $this->reviewer] as $user) {
        Livewire::actingAs($user)
            ->test(ConsolidationWorkspace::class)
            ->set('newPeriod', $this->annual->code)
            ->set('newType', ConsolidatedReportType::AnnualApr->value)
            ->call('open')
            ->assertForbidden();
    }

    expect(ConsolidatedReport::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Opening a roll-up */
/* -------------------------------------------------------------------------- */

it('opens a consolidation and seeds the narrative skeleton the type requires', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ConsolidationWorkspace::class)
        ->call('startOpening')
        ->set('newPeriod', $this->annual->code)
        ->set('newType', ConsolidatedReportType::AnnualApr->value)
        ->call('open')
        ->assertHasNoErrors()
        ->assertRedirect();

    $report = ConsolidatedReport::query()->firstOrFail();

    expect($report->type)->toBe(ConsolidatedReportType::AnnualApr)
        ->and($report->status)->toBe(ConsolidationStatus::Draft)
        ->and($report->reporting_period_id)->toBe($this->annual->id)
        ->and($report->created_by_id)->toBe($this->stateAdmin->id)
        // The skeleton belongs to the TYPE, not to the form.
        ->and($report->sections()->pluck('key')->all())
        ->toBe(array_keys(ConsolidatedReportType::AnnualApr->sectionSkeleton()))
        // And the opening row of the ledger exists, with nothing before it.
        ->and($report->events()->count())->toBe(1)
        ->and($report->events()->first()->from_status)->toBeNull();
});

/**
 * THE rendered-form test. Livewire::test() sets properties directly and never
 * renders the markup a browser posts, which is how a select that submits the
 * wrong key ships green. So this one reads the option value out of the HTML
 * the screen actually produced and feeds THAT back through validation.
 *
 * The specific defect it guards: periodOptions() is keyed by window CODE, not
 * by id, and `exists:reporting_periods,code` only passes if the rendered
 * option carries the code.
 */
it('submits the window value its own rendered <option> carries', function () {
    $html = Livewire::actingAs($this->stateAdmin)
        ->test(ConsolidationWorkspace::class)
        ->call('startOpening')
        ->html();

    // Pull the select the form posts, then its first real option.
    expect($html)->toContain('id="newPeriod"');

    $select = preg_match('/<select[^>]*id="newPeriod".*?<\/select>/s', $html, $matches) === 1
        ? $matches[0]
        : '';

    expect($select)->not->toBe('');

    preg_match_all('/<option value="([^"]+)"/', $select, $options);

    $rendered = array_values(array_filter($options[1], fn (string $value): bool => $value !== ''));

    expect($rendered)->not->toBeEmpty()
        // A window code, never an auto-increment id.
        ->and($rendered[0])->not->toMatch('/^\d+$/');

    Livewire::actingAs($this->stateAdmin)
        ->test(ConsolidationWorkspace::class)
        ->call('startOpening')
        ->set('newPeriod', $rendered[0])
        ->set('newType', ConsolidatedReportType::AnnualApr->value)
        ->call('open')
        ->assertHasNoErrors();

    expect(ConsolidatedReport::query()->firstOrFail()->reportingPeriod->code)->toBe($rendered[0]);
});

it('refuses a window it does not recognise', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ConsolidationWorkspace::class)
        ->call('startOpening')
        ->set('newPeriod', 'NOT-A-WINDOW')
        ->set('newType', ConsolidatedReportType::AnnualApr->value)
        ->call('open')
        ->assertHasErrors(['newPeriod']);

    expect(ConsolidatedReport::query()->count())->toBe(0);
});

it('states the one-per-window rule as a field error rather than a 500', function () {
    ConsolidatedReport::factory()
        ->forPeriod($this->annual)
        ->ofType(ConsolidatedReportType::AnnualApr)
        ->create();

    Livewire::actingAs($this->stateAdmin)
        ->test(ConsolidationWorkspace::class)
        ->call('startOpening')
        ->set('newPeriod', $this->annual->code)
        ->set('newType', ConsolidatedReportType::AnnualApr->value)
        ->call('open')
        ->assertHasErrors(['newPeriod']);

    expect(ConsolidatedReport::query()->count())->toBe(1);
});

it('refuses to compile an annual report against a quarterly window', function () {
    // The window sets the denominator, so the wrong cadence makes every rate
    // in the finished document wrong in the same direction.
    expect($this->quarter->cadence)->toBe(ReportingCadence::Quarterly);

    Livewire::actingAs($this->stateAdmin)
        ->test(ConsolidationWorkspace::class)
        ->call('startOpening')
        ->set('newPeriod', $this->quarter->code)
        ->set('newType', ConsolidatedReportType::AnnualApr->value)
        ->call('open')
        ->assertHasErrors(['newPeriod']);

    expect(ConsolidatedReport::query()->count())->toBe(0);
});

it('lets a state open more than one thematic study in the same window', function () {
    foreach (['First', 'Second'] as $title) {
        Livewire::actingAs($this->stateAdmin)
            ->test(ConsolidationWorkspace::class)
            ->call('startOpening')
            ->set('newPeriod', $this->quarter->code)
            ->set('newType', ConsolidatedReportType::Thematic->value)
            ->set('newTitle', $title.' thematic study')
            ->call('open')
            ->assertHasNoErrors();
    }

    expect(ConsolidatedReport::query()->count())->toBe(2);
});

/* -------------------------------------------------------------------------- */
/* Filters and the summary row */
/* -------------------------------------------------------------------------- */

it('narrows the desk by chain state and by type', function () {
    ConsolidatedReport::factory()->forPeriod($this->annual)->create(['title' => 'A draft roll-up']);
    ConsolidatedReport::factory()
        ->forPeriod($this->quarter)
        ->ofType(ConsolidatedReportType::Quarterly)
        ->inReview()
        ->create(['title' => 'A quarterly pack in review']);

    $desk = Livewire::actingAs($this->stateAdmin)->test(ConsolidationWorkspace::class);

    expect($desk->instance()->reports->total())->toBe(2);

    $desk->set('status', ConsolidationStatus::InReview->value);
    expect($desk->instance()->reports->pluck('title')->all())->toBe(['A quarterly pack in review']);

    $desk->set('status', '')->set('type', ConsolidatedReportType::AnnualApr->value);
    expect($desk->instance()->reports->pluck('title')->all())->toBe(['A draft roll-up']);

    $desk->call('clearFilters');
    expect($desk->instance()->reports->total())->toBe(2);
});

it('counts the desk by where each roll-up has reached', function () {
    ConsolidatedReport::factory()->forPeriod($this->annual)->create();
    ConsolidatedReport::factory()->forPeriod($this->quarter)->ofType(ConsolidatedReportType::Quarterly)->inReview()->create();

    $stats = Livewire::actingAs($this->stateAdmin)->test(ConsolidationWorkspace::class)->instance()->stats;

    expect($stats['open'])->toBe(1)
        ->and($stats['in_review'])->toBe(1)
        ->and($stats['approved'])->toBe(0)
        ->and($stats['published'])->toBe(0)
        // The denominator every coverage figure is measured against.
        ->and($stats['entities'])->toBe(2);
});
