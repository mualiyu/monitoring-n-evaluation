<?php

/**
 * The mandatory isolation proof for the nine tenant-owned models of the
 * projects slice (rules/tenancy.md; projects-module.md §7, migration review §1).
 *
 * Two tenants, identical rows in each, then every read is taken from inside
 * tenant A's context: counts, ownership, a lookup by tenant B's primary key and
 * a lookup by tenant B's public ULID must all come back with nothing of B's.
 * The ULID case is the one that matters for URLs — a leaked public id from
 * another MDA must resolve to nothing, which is what makes route-model binding
 * 404 rather than serve another ministry's record.
 *
 * Isolation at the SURFACE — index, detail, export — is proven where those
 * surfaces live: ProjectScreensTest (list + detail + route-model binding) and
 * ProjectExportTest (the CSV, which leaves the Livewire response entirely and
 * is therefore the one read that could go wide without a screen showing it).
 */

use App\Models\Contract;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry per tenant-owned model in the slice. Each closure runs inside a
 * bound tenant context and returns a persisted record of that model.
 */
dataset('tenant-owned project models', [
    'projects' => [Project::class, fn () => Project::factory()->ongoing()->create()],
    'project locations' => [ProjectLocation::class, fn () => ProjectLocation::factory()->primary()->create()],
    'project funding sources' => [ProjectFundingSource::class, fn () => ProjectFundingSource::factory()->create()],
    'contracts' => [Contract::class, fn () => Contract::factory()->create()],
    'project assignments' => [ProjectAssignment::class, fn () => ProjectAssignment::factory()->create()],
    'project status events' => [ProjectStatusEvent::class, fn () => ProjectStatusEvent::factory()->create()],
    'indicators' => [Indicator::class, fn () => Indicator::factory()->active()->create()],
    'indicator targets' => [IndicatorTarget::class, fn () => IndicatorTarget::factory()->create()],
    'indicator readings' => [IndicatorReading::class, fn () => IndicatorReading::factory()->create()],
]);

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

it('shows one MDA only its own rows, never the other MDA\'s', function (string $model, Closure $create) {
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
})->with('tenant-owned project models');

it('resolves nothing for another MDA\'s public id', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    $routeKey = $inHealth->getRouteKeyName();

    $this->current->runAs($this->works, function () use ($model, $routeKey, $inWorks, $inHealth) {
        expect($model::query()->where($routeKey, $inHealth->getRouteKey())->first())->toBeNull()
            ->and($model::query()->where($routeKey, $inWorks->getRouteKey())->first())->not->toBeNull();
    });
})->with('tenant-owned project models');

it('stamps every row with the tenant that created it, without the factory naming one', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    expect($inWorks->tenant_id)->toBe($this->works->id)
        ->and($inHealth->tenant_id)->toBe($this->health->id);
})->with('tenant-owned project models');

it('refuses to read a tenant-owned model with no workspace bound', function (string $model, Closure $create) {
    $this->current->runAs($this->works, $create);

    actingWithoutTenant();

    expect(fn () => $model::query()->count())->toThrow(TenantNotResolvedException::class);
})->with('tenant-owned project models');

it('refuses to write a row into another MDA from inside this one', function () {
    $healthProject = $this->current->runAs($this->health, fn () => Project::factory()->create());

    $this->current->runAs($this->works, function () use ($healthProject) {
        expect(fn () => ProjectLocation::factory()->create([
            'project_id' => $healthProject->id,
            'tenant_id' => $healthProject->tenant_id,
        ]))->toThrow(CrossTenantWriteException::class);
    });
});

it('refuses to move an existing row to another MDA', function () {
    $project = $this->current->runAs($this->works, fn () => Project::factory()->create());

    $this->current->runAs($this->works, function () use ($project) {
        $project->tenant_id = $this->health->id;

        expect(fn () => $project->save())->toThrow(CrossTenantWriteException::class);
    });
});

it('keeps a project\'s whole object graph inside its own MDA', function () {
    $worksProject = $this->current->runAs($this->works, function () {
        $project = Project::factory()->ongoing()->multiSite(2)->coFunded()->create();
        Contract::factory()->forProject($project)->create();
        ProjectAssignment::factory()->forProject($project)->consultant()->create();
        ProjectStatusEvent::factory()->create(['project_id' => $project->id]);
        $indicator = Indicator::factory()->forProject($project)->active()->create();
        IndicatorTarget::factory()->forIndicator($indicator)->create();
        IndicatorReading::factory()->forIndicator($indicator)->create();

        return $project;
    });

    $this->current->runAs($this->health, function () use ($worksProject) {
        expect(Project::query()->count())->toBe(0)
            ->and(ProjectLocation::query()->count())->toBe(0)
            ->and(ProjectFundingSource::query()->count())->toBe(0)
            ->and(Contract::query()->count())->toBe(0)
            ->and(ProjectAssignment::query()->count())->toBe(0)
            ->and(ProjectStatusEvent::query()->count())->toBe(0)
            ->and(Indicator::query()->count())->toBe(0)
            ->and(IndicatorTarget::query()->count())->toBe(0)
            ->and(IndicatorReading::query()->count())->toBe(0)
            ->and(Project::query()->where('ulid', $worksProject->ulid)->exists())->toBeFalse();
    });
});

it('lets an oversight bypass — and only a bypass — read across both MDAs', function () {
    $this->current->runAs($this->works, fn () => Project::factory()->count(2)->create());
    $this->current->runAs($this->health, fn () => Project::factory()->create());

    actingWithoutTenant();

    expect($this->current->bypass(fn () => Project::query()->count()))->toBe(3)
        ->and($this->current->runAs($this->works, fn () => Project::query()->count()))->toBe(2);
});
