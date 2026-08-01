<?php

/**
 * The oversight surface (projects-module.md §5, §6): the only place in the
 * platform where one query legitimately spans every MDA.
 *
 * Three things have to be true at once — the board aggregates across tenants,
 * it costs a handful of queries rather than one per card, and no tenant user
 * can reach it however they arrive.
 */

use App\Actions\Oversight\BuildPortfolioSummary;
use App\Actions\Oversight\ListProjectsAcrossTenants;
use App\Actions\Projects\TransitionProjectStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $current = app(CurrentTenant::class);

    $current->runAs($this->works, function () {
        Project::factory()->ongoing()->create([
            'contract_value_total' => '300000000.00',
            'expenditure_to_date' => '120000000.00',
            'physical_progress' => '40.00',
        ]);
        Project::factory()->draft()->create();
    });

    $current->runAs($this->health, function () {
        Project::factory()->ongoing()->create([
            'contract_value_total' => '200000000.00',
            'expenditure_to_date' => '80000000.00',
            'physical_progress' => '60.00',
        ]);
    });

    $current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
});

it('aggregates every MDA into one board', function () {
    $summary = (new BuildPortfolioSummary)($this->stateAdmin);

    expect($summary['totals']['project_count'])->toBe(3)
        ->and($summary['totals']['contract_value_total']->toDecimalString())->toBe('500000000.00')
        ->and($summary['totals']['expenditure_total']->toDecimalString())->toBe('200000000.00')
        ->and($summary['by_status'][ProjectStatus::InProgress->value])->toBe(2)
        ->and($summary['by_status'][ProjectStatus::Draft->value])->toBe(1)
        ->and($summary['tenants'])->toHaveCount(2);

    $works = collect($summary['tenants'])->firstWhere('tenant_id', $this->works->id);

    expect($works['name'])->toBe('Ministry of Works')
        ->and($works['project_count'])->toBe(2)
        ->and($works['contract_value_total']->toDecimalString())->toBe('300000000.00');
});

it('answers an executive viewer too — oversight is read-wide by design', function () {
    expect((new BuildPortfolioSummary)($this->execViewer)['totals']['project_count'])->toBe(3);
});

it('refuses the board to an MDA admin, on any surface', function () {
    $mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    expect(fn () => (new BuildPortfolioSummary)($mdaAdmin->fresh()))
        ->toThrow(AuthorizationException::class);

    // …and still refuses inside their own workspace context
    app(CurrentTenant::class)->set($this->works);

    expect(fn () => (new BuildPortfolioSummary)($mdaAdmin->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('serves the whole board from a handful of queries once warm', function () {
    $summary = new BuildPortfolioSummary;

    $summary($this->stateAdmin);            // warm the cache and the permission lookups

    DB::enableQueryLog();
    $summary($this->stateAdmin);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(3);
});

it('busts the cache when a project moves, so the board is never stale about status', function () {
    $summary = new BuildPortfolioSummary;

    expect($summary($this->stateAdmin)['by_status'][ProjectStatus::Suspended->value])->toBe(0)
        ->and(Cache::has(BuildPortfolioSummary::CACHE_KEY))->toBeTrue();

    app(CurrentTenant::class)->runAs($this->works, function () {
        $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
        $project = Project::query()->where('status', ProjectStatus::InProgress)->firstOrFail();

        (new TransitionProjectStatus)($project, ProjectStatus::Suspended, $admin->fresh(), 'Funding paused.');
    });

    expect(Cache::has(BuildPortfolioSummary::CACHE_KEY))->toBeFalse()
        ->and($summary($this->stateAdmin)['by_status'][ProjectStatus::Suspended->value])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The cross-MDA list
|--------------------------------------------------------------------------
*/

it('lists projects across MDAs and filters down to one', function () {
    $list = new ListProjectsAcrossTenants;

    expect($list($this->stateAdmin)->total())->toBe(3)
        ->and($list($this->stateAdmin, ['tenant' => $this->health])->total())->toBe(1)
        ->and($list($this->stateAdmin, ['status' => ProjectStatus::Draft])->total())->toBe(1);
});

it('refuses the cross-MDA list to a tenant user', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    expect(fn () => (new ListProjectsAcrossTenants)($officer->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('eager-loads what the row renders, so a cross-MDA list is not an N+1', function () {
    $page = (new ListProjectsAcrossTenants)($this->stateAdmin);

    DB::enableQueryLog();

    foreach ($page->items() as $project) {
        $project->tenant->name;
        $project->sector->name;
        $project->assignments_count;
    }

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});
