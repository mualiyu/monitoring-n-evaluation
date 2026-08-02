<?php

/**
 * The state-wide compliance board (progress-reporting.md §3.1) — the manual's
 * rewards-and-sanctions instrument, so the numbers on it have to be right and
 * the query behind it has to stay one query.
 *
 * Three properties under test:
 *   - the counts are per MDA and correct, including the on-time distinction,
 *   - NO progress-report row is scanned to produce them (that is why §1.2
 *     materializes obligations at all) — asserted as a query-count ceiling,
 *   - it is oversight authority, and a workspace user cannot reach it however
 *     senior they are inside their own ministry.
 */

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Actions\Reporting\SubmitProgressReport;
use App\Actions\Reporting\WaiveReportObligation;
use App\Enums\Role;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->build = app(BuildComplianceLeagueTable::class);

    // Works filed three of four returns, one of them late.
    // Health filed one of three, and missed one outright.
    $this->current->runAs($this->works, function () {
        $project = Project::factory()->ongoing()->create();

        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled(late: true)->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();
    });

    $this->current->runAs($this->health, function () {
        $project = Project::factory()->ongoing()->create();

        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->missed()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();
    });
});

it('counts what each MDA owed, filed, filed on time and missed', function () {
    $board = ($this->build)($this->stateAdmin, $this->period);

    $rows = collect($board['tenants'])->keyBy('slug');

    expect($rows)->toHaveCount(2)
        ->and($rows['works']['expected'])->toBe(4)
        ->and($rows['works']['submitted'])->toBe(3)
        ->and($rows['works']['on_time'])->toBe(2)
        ->and($rows['works']['missed'])->toBe(0)
        ->and($rows['health']['expected'])->toBe(3)
        ->and($rows['health']['submitted'])->toBe(1)
        ->and($rows['health']['on_time'])->toBe(1)
        ->and($rows['health']['missed'])->toBe(1);
});

it('totals the state, and ranks the better-performing MDA first', function () {
    $board = ($this->build)($this->stateAdmin, $this->period);

    expect($board['totals'])->toBe([
        'expected' => 7, 'submitted' => 4, 'on_time' => 3, 'missed' => 1, 'waived' => 0,
    ])
        // Works is on 50% on-time against Health's 33% — the ranking IS the
        // sanction, so its order is part of the contract.
        ->and($board['tenants'][0]['slug'])->toBe('works')
        ->and($board['period']['code'])->toBe($this->period->code);
});

it('excuses a waived obligation from the denominator instead of scoring it as a failure', function () {
    $waiver = $this->current->runAs($this->health, function () {
        $admin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);
        $pending = ReportObligation::query()->outstanding()->firstOrFail();

        app(WaiveReportObligation::class)($pending, $admin, 'Site inaccessible for the whole period after flooding.');

        return $pending;
    });

    $board = ($this->build)($this->stateAdmin, $this->period);
    $health = collect($board['tenants'])->firstWhere('slug', 'health');

    expect($waiver->fresh()->status->value)->toBe('waived')
        ->and($health['expected'])->toBe(3)
        ->and($health['waived'])->toBe(1)
        // 1 filed out of 2 scored obligations, not out of 3.
        ->and($health['compliance_rate'])->toBe(50.0);
});

it('builds the whole board in one grouped query, whatever the number of MDAs', function () {
    /** @var Closure(): list<string> $measure */
    $measure = function (): array {
        Cache::forget(BuildComplianceLeagueTable::cacheKey($this->period->id));

        DB::enableQueryLog();
        DB::flushQueryLog();

        ($this->build)($this->stateAdmin, $this->period);

        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $queries;
    };

    // Warm the authorization cache first: a cold permission lookup is a fixed
    // cost every oversight screen pays once, and it is not what this asserts.
    ($this->build)($this->stateAdmin, $this->period);

    $twoMdas = $measure();

    // A third ministry, with its own obligations, must not add a query.
    $lands = Tenant::factory()->create(['name' => 'Ministry of Lands', 'slug' => 'lands']);
    $this->current->runAs($lands, function () {
        $project = Project::factory()->ongoing()->create();
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->count(3)->create();
    });

    $threeMdas = $measure();

    $obligationQueries = fn (array $queries): int => count(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'report_obligations'),
    ));

    expect($obligationQueries($twoMdas))->toBe(1)
        // The whole point of materializing obligations (§1.2): the board costs
        // the same with 40 MDAs as with two.
        ->and(count($threeMdas))->toBe(count($twoMdas))
        ->and($obligationQueries($threeMdas))->toBe(1)
        // And no progress report row is ever read to compute compliance.
        ->and(implode(' ', $threeMdas))->not->toContain('progress_reports');
});

it('serves the board from cache, and busts it the moment a return is filed', function () {
    $before = ($this->build)($this->stateAdmin, $this->period);
    expect($before['totals']['submitted'])->toBe(4);

    $this->current->runAs($this->works, function () {
        $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
        $obligation = ReportObligation::query()->outstanding()->firstOrFail();

        $report = ProgressReport::factory()
            ->forObligation($obligation)
            // Above whatever the fixture project stands at, so the submission
            // is not refused for an unexplained downward revision.
            ->create(['physical_progress_claimed' => '95.00', 'created_by_id' => $consultant->id]);

        app(SubmitProgressReport::class)($report, $consultant);
    });

    $after = ($this->build)($this->stateAdmin, $this->period);

    expect($after['totals']['submitted'])->toBe(5);
});

it('refuses the board to a workspace user, however senior in their own ministry', function () {
    $mdaAdmin = $this->current->runAs(
        $this->works,
        fn () => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin),
    );

    expect(fn () => ($this->build)($mdaAdmin->fresh(), $this->period))
        ->toThrow(AuthorizationException::class, 'oversight authority');
});

it('lets every oversight role read the board', function (Role $role) {
    $viewer = userWithRole($role);

    expect(($this->build)($viewer, $this->period)['totals']['expected'])->toBe(7);
})->with([
    'state admin' => [Role::StateAdmin],
    'executive viewer' => [Role::ExecutiveViewer],
    'data quality reviewer' => [Role::DataQualityReviewer],
    'super admin' => [Role::SuperAdmin],
]);
