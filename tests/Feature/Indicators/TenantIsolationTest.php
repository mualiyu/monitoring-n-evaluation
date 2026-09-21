<?php

/**
 * The mandatory isolation proof for the results-framework module
 * (rules/tenancy.md).
 *
 * Two MDAs, identical rows in each, then every read is taken from inside
 * tenant A's context: the query layer, the list SCREEN, the detail page by
 * another ministry's public id, and the CSV export. The export gets its own
 * cases because it is the one read that goes straight past the view layer
 * into a file somebody forwards — a scoping mistake there is invisible on
 * screen and permanent in Excel.
 *
 * `indicator_definitions` is deliberately NOT in the isolated list. The state
 * indicator library is the manual's "predetermined indicator list" (digest
 * §4): the secretariat's annual consolidation only produces comparable
 * numbers if "classrooms completed and handed over" means the same thing, in
 * the same unit, at the same frequency, in every ministry. Its SHARED
 * visibility is asserted at the end, so that "the library is global" is a
 * tested decision rather than a missing test.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Indicators\IndicatorIndex;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorReading;
use App\Models\IndicatorReadingEvent;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\ResultFramework;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

dataset('tenant-owned indicator models', [
    'result statements' => [ResultFramework::class, fn () => ResultFramework::factory()->create()],
    'indicators' => [Indicator::class, fn () => Indicator::factory()->create()],
    'indicator targets' => [IndicatorTarget::class, fn () => IndicatorTarget::factory()->create()],
    'indicator readings' => [IndicatorReading::class, fn () => IndicatorReading::factory()->create()],
    'reading events' => [IndicatorReadingEvent::class, fn () => IndicatorReadingEvent::factory()->create()],
]);

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

/** Run the export and capture the bytes it streams — the artifact a user receives. */
function exportedIndicatorCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/* -------------------------------------------------------------------------- */
/* The query layer */
/* -------------------------------------------------------------------------- */

it('shows one entity only its own results-framework rows, never the other entity’s', function (string $model, Closure $create) {
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
})->with('tenant-owned indicator models');

it('stamps every results-framework row with the entity that created it, without the factory naming one', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    expect($inWorks->tenant_id)->toBe($this->works->id)
        ->and($inHealth->tenant_id)->toBe($this->health->id);
})->with('tenant-owned indicator models');

it('refuses to read a tenant-owned results-framework model with no workspace bound', function (string $model, Closure $create) {
    $this->current->runAs($this->works, $create);

    actingWithoutTenant();

    // Fail closed: "no context" must never quietly mean "any context".
    expect(fn () => $model::query()->count())->toThrow(TenantNotResolvedException::class);
})->with('tenant-owned indicator models');

