<?php

/**
 * The mandatory isolation proof for the evaluation module (rules/tenancy.md).
 *
 * Six tenant-owned tables — the commission, its roster, its report sections,
 * its scorecard, its lifecycle ledger and the follow-up register — and every
 * one of them carries findings about a ministry's own delivery. A leak here is
 * not a privacy nicety: it is one MDA reading another's unpublished
 * conclusions about itself.
 *
 * Every read is taken from inside tenant A's context: the query layer, the
 * list SCREEN, the detail page by another ministry's public id, and the CSV
 * export. The export gets its own cases because it goes straight past the view
 * layer into a file somebody forwards — a scoping mistake there is invisible
 * on screen and permanent in Excel.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Evaluation\EvaluationIndex;
use App\Livewire\Tenant\Evaluation\RecommendationIndex;
use App\Models\Evaluation;
use App\Models\EvaluationCriterionScore;
use App\Models\EvaluationEvent;
use App\Models\EvaluationReportSection;
use App\Models\EvaluationTeamMember;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

dataset('tenant-owned evaluation models', [
    'evaluations' => [Evaluation::class, fn () => Evaluation::factory()->create()],
    'team members' => [EvaluationTeamMember::class, fn () => EvaluationTeamMember::factory()->create()],
    'report sections' => [EvaluationReportSection::class, fn () => EvaluationReportSection::factory()->create()],
    'criterion scores' => [EvaluationCriterionScore::class, fn () => EvaluationCriterionScore::factory()->create()],
    'lifecycle events' => [EvaluationEvent::class, fn () => EvaluationEvent::factory()->create()],
    'recommendations' => [Recommendation::class, fn () => Recommendation::factory()->create()],
]);

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

/** Run an export and capture the bytes it streams — the artifact a user receives. */
function exportedEvaluationCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/* -------------------------------------------------------------------------- */
/* The query layer */
/* -------------------------------------------------------------------------- */

it('shows one entity only its own evaluation rows, never the other entity’s', function (string $model, Closure $create) {
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
})->with('tenant-owned evaluation models');

it('stamps every evaluation row with the entity that created it, without the factory naming one', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    expect($inWorks->tenant_id)->toBe($this->works->id)
        ->and($inHealth->tenant_id)->toBe($this->health->id);
})->with('tenant-owned evaluation models');

it('refuses to read a tenant-owned evaluation model with no workspace bound', function (string $model, Closure $create) {
    $this->current->runAs($this->works, $create);

    actingWithoutTenant();

    // Fail closed: "no context" must never quietly mean "any context".
    expect(fn () => $model::query()->count())->toThrow(TenantNotResolvedException::class);
})->with('tenant-owned evaluation models');

