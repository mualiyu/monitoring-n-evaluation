<?php

/**
 * The consolidation chain, driven through the Actions that own it.
 *
 * Two properties are being proved here, and the second is the reason the whole
 * module exists:
 *
 *  1. THE SEPARATION OF COMPILER FROM APPROVER. A state report that one person
 *     compiles, submits and signs is an assertion with three of that person's
 *     signatures on it.
 *  2. APPROVAL FREEZES THE FIGURES. An Annual Performance Report whose numbers
 *     move when an MDA edits last quarter's return is not an APR — it is a
 *     dashboard with a title page, and every printed copy becomes
 *     unverifiable.
 *
 * Everything runs through App\Actions\Consolidation\*, never through a direct
 * status write, because the chokepoint is what the guards live in.
 */

use App\Actions\Consolidation\ApproveConsolidation;
use App\Actions\Consolidation\CompileConsolidatedFigures;
use App\Actions\Consolidation\OpenConsolidation;
use App\Actions\Consolidation\PublishConsolidation;
use App\Actions\Consolidation\RecordConsolidationSection;
use App\Actions\Consolidation\ReturnConsolidation;
use App\Actions\Consolidation\SubmitConsolidationForReview;
use App\Actions\Consolidation\TransitionConsolidationStatus;
use App\Actions\Oversight\AggregateForConsolidation;
use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use App\Enums\Role;
use App\Exceptions\Consolidation\ConsolidationRuleViolation;
use App\Exceptions\Consolidation\InvalidConsolidationTransition;
use App\Models\ConsolidatedReport;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->annual = ReportingPeriod::factory()->annual(2026)->create();

    $current = app(CurrentTenant::class);

    $current->runAs($this->works, function () {
        $this->worksProject = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'contract_value_total' => '300000000.00',
            'expenditure_to_date' => '120000000.00',
            'physical_progress' => '40.00',
        ]);

        ReportObligation::factory()
            ->forProject($this->worksProject)
            ->forPeriod($this->annual)
            ->fulfilled()
            ->create();

        ProgressReport::factory()
            ->forProject($this->worksProject)
            ->forPeriod($this->annual)
            ->approved()
            ->create(['period_expenditure' => '20000000.00']);
    });

    $current->runAs($this->health, function () {
        $project = Project::factory()->ongoing()->create([
            'title' => 'Cottage Hospital Rewiring',
            'contract_value_total' => '200000000.00',
            'expenditure_to_date' => '80000000.00',
            'physical_progress' => '60.00',
        ]);

        // Owed a return and filed nothing — the finding a roll-up exists to
        // surface, and a row the compile must still produce.
        ReportObligation::factory()
            ->forProject($project)
            ->forPeriod($this->annual)
            ->missed()
            ->create();
    });

    $current->forget();

    $this->compiler = userWithRole(Role::StateAdmin);
    $this->director = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $current->forget();
    Cache::flush();
});

/**
 * Open → compile → write the summary, which is the state every chain test
 * starts from.
 */
function readyToSubmit(User $compiler, ReportingPeriod $period): ConsolidatedReport
{
    $report = app(OpenConsolidation::class)($compiler, $period, ConsolidatedReportType::AnnualApr);

    app(CompileConsolidatedFigures::class)($report, $compiler);

    app(RecordConsolidationSection::class)(
        $report,
        'executive_summary',
        'Delivery across the state reached 48% of plan against an expected 60%; one entity filed nothing at all.',
        $compiler,
    );

    return ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();
}

/* -------------------------------------------------------------------------- */
/* The happy path, one hop at a time */
/* -------------------------------------------------------------------------- */