it('resolves nothing for another entity’s public indicator id', function () {
    $inWorks = $this->current->runAs($this->works, fn () => Indicator::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => Indicator::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        // The ULID case is the one that matters for URLs: a leaked public id
        // from another ministry resolves to nothing, which is what makes
        // route-model binding 404 rather than serve a foreign measure.
        expect(Indicator::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(Indicator::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('resolves nothing for another entity’s public reading id either', function () {
    $inWorks = $this->current->runAs($this->works, fn () => IndicatorReading::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => IndicatorReading::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        expect(IndicatorReading::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(IndicatorReading::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('refuses to hang an indicator off another entity’s result statement', function () {
    $healthStatement = $this->current->runAs($this->health, fn () => ResultFramework::factory()->create());

    $this->current->runAs($this->works, function () use ($healthStatement) {
        expect(fn () => Indicator::factory()->create([
            'result_framework_id' => $healthStatement->id,
            'tenant_id' => $healthStatement->tenant_id,
        ]))->toThrow(CrossTenantWriteException::class);
    });
});

it('keeps an indicator’s whole measurement history inside its own entity', function () {
    $worksIndicator = $this->current->runAs($this->works, function () {
        $indicator = Indicator::factory()->active()->create();
        IndicatorTarget::factory()->forIndicator($indicator)->create();
        $reading = IndicatorReading::factory()->forIndicator($indicator)->submitted()->create();
        IndicatorReadingEvent::factory()->forReading($reading)->create();

        return $indicator;
    });

    $this->current->runAs($this->health, function () use ($worksIndicator) {
        expect(Indicator::query()->count())->toBe(0)
            ->and(IndicatorTarget::query()->count())->toBe(0)
            ->and(IndicatorReading::query()->count())->toBe(0)
            ->and(IndicatorReadingEvent::query()->count())->toBe(0)
            ->and(Indicator::query()->where('ulid', $worksIndicator->ulid)->exists())->toBeFalse();
    });
});

/* -------------------------------------------------------------------------- */
/* The screens: list, detail, export */
/* -------------------------------------------------------------------------- */

describe('across the subdomains', function () {
    beforeEach(function () {
        seedPermissions();

        $this->mine = $this->current->runAs($this->works, function (): Indicator {
            $project = Project::factory()->ongoing()->create([
                'title' => 'Township Road Rehabilitation',
                'reference' => 'WKS/2026/001',
            ]);

            return Indicator::factory()->active()->forProject($project)->create([
                'name' => 'Kilometres of road rehabilitated',
            ]);
        });

        $this->theirs = $this->current->runAs($this->health, function (): Indicator {
            $project = Project::factory()->ongoing()->create([
                'title' => 'Model Primary Health Centre',
                'reference' => 'HLT/2026/007',
            ]);

            return Indicator::factory()->active()->forProject($project)->create([
                'name' => 'Facilities meeting the minimum equipment standard',
            ]);
        });

        actingOnTenant($this->works);
        $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
        actingOnTenant($this->works);
    });

    it('lists only this entity’s indicators on its own subdomain', function () {
        $this->actingAs($this->admin)
            ->get(tenantUrl($this->works, '/indicators'))
            ->assertOk()
            ->assertSee('Kilometres of road rehabilitated')
            ->assertDontSee('Facilities meeting the minimum equipment standard')
            ->assertDontSee('Model Primary Health Centre');
    });

    it('404s the detail page for another entity’s indicator ULID', function () {
        // Not a 403: a refusal would confirm the row exists somewhere, which
        // is itself information about another ministry.
        $this->actingAs($this->admin)
            ->get(tenantUrl($this->works, '/indicators/'.$this->theirs->ulid))
            ->assertNotFound();
    });

    it('keeps a foreign indicator out of the CSV export, not merely off the screen', function () {
        $csv = exportedIndicatorCsv(
            Livewire::actingAs($this->admin)->test(IndicatorIndex::class)->instance()->export()
        );

        expect($csv)->toContain('Kilometres of road rehabilitated')
            ->and($csv)->toContain('Township Road Rehabilitation')
            // By name AND by project — an export that leaks only the project
            // column is still a leak.
            ->and($csv)->not->toContain('Facilities meeting the minimum equipment standard')
            ->and($csv)->not->toContain('Model Primary Health Centre')
            ->and($csv)->not->toContain('HLT/2026/007');
    });

    it('exports nothing at all from an entity that has no indicators of its own', function () {
        $education = Tenant::factory()->create(['name' => 'Ministry of Education', 'slug' => 'education']);
        actingOnTenant($education);
        $admin = memberOf(User::factory()->create(), $education, Role::MdaAdmin);
        actingOnTenant($education);

        $csv = exportedIndicatorCsv(
            Livewire::actingAs($admin)->test(IndicatorIndex::class)->instance()->export()
        );

        // Header row and the UTF-8 BOM, and not one data line: an empty
        // register must produce an empty file, never "everyone else's".
        expect(array_values(array_filter(explode("\n", trim($csv)))))->toHaveCount(1)
            ->and($csv)->toContain('Indicator');
    });

    it('matches nothing when the project filter carries a foreign ULID', function () {
        $foreignProject = $this->current->runAs(
            $this->health,
            fn (): Project => Project::query()->firstOrFail(),
        );

        actingOnTenant($this->works);

        $component = Livewire::actingAs($this->admin)
            ->test(IndicatorIndex::class)
            ->set('projectUlid', $foreignProject->ulid);

        expect($component->instance()->indicators()->total())->toBe(0);
    });
});

/* -------------------------------------------------------------------------- */
/* The one thing that is deliberately shared */
/* -------------------------------------------------------------------------- */

it('shows both entities the same indicator library, because the library is the state’s', function () {
    $definition = IndicatorDefinition::factory()->create(['code' => 'EDU-001']);

    // No tenant bound at all: global reference data must be readable from the
    // console and the oversight surface as well as from a workspace.
    expect(IndicatorDefinition::query()->count())->toBe(1);

    foreach ([$this->works, $this->health] as $tenant) {
        $this->current->runAs($tenant, function () use ($definition) {
            expect(IndicatorDefinition::query()->count())->toBe(1)
                ->and(IndicatorDefinition::query()->find($definition->id))->not->toBeNull();
        });
    }
});

it('gives the library no tenant column to scope by, which is what makes it shared', function () {
    // Stated as a schema assertion, not only as a behavioural one: the day
    // somebody adds tenant_id here, every MDA silently loses the state list
    // and the consolidation stops comparing like with like.
    expect(Schema::hasColumn('indicator_definitions', 'tenant_id'))->toBeFalse()
        ->and(in_array('App\Models\Concerns\BelongsToTenant', class_uses_recursive(IndicatorDefinition::class), true))
        ->toBeFalse();
});

it('keeps each entity’s instantiations of one shared library entry apart', function () {
    $definition = IndicatorDefinition::factory()->create(['code' => 'EDU-001']);

    $this->current->runAs($this->works, fn () => Indicator::factory()->fromLibrary($definition)->create());
    $this->current->runAs($this->health, fn () => Indicator::factory()->fromLibrary($definition)->create());

    // The definition is one row shared by both; the indicators made from it
    // are two rows, one per ministry, and the relation is scoped like any
    // other tenant-owned read.
    $this->current->runAs($this->works, function () use ($definition) {
        expect($definition->indicators()->count())->toBe(1);
    });

    actingWithoutTenant();

    expect($this->current->bypass(fn (): int => Indicator::query()->count()))->toBe(2);
});

it('lets an oversight bypass — and only a bypass — read readings across both entities', function () {
    $this->current->runAs($this->works, fn () => IndicatorReading::factory()->count(2)->create());
    $this->current->runAs($this->health, fn () => IndicatorReading::factory()->create());

    actingWithoutTenant();

    expect($this->current->bypass(fn (): int => IndicatorReading::query()->count()))->toBe(3)
        ->and($this->current->runAs($this->works, fn (): int => IndicatorReading::query()->count()))->toBe(2);
});
