<?php

/**
 * The mandatory isolation proof for the three tenant-owned models of the
 * reporting slice (rules/tenancy.md; progress-reporting.md §1.5, §7).
 *
 * Two MDAs, identical rows in each, then every read is taken from inside
 * tenant A's context: counts, ownership, a lookup by tenant B's primary key
 * and a lookup by tenant B's public ULID must all come back with nothing of
 * B's. The ULID case is the one that matters for URLs — a leaked public id
 * from another MDA must resolve to nothing, which is what makes route-model
 * binding 404 rather than serve another ministry's return.
 *
 * `reporting_periods` is deliberately NOT in this list. The statutory calendar
 * is state-wide (§1.1): both MDAs report against the same windows, and the
 * compliance league table is only meaningful if the denominator is identical
 * across ministries. Its shared visibility is asserted at the end, so that
 * "the calendar is global" is a tested decision rather than a missing test.
 */

use App\Models\ProgressReport;
use App\Models\ProgressReportEvent;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;

dataset('tenant-owned reporting models', [
    'report obligations' => [ReportObligation::class, fn () => ReportObligation::factory()->create()],
    'progress reports' => [ProgressReport::class, fn () => ProgressReport::factory()->create()],
    'progress report events' => [ProgressReportEvent::class, fn () => ProgressReportEvent::factory()->create()],
]);

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

it('shows one MDA only its own reporting rows, never the other MDA\'s', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    $this->current->runAs($this->works, function () use ($model, $inWorks, $inHealth) {
        expect($model::query()->count())->toBe(1)
            ->and($model::query()->pluck('id')->all())->toBe([$inWorks->getKey()])
            ->and($model::query()->find($inHealth->getKey()))->toBeNull();
    });

    $this->current->runAs($this->health, function () use ($model, $inWorks, $inHealth) {
        expect($model::query()->count())->toBe(1)
            ->and($model::query()->pluck('id')->all())->toBe([$inHealth->getKey()])
            ->and($model::query()->find($inWorks->getKey()))->toBeNull();
    });
})->with('tenant-owned reporting models');

it('resolves nothing for another MDA\'s public report id', function () {
    $inWorks = $this->current->runAs($this->works, fn () => ProgressReport::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => ProgressReport::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        expect(ProgressReport::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(ProgressReport::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('stamps every reporting row with the tenant that created it, without the factory naming one', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    expect($inWorks->tenant_id)->toBe($this->works->id)
        ->and($inHealth->tenant_id)->toBe($this->health->id);
})->with('tenant-owned reporting models');

it('refuses to read a tenant-owned reporting model with no workspace bound', function (string $model, Closure $create) {
    $this->current->runAs($this->works, $create);

    actingWithoutTenant();

    expect(fn () => $model::query()->count())->toThrow(TenantNotResolvedException::class);
})->with('tenant-owned reporting models');

it('refuses to file a return into another MDA from inside this one', function () {
    $healthProject = $this->current->runAs($this->health, fn () => Project::factory()->ongoing()->create());

    $this->current->runAs($this->works, function () use ($healthProject) {
        expect(fn () => ProgressReport::factory()->create([
            'project_id' => $healthProject->id,
            'tenant_id' => $healthProject->tenant_id,
        ]))->toThrow(CrossTenantWriteException::class);
    });
});

it('keeps a project\'s whole reporting history inside its own MDA', function () {
    $worksReport = $this->current->runAs($this->works, function () {
        $obligation = ReportObligation::factory()->create();
        $report = ProgressReport::factory()->forObligation($obligation)->submitted()->create();
        ProgressReportEvent::factory()->forReport($report)->create();

        return $report;
    });

    $this->current->runAs($this->health, function () use ($worksReport) {
        expect(ReportObligation::query()->count())->toBe(0)
            ->and(ProgressReport::query()->count())->toBe(0)
            ->and(ProgressReportEvent::query()->count())->toBe(0)
            ->and(ProgressReport::query()->where('ulid', $worksReport->ulid)->exists())->toBeFalse();
    });
});

it('lets an oversight bypass — and only a bypass — read obligations across both MDAs', function () {
    $this->current->runAs($this->works, fn () => ReportObligation::factory()->count(2)->create());
    $this->current->runAs($this->health, fn () => ReportObligation::factory()->create());

    actingWithoutTenant();

    expect($this->current->bypass(fn () => ReportObligation::query()->count()))->toBe(3)
        ->and($this->current->runAs($this->works, fn () => ReportObligation::query()->count()))->toBe(2);
});

it('shows both MDAs the same statutory calendar, because the calendar is the state\'s', function () {
    $period = ReportingPeriod::factory()->monthly()->create();

    // No tenant bound at all: a global model must be readable from the
    // console and the oversight surface as well as from a workspace.
    expect(ReportingPeriod::query()->count())->toBe(1);

    foreach ([$this->works, $this->health] as $tenant) {
        $this->current->runAs($tenant, function () use ($period) {
            expect(ReportingPeriod::query()->count())->toBe(1)
                ->and(ReportingPeriod::query()->find($period->id))->not->toBeNull();
        });
    }
});