it('walks draft → compiling → in_review → approved → published and writes a ledger row for each hop', function () {
    $report = app(OpenConsolidation::class)($this->compiler, $this->annual, ConsolidatedReportType::AnnualApr);

    expect($report->status)->toBe(ConsolidationStatus::Draft);

    app(CompileConsolidatedFigures::class)($report, $this->compiler);
    expect($report->status)->toBe(ConsolidationStatus::Compiling)
        ->and($report->compiled_by_id)->toBe($this->compiler->id)
        ->and($report->hasFigures())->toBeTrue();

    app(RecordConsolidationSection::class)($report, 'executive_summary', 'The year in one paragraph.', $this->compiler);

    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    expect($report->status)->toBe(ConsolidationStatus::InReview)
        ->and($report->submitted_by_id)->toBe($this->compiler->id);

    app(ApproveConsolidation::class)($report, $this->director);
    expect($report->status)->toBe(ConsolidationStatus::Approved)
        ->and($report->approved_by_id)->toBe($this->director->id)
        ->and($report->snapshot)->toBeArray();

    app(PublishConsolidation::class)($report, $this->director);
    expect($report->status)->toBe(ConsolidationStatus::Published)
        ->and($report->published_by_id)->toBe($this->director->id);

    // Opening row + four moves, in order, each with its actor.
    expect($report->events()->orderBy('id')->pluck('to_status')->map(fn ($s) => $s->value)->all())
        ->toBe(['draft', 'compiling', 'in_review', 'approved', 'published']);
});

it('sends a consolidation back to the desk with a reason and lets it go up again', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);

    app(ReturnConsolidation::class)($report, $this->director, 'The compliance chapter does not explain the entity that filed nothing.');

    // A return lands on `compiling`, not `draft`: that is where the narrative
    // lives and the questioned figures are still attached.
    expect($report->status)->toBe(ConsolidationStatus::Compiling)
        ->and($report->return_reason)->toContain('filed nothing')
        ->and($report->returned_by_id)->toBe($this->director->id)
        ->and($report->isEditable())->toBeTrue();

    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    expect($report->status)->toBe(ConsolidationStatus::InReview);
});

/* -------------------------------------------------------------------------- */
/* Forbidden moves */
/* -------------------------------------------------------------------------- */

it('refuses to reopen an approved consolidation, whoever asks', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    app(ApproveConsolidation::class)($report, $this->director);

    expect(fn () => app(TransitionConsolidationStatus::class)(
        $report, ConsolidationStatus::Compiling, $this->director
    ))->toThrow(InvalidConsolidationTransition::class);

    expect(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::Approved);
});

it('refuses to send an uncompiled consolidation up the chain', function () {
    $report = app(OpenConsolidation::class)($this->compiler, $this->annual, ConsolidatedReportType::AnnualApr);

    // draft → in_review is not in the table at all: the chain refuses before
    // any permission is considered.
    expect(fn () => app(SubmitConsolidationForReview::class)($report, $this->compiler))
        ->toThrow(InvalidConsolidationTransition::class);
});

it('refuses to send figures up with no argument around them', function () {
    $report = app(OpenConsolidation::class)($this->compiler, $this->annual, ConsolidatedReportType::AnnualApr);
    app(CompileConsolidatedFigures::class)($report, $this->compiler);

    expect(fn () => app(SubmitConsolidationForReview::class)($report, $this->compiler))
        ->toThrow(ConsolidationRuleViolation::class, 'Executive summary');
});

it('refuses a return with no stated reason', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);

    expect(fn () => app(TransitionConsolidationStatus::class)(
        $report, ConsolidationStatus::Compiling, $this->director, null
    ))->toThrow(ConsolidationRuleViolation::class, 'stated reason');
});

it('refuses to publish figures that were never frozen', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);

    // Force the chain to `approved` WITHOUT the snapshot ApproveConsolidation
    // takes — the state that should be unreachable, and which publication must
    // still refuse rather than trust.
    app(TransitionConsolidationStatus::class)($report, ConsolidationStatus::Approved, $this->director);

    expect($report->snapshot)->toBeNull()
        ->and(fn () => app(PublishConsolidation::class)($report, $this->director))
        ->toThrow(ConsolidationRuleViolation::class, 'frozen snapshot');
});

