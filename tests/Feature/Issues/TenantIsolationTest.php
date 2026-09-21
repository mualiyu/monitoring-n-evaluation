<?php

/**
 * The mandatory isolation proof for the three tenant-owned models of the
 * issues slice (rules/tenancy.md).
 *
 * Two MDAs, identical rows in each, then every read is taken from inside
 * tenant A's context: counts, ownership, a lookup by tenant B's primary key
 * and a lookup by tenant B's public ULID must all come back with nothing of
 * B's. The ULID case is the one that matters for URLs — a leaked public id
 * from another MDA must resolve to nothing, which is what makes route-model
 * binding 404 rather than serve another ministry's register.
 *
 * The list, the DETAIL PAGE and the EXPORT are each asserted over real HTTP at
 * the end: a scope that holds in a query and leaks in a screen is the failure
 * mode this suite exists to catch.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Issues\IssueIndex;
use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

dataset('tenant-owned issue models', [
    'issues' => [Issue::class, fn () => Issue::factory()->create()],
    'issue events' => [IssueEvent::class, fn () => IssueEvent::factory()->create()],
    'exception reports' => [ExceptionReport::class, fn () => ExceptionReport::factory()->create()],
]);

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

it('shows one MDA only its own issue rows, never the other MDA\'s', function (string $model, Closure $create) {
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
})->with('tenant-owned issue models');

it('resolves nothing for another MDA\'s public issue id', function () {
    $inWorks = $this->current->runAs($this->works, fn () => Issue::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => Issue::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        expect(Issue::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(Issue::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('resolves nothing for another MDA\'s public exception report id', function () {
    $inWorks = $this->current->runAs($this->works, fn () => ExceptionReport::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => ExceptionReport::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        expect(ExceptionReport::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(ExceptionReport::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('stamps every issue row with the tenant that created it, without the factory naming one', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    expect($inWorks->tenant_id)->toBe($this->works->id)
        ->and($inHealth->tenant_id)->toBe($this->health->id);
})->with('tenant-owned issue models');

it('refuses to read a tenant-owned issue model with no workspace bound', function (string $model, Closure $create) {
    $this->current->runAs($this->works, $create);

    actingWithoutTenant();

    expect(fn () => $model::query()->count())->toThrow(TenantNotResolvedException::class);
})->with('tenant-owned issue models');

it('refuses to create an issue for a foreign workspace', function () {
    $foreign = $this->health;

    $this->current->runAs($this->works, function () use ($foreign) {
        $project = Project::factory()->ongoing()->create();

        expect(fn () => Issue::factory()->forProject($project)->create(['tenant_id' => $foreign->id]))
            ->toThrow(CrossTenantWriteException::class);
    });
});

it('never lets one MDA see another\'s register in the list, the detail page or the export', function () {
    seedPermissions();

    $mine = $this->current->runAs($this->works, function (): Issue {
        $project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

        return Issue::factory()->forProject($project)->create(['title' => 'Works: culvert collapsed']);
    });

    $theirs = $this->current->runAs($this->health, function (): Issue {
        $project = Project::factory()->ongoing()->create(['title' => 'Primary Health Centre Upgrade']);

        return Issue::factory()->forProject($project)->create(['title' => 'Health: roofing sheets stolen']);
    });

    actingOnTenant($this->works);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($officer);

    // The list.
    $this->get(tenantUrl($this->works, '/issues'))
        ->assertOk()
        ->assertSee('Works: culvert collapsed')
        ->assertDontSee('Health: roofing sheets stolen');

    // The detail page — a leaked public id from another MDA is a 404.
    $this->get(tenantUrl($this->works, '/issues/'.$mine->ulid))->assertOk();
    $this->get(tenantUrl($this->works, '/issues/'.$theirs->ulid))->assertNotFound();

    // The export. A row absent from the screen must be absent from the file —
    // a scoping mistake there produces a file nobody looks at on screen and
    // everybody opens in Excel. The streamed BYTES are read, because that is
    // the artifact the user actually receives.
    $response = Livewire::actingAs($officer)->test(IssueIndex::class)->instance()->export();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Works: culvert collapsed')
        ->and($csv)->toContain('Township Road Rehabilitation')
        ->and($csv)->not->toContain('Health: roofing sheets stolen')
        ->and($csv)->not->toContain('Primary Health Centre Upgrade');
});

it('never lets one MDA see another\'s exception reports in the list or the detail page', function () {
    seedPermissions();

    $mine = $this->current->runAs($this->works, function (): ExceptionReport {
        $project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

        return ExceptionReport::factory()->forProject($project)->create([
            'narrative' => 'Works deviation: the road has slipped badly.',
        ]);
    });

    $theirs = $this->current->runAs($this->health, function (): ExceptionReport {
        $project = Project::factory()->ongoing()->create(['title' => 'Primary Health Centre Upgrade']);

        return ExceptionReport::factory()->forProject($project)->create([
            'narrative' => 'Health deviation: the clinic has slipped badly.',
        ]);
    });

    actingOnTenant($this->works);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($officer);

    $this->get(tenantUrl($this->works, '/exceptions'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Primary Health Centre Upgrade');

    $this->get(tenantUrl($this->works, '/exceptions/'.$mine->ulid))->assertOk();
    $this->get(tenantUrl($this->works, '/exceptions/'.$theirs->ulid))->assertNotFound();
});