it('resolves nothing for another entity’s public evaluation id', function () {
    $inWorks = $this->current->runAs($this->works, fn () => Evaluation::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => Evaluation::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        // The ULID case is the one that matters for URLs: another ministry's
        // public id resolves to nothing, so route-model binding 404s rather
        // than serving unpublished findings about somebody else.
        expect(Evaluation::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(Evaluation::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('resolves nothing for another entity’s public recommendation id either', function () {
    $inWorks = $this->current->runAs($this->works, fn () => Recommendation::factory()->create());
    $inHealth = $this->current->runAs($this->health, fn () => Recommendation::factory()->create());

    $this->current->runAs($this->works, function () use ($inWorks, $inHealth) {
        expect(Recommendation::query()->where('ulid', $inHealth->ulid)->first())->toBeNull()
            ->and(Recommendation::query()->where('ulid', $inWorks->ulid)->first())->not->toBeNull();
    });
});

it('refuses to commission an evaluation of another entity’s project from inside this one', function () {
    $healthProject = $this->current->runAs($this->health, fn () => Project::factory()->ongoing()->create());

    $this->current->runAs($this->works, function () use ($healthProject) {
        expect(fn () => Evaluation::factory()->create([
            'project_id' => $healthProject->id,
            'tenant_id' => $healthProject->tenant_id,
        ]))->toThrow(CrossTenantWriteException::class);
    });
});

it('keeps an evaluation’s whole record — roster, report, scorecard, ledger and findings — inside its own entity', function () {
    $worksEvaluation = $this->current->runAs($this->works, function (): Evaluation {
        $evaluation = Evaluation::factory()->create();

        EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead()->create();
        EvaluationReportSection::factory()->forEvaluation($evaluation)->template('findings')->written()->create();
        EvaluationCriterionScore::factory()->forEvaluation($evaluation)->scored()->create();
        EvaluationEvent::factory()->forEvaluation($evaluation)->create();
        Recommendation::factory()->from($evaluation)->create();

        return $evaluation;
    });

    $this->current->runAs($this->health, function () use ($worksEvaluation) {
        expect(Evaluation::query()->count())->toBe(0)
            ->and(EvaluationTeamMember::query()->count())->toBe(0)
            ->and(EvaluationReportSection::query()->count())->toBe(0)
            ->and(EvaluationCriterionScore::query()->count())->toBe(0)
            ->and(EvaluationEvent::query()->count())->toBe(0)
            ->and(Recommendation::query()->count())->toBe(0)
            ->and(Evaluation::query()->where('ulid', $worksEvaluation->ulid)->exists())->toBeFalse();
    });
});

it('never lets a recommendation hang off another entity’s evaluation', function () {
    $healthEvaluation = $this->current->runAs($this->health, fn () => Evaluation::factory()->create());

    $this->current->runAs($this->works, function () use ($healthEvaluation) {
        // `source_type` is a string and `source_id` has no foreign key, so the
        // global scope cannot police this pairing on its own — the write guard
        // has to.
        expect(fn () => Recommendation::factory()->create([
            'source_type' => $healthEvaluation->getMorphClass(),
            'source_id' => $healthEvaluation->id,
            'tenant_id' => $healthEvaluation->tenant_id,
        ]))->toThrow(CrossTenantWriteException::class);
    });
});

it('lets an oversight bypass — and only a bypass — read evaluations across both entities', function () {
    $this->current->runAs($this->works, fn () => Evaluation::factory()->count(2)->create());
    $this->current->runAs($this->health, fn () => Evaluation::factory()->create());

    actingWithoutTenant();

    expect($this->current->bypass(fn (): int => Evaluation::query()->count()))->toBe(3)
        ->and($this->current->runAs($this->works, fn (): int => Evaluation::query()->count()))->toBe(2);
});

/* -------------------------------------------------------------------------- */
/* The screens: list, detail, export */
/* -------------------------------------------------------------------------- */

describe('across the subdomains', function () {
    beforeEach(function () {
        seedPermissions();

        $this->mine = $this->current->runAs($this->works, function (): Evaluation {
            $project = Project::factory()->ongoing()->create([
                'title' => 'Township Road Rehabilitation',
                'reference' => 'WKS/2026/001',
            ]);

            $evaluation = Evaluation::factory()->forProject($project)->midTerm()->create([
                'title' => 'Mid-term evaluation of the township road programme',
                'sponsor' => 'State M&E Secretariat',
            ]);

            Recommendation::factory()->from($evaluation)->create([
                'title' => 'Re-sequence the drainage works ahead of the wet season',
                'addressee_body' => 'Directorate of Works',
            ]);

            return $evaluation;
        });

        $this->theirs = $this->current->runAs($this->health, function (): Evaluation {
            $project = Project::factory()->ongoing()->create([
                'title' => 'Model Primary Health Centre',
                'reference' => 'HLT/2026/007',
            ]);

            $evaluation = Evaluation::factory()->forProject($project)->terminal()->create([
                'title' => 'Terminal evaluation of the cottage hospital upgrade',
                'sponsor' => 'Hospitals Management Board',
            ]);

            Recommendation::factory()->from($evaluation)->create([
                'title' => 'Commission the generator before handover',
                'addressee_body' => 'Directorate of Hospital Services',
            ]);

            return $evaluation;
        });

        actingOnTenant($this->works);
        $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
        actingOnTenant($this->works);
    });

    it('lists only this entity’s evaluations on its own subdomain', function () {
        $this->actingAs($this->admin)
            ->get(tenantUrl($this->works, '/evaluations'))
            ->assertOk()
            ->assertSee('Mid-term evaluation of the township road programme')
            ->assertDontSee('Terminal evaluation of the cottage hospital upgrade')
            ->assertDontSee('Model Primary Health Centre');
    });

    it('lists only this entity’s recommendations on its own subdomain', function () {
        $this->actingAs($this->admin)
            ->get(tenantUrl($this->works, '/recommendations'))
            ->assertOk()
            ->assertSee('Re-sequence the drainage works ahead of the wet season')
            ->assertDontSee('Commission the generator before handover')
            ->assertDontSee('Directorate of Hospital Services');
    });

    it('404s the detail page for another entity’s evaluation ULID', function () {
        // Not a 403: a refusal would confirm the record exists somewhere,
        // which is itself information about another ministry.
        $this->actingAs($this->admin)
            ->get(tenantUrl($this->works, '/evaluations/'.$this->theirs->ulid))
            ->assertNotFound();
    });

    it('keeps a foreign evaluation out of the CSV export, not merely off the screen', function () {
        $csv = exportedEvaluationCsv(
            Livewire::actingAs($this->admin)->test(EvaluationIndex::class)->instance()->export()
        );

        expect($csv)->toContain('Mid-term evaluation of the township road programme')
            ->and($csv)->toContain('Township Road Rehabilitation')
            // By title AND by subject AND by sponsor — an export that leaks
            // only one column is still a leak.
            ->and($csv)->not->toContain('Terminal evaluation of the cottage hospital upgrade')
            ->and($csv)->not->toContain('Model Primary Health Centre')
            ->and($csv)->not->toContain('Hospitals Management Board');
    });

    it('keeps a foreign recommendation out of the follow-up export', function () {
        $csv = exportedEvaluationCsv(
            Livewire::actingAs($this->admin)->test(RecommendationIndex::class)->instance()->export()
        );

        expect($csv)->toContain('Re-sequence the drainage works ahead of the wet season')
            ->and($csv)->not->toContain('Commission the generator before handover')
            ->and($csv)->not->toContain('Directorate of Hospital Services');
    });

    it('exports nothing at all from an entity with no evaluations of its own', function () {
        $education = Tenant::factory()->create(['name' => 'Ministry of Education', 'slug' => 'education']);
        actingOnTenant($education);
        $admin = memberOf(User::factory()->create(), $education, Role::MdaAdmin);
        actingOnTenant($education);

        $csv = exportedEvaluationCsv(
            Livewire::actingAs($admin)->test(EvaluationIndex::class)->instance()->export()
        );

        // Header row and the UTF-8 BOM, and not one data line: an empty
        // register must produce an empty file, never "everyone else's".
        expect(array_values(array_filter(explode("\n", trim($csv)))))->toHaveCount(1)
            ->and($csv)->toContain('Title');
    });

    it('matches nothing when a search term happens to name another entity’s evaluation', function () {
        $component = Livewire::actingAs($this->admin)
            ->test(EvaluationIndex::class)
            ->set('search', 'cottage hospital');

        // Search is the read that most often forgets its scope, because the
        // term comes from the user rather than from a filter list.
        expect($component->instance()->evaluations()->total())->toBe(0);
    });
});