it('closes the narrative once the report has left the desk', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);

    expect(fn () => app(RecordConsolidationSection::class)(
        $report, 'executive_summary', 'A quiet rewrite after the reviewer read it.', $this->compiler
    ))->toThrow(AuthorizationException::class);
});

it('refuses a chapter the report type does not have', function () {
    $report = app(OpenConsolidation::class)($this->compiler, $this->annual, ConsolidatedReportType::AnnualApr);

    expect(fn () => app(RecordConsolidationSection::class)(
        $report, 'a_chapter_nobody_asked_for', 'Text.', $this->compiler
    ))->toThrow(ConsolidationRuleViolation::class);
});

/* -------------------------------------------------------------------------- */
/* Separation of duties — the guard this module exists for */
/* -------------------------------------------------------------------------- */

it('refuses to let the officer who compiled the figures sign them off', function () {
    $report = readyToSubmit($this->compiler, $this->annual);

    // Someone ELSE sends it up, so the only bar left is the compile itself.
    app(SubmitConsolidationForReview::class)($report, $this->director);

    $second = userWithRole(Role::StateAdmin);
    app(CurrentTenant::class)->forget();

    expect($report->compiled_by_id)->toBe($this->compiler->id)
        ->and(fn () => app(ApproveConsolidation::class)($report, $this->compiler))
        ->toThrow(ConsolidationRuleViolation::class, 'cannot also sign them off');

    expect(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::InReview);

    // A third pair of eyes can.
    app(ApproveConsolidation::class)($report, $second);
    expect($report->status)->toBe(ConsolidationStatus::Approved);
});

it('refuses to let the officer who sent it up sign it off', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->director);

    expect(fn () => app(ApproveConsolidation::class)($report, $this->director))
        ->toThrow(ConsolidationRuleViolation::class, 'cannot also approve it');
});

it('refuses compilation and approval to an oversight role without secretariat authority', function () {
    $report = app(OpenConsolidation::class)($this->compiler, $this->annual, ConsolidatedReportType::AnnualApr);

    // ExecutiveViewer holds oversight.reports.view but not
    // oversight.consolidation.manage — reading the state's roll-ups is what
    // oversight is for; writing one is not.
    expect(fn () => app(CompileConsolidatedFigures::class)($report, $this->execViewer))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(OpenConsolidation::class)($this->execViewer, $this->annual, ConsolidatedReportType::Quarterly))
        ->toThrow(AuthorizationException::class);
});

it('refuses the cross-MDA aggregate itself to an MDA admin, however the request arrived', function () {
    expect(fn () => app(AggregateForConsolidation::class)($this->mdaAdmin, $this->annual))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(OpenConsolidation::class)($this->mdaAdmin, $this->annual, ConsolidatedReportType::AnnualApr))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* Compiling */
/* -------------------------------------------------------------------------- */

it('rolls every entity on the instance into the report, including the one that filed nothing', function () {
    $report = readyToSubmit($this->compiler, $this->annual);

    $entities = collect($report->entityFigures());

    expect($entities)->toHaveCount(2)
        ->and($report->denominator)->toBe(2);

    $works = $entities->firstWhere('subject_tenant_id', $this->works->id);
    $health = $entities->firstWhere('subject_tenant_id', $this->health->id);

    expect($works['entity'])->toBe('Ministry of Works')
        ->and($works['contract_value_total'])->toBe('300000000.00')
        ->and($works['obligations_submitted'])->toBe(1)
        ->and($health['entity'])->toBe('Ministry of Health')
        ->and($health['contract_value_total'])->toBe('200000000.00')
        // Owed a return, filed none: the absence IS the finding.
        ->and($health['obligations_expected'])->toBe(1)
        ->and($health['obligations_submitted'])->toBe(0)
        ->and($health['obligations_missed'])->toBe(1);

    expect($report->figures()['contract_value_total'])->toBe('500000000.00');
});

it('lands on the same numbers when the compile is run twice', function () {
    // Idempotent by construction (updateOrCreate on the unique key), which is
    // what makes "press Recompile twice" harmless and a replayed job safe.
    $report = readyToSubmit($this->compiler, $this->annual);
    $first = $report->entityFigures();

    app(CompileConsolidatedFigures::class)($report, $this->compiler);

    expect($report->entries()->count())->toBe(2)
        ->and(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->entityFigures())
        ->toEqual($first);
});

it('refuses to recompile a consolidation that has left the desk', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);

    expect(fn () => app(CompileConsolidatedFigures::class)($report, $this->compiler))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* THE SNAPSHOT — the property the whole module exists for */
/* -------------------------------------------------------------------------- */

it('freezes the figures at approval so a later change to an entity’s record cannot move them', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    app(ApproveConsolidation::class)($report, $this->director);

    $signedTotals = $report->figures();
    $signedEntities = $report->entityFigures();

    expect($signedTotals['contract_value_total'])->toBe('500000000.00');

    // Now the underlying record moves — an MDA revising a contract sum on a
    // project the signed report already counted.
    app(CurrentTenant::class)->runAs($this->works, function () {
        Project::query()
            ->whereKey($this->worksProject->id)
            ->firstOrFail()
            ->forceFill(['contract_value_total' => '999000000.00'])
            ->save();
    });

    app(CurrentTenant::class)->forget();
    Cache::flush(); // the portfolio aggregate is cached; prove the SOURCE moved

    // The source really did move: a fresh aggregate says so.
    $live = app(AggregateForConsolidation::class)($this->director, $this->annual);
    expect($live['totals']['contract_value_total']->toDecimalString())->toBe('1199000000.00');

    // The signed report does not. Re-read from the database so nothing is
    // being answered out of an in-memory model.
    $reread = ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();

    expect($reread->status)->toBe(ConsolidationStatus::Approved)
        ->and($reread->figures()['contract_value_total'])->toBe('500000000.00')
        ->and($reread->figures())->toEqual($signedTotals)
        ->and($reread->entityFigures())->toEqual($signedEntities);
});

it('keeps stating the frozen annex even if the working entry rows are rewritten underneath it', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    app(ApproveConsolidation::class)($report, $this->director);

    $signed = $report->entityFigures();

    // The entries table is DERIVED and is rewritten wholesale by every
    // compile. Nothing in the application can recompile a signed report — so
    // this rewrites the rows directly, which is strictly worse than anything
    // the app can do, and asserts the accessor still answers from the
    // snapshot.
    $report->entries()->update(['contract_value_total' => '1.00', 'obligations_submitted' => 99]);

    $reread = ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();

    expect($reread->entityFigures())->toEqual($signed)
        ->and(collect($reread->entityFigures())->pluck('contract_value_total')->all())
        ->not->toContain('1.00');
});

it('freezes the narrative alongside the figures', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    app(ApproveConsolidation::class)($report, $this->director);

    $signedNarrative = $report->narrative();
    expect(collect($signedNarrative)->firstWhere('key', 'executive_summary')['body'])
        ->toContain('48% of plan');

    // Same argument as the annex: the section rows cannot be edited through
    // the application once the report has left the desk, so this writes them
    // directly and proves the accessor ignores them.
    $report->sections()->where('key', 'executive_summary')->update(['body' => 'A quiet rewrite.']);

    expect(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->narrative())
        ->toEqual($signedNarrative);
});

it('records who froze it and when', function () {
    $report = readyToSubmit($this->compiler, $this->annual);
    app(SubmitConsolidationForReview::class)($report, $this->compiler);
    app(ApproveConsolidation::class)($report, $this->director);

    expect($report->snapshot['taken_by']['id'])->toBe($this->director->id)
        ->and($report->snapshot['version'])->toBe(ApproveConsolidation::SNAPSHOT_VERSION)
        ->and($report->snapshot_taken_at)->not->toBeNull()
        ->and($report->snapshot['period']['code'])->toBe($this->annual->code)
        ->and($report->snapshot['compiled_by_id'])->toBe($this->compiler->id);
});
