<?php

/**
 * The follow-up loop — the reason the evaluation module is worth building
 * (manual digest §7.9). An evaluation nobody acts on is a document; the only
 * thing separating the two is a register that can answer "what did we say, who
 * owns it, and did it happen".
 *
 * So the cases here are about the loop closing, in this order:
 *
 *  1. a recommendation is raised with an addressee and a deadline;
 *  2. it moves through the register, and "implemented" costs EVIDENCE;
 *  3. an overdue one is FINDABLE — on the register, in the stat row, in the
 *     export;
 *  4. the nightly sweep announces it exactly once, and running it twice
 *     announces nothing more. Idempotency is asserted by running the real
 *     Action a second time rather than by trusting the flag column.
 *
 * Every date is driven by Carbon::setTestNow(). Nothing here sleeps, and
 * nothing depends on what day the suite happens to run.
 */

use App\Actions\Evaluation\FlagOverdueRecommendations;
use App\Actions\Evaluation\RaiseRecommendation;
use App\Actions\Evaluation\TransitionRecommendationStatus;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Enums\Role;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Exceptions\Evaluation\InvalidEvaluationTransition;
use App\Livewire\Tenant\Evaluation\RecommendationIndex;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Evaluation\RecommendationOverdue;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    // A fixed "today" for the whole file: every deadline below is stated
    // relative to it, so a case cannot pass or fail on the calendar.
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(['name' => 'Bolanle Adewale']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Ifeoma Nnadi']), $this->works, Role::MeOfficer);
    $this->owner = memberOf(User::factory()->create(['name' => 'Segun Fashola']), $this->works, Role::MeOfficer);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);
    $this->evaluation = Evaluation::factory()->forProject($this->project)->midTerm()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Re-read a recommendation through the model, inside its own workspace. */
function recommendationNow(Recommendation $recommendation): Recommendation
{
    return app(CurrentTenant::class)->runAs(
        $recommendation->loadMissing('tenant')->tenant,
        fn (): Recommendation => Recommendation::query()->whereKey($recommendation->getKey())->firstOrFail(),
    );
}

/* -------------------------------------------------------------------------- */
/* Raising one */
/* -------------------------------------------------------------------------- */

it('puts a recommendation on the register, open, costed, timetabled and addressed', function () {
    $recommendation = app(RaiseRecommendation::class)($this->evaluation, $this->officer, [
        'title' => 'Re-sequence the drainage works ahead of the wet season',
        'body' => 'Drainage structures scheduled for Q4 should be brought forward.',
        'addressee_id' => $this->owner->id,
        'priority' => RecommendationPriority::High,
        'estimated_cost' => '1250000.00',
        'timeline' => 'Before the next rainy season',
        'due_on' => '2026-08-31',
    ]);

    expect($recommendation->status)->toBe(RecommendationStatus::Open)
        ->and($recommendation->raised_by_id)->toBe($this->officer->id)
        ->and($recommendation->raised_at)->not->toBeNull()
        // Provenance captured at raise time, not a live mirror: it is what
        // makes "every outstanding recommendation on this road" one indexed
        // query rather than a join across four possible source tables.
        ->and($recommendation->project_id)->toBe($this->project->id)
        ->and($recommendation->source_type)->toBe($this->evaluation->getMorphClass())
        ->and($recommendation->source_id)->toBe($this->evaluation->id)
        ->and($recommendation->estimated_cost?->toDecimalString())->toBe('1250000.00')
        ->and($recommendation->isOutstanding())->toBeTrue();
});

it('refuses a recommendation addressed to nobody', function () {
    // One addressed to nobody is the definition of one that will not be
    // implemented, and the overdue sweep would have nobody to chase.
    expect(fn () => app(RaiseRecommendation::class)($this->evaluation, $this->officer, [
        'title' => 'Something everyone agrees with and nobody owns',
        'body' => 'A finding with no addressee.',
        'priority' => RecommendationPriority::Medium,
    ]))->toThrow(EvaluationRuleViolation::class, 'needs an addressee');
});

it('accepts a named body where there is no account to address', function () {
    $recommendation = app(RaiseRecommendation::class)($this->evaluation, $this->officer, [
        'title' => 'Re-sequence the drainage works',
        'body' => 'Bring the Q4 drainage structures forward.',
        'addressee_body' => 'Directorate of Works',
        'priority' => RecommendationPriority::High,
    ]);

    expect($recommendation->addressee_id)->toBeNull()
        ->and($recommendation->addresseeLabel())->toBe('Directorate of Works');
});

it('refuses to hang a recommendation off a global reference row', function () {
    $sector = Sector::factory()->create(['name' => 'Transport']);

    // A recommendation belongs to one entity's follow-up register; a sector
    // belongs to the state. Checked by the column rather than by a class
    // allow-list, so a new module's tenant-owned source needs no change here.
    expect(fn () => app(RaiseRecommendation::class)($sector, $this->officer, [
        'title' => 'A finding against the whole transport sector',
        'body' => 'Raised against reference data rather than against a record.',
        'addressee_body' => 'Directorate of Works',
        'priority' => RecommendationPriority::Low,
    ]))->toThrow(EvaluationRuleViolation::class);
});

/* -------------------------------------------------------------------------- */
/* Moving one through the loop */
/* -------------------------------------------------------------------------- */

it('walks a recommendation from open to implemented, with evidence at the end', function () {
    $recommendation = Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->create();

    $transition = app(TransitionRecommendationStatus::class);

    $transition($recommendation, RecommendationStatus::Accepted, $this->owner);
    expect(recommendationNow($recommendation)->accepted_by_id)->toBe($this->owner->id);

    $transition($recommendation, RecommendationStatus::InProgress, $this->owner);
    expect(recommendationNow($recommendation)->status)->toBe(RecommendationStatus::InProgress);

    $transition(
        $recommendation,
        RecommendationStatus::Implemented,
        $this->owner,
        evidence: 'Revised works programme issued 14 May; drainage now precedes carriageway on all three sections.',
    );

    $done = recommendationNow($recommendation);

    expect($done->status)->toBe(RecommendationStatus::Implemented)
        ->and($done->implemented_by_id)->toBe($this->owner->id)
        ->and($done->implementation_evidence)->toContain('Revised works programme')
        ->and($done->isOutstanding())->toBeFalse();
});

it('refuses "implemented" with no evidence behind it', function () {
    // A register whose rows can be closed by pressing a button will read 100%
    // implemented within a year and mean nothing.
    $recommendation = Recommendation::factory()->from($this->evaluation)->accepted($this->owner)->create();

    expect(fn () => app(TransitionRecommendationStatus::class)(
        $recommendation,
        RecommendationStatus::Implemented,
        $this->owner,
        evidence: '   ',
    ))->toThrow(EvaluationRuleViolation::class);

    expect(recommendationNow($recommendation)->status)->toBe(RecommendationStatus::Accepted);
});

it('declines a recommendation when it lands, with a reason on the record', function () {
    $recommendation = Recommendation::factory()->from($this->evaluation)->open()->create();

    app(TransitionRecommendationStatus::class)(
        $recommendation,
        RecommendationStatus::Rejected,
        $this->admin,
        reason: 'No budget line exists for re-sequencing within the current appropriation.',
    );

    $declined = recommendationNow($recommendation);

    expect($declined->status)->toBe(RecommendationStatus::Rejected)
        ->and($declined->closure_reason)->toContain('No budget line');
});

it('refuses a quiet decline months after the recommendation was accepted', function () {
    // THE rule the table exists for: accepting and then dropping is precisely
    // the move the register makes visible. The honest record of it is a
    // supersession that names the replacement.
    $recommendation = Recommendation::factory()->from($this->evaluation)->accepted($this->owner)->create();

    expect(fn () => app(TransitionRecommendationStatus::class)(
        $recommendation,
        RecommendationStatus::Rejected,
        $this->admin,
        reason: 'On reflection we would rather not.',
    ))->toThrow(InvalidEvaluationTransition::class);
});

it('supersedes a recommendation only by naming the one that replaces it', function () {
    $original = Recommendation::factory()->from($this->evaluation)->open()->create([
        'title' => 'Re-sequence the drainage works',
    ]);

    $replacement = Recommendation::factory()->from($this->evaluation)->open()->create([
        'title' => 'Adopt a catchment-wide drainage master plan',
    ]);

    expect(fn () => app(TransitionRecommendationStatus::class)(
        $original,
        RecommendationStatus::Superseded,
        $this->admin,
        reason: 'Replaced by a wider drainage master-plan recommendation.',
    ))->toThrow(EvaluationRuleViolation::class);

    expect(fn () => app(TransitionRecommendationStatus::class)(
        $original,
        RecommendationStatus::Superseded,
        $this->admin,
        reason: 'Replaced by a wider drainage master-plan recommendation.',
        supersededBy: $original,
    ))->toThrow(EvaluationRuleViolation::class);

    app(TransitionRecommendationStatus::class)(
        $original,
        RecommendationStatus::Superseded,
        $this->admin,
        reason: 'Replaced by a wider drainage master-plan recommendation.',
        supersededBy: $replacement,
    );

    expect(recommendationNow($original)->superseded_by_id)->toBe($replacement->id);
});

it('refuses to reopen or rewind a closed recommendation', function (string $state, RecommendationStatus $to) {
    /** @var Recommendation $recommendation */
    $recommendation = Recommendation::factory()->from($this->evaluation)->{$state}()->create();

    expect(fn () => app(TransitionRecommendationStatus::class)(
        $recommendation,
        $to,
        $this->admin,
        reason: 'A stated reason, so the refusal is the chain and not the precondition.',
        evidence: 'Evidence, for the same reason.',
    ))->toThrow(InvalidEvaluationTransition::class);
})->with([
    'implemented reopened' => ['implemented', RecommendationStatus::Open],
    'implemented restarted' => ['implemented', RecommendationStatus::InProgress],
    'declined accepted later' => ['rejected', RecommendationStatus::Accepted],
    'superseded reopened' => ['superseded', RecommendationStatus::Open],
]);

/* -------------------------------------------------------------------------- */
/* Overdue: derived, never a stale column */
/* -------------------------------------------------------------------------- */

it('reads overdue from the deadline and the status, not from a stored flag', function () {
    $late = Recommendation::factory()->from($this->evaluation)->open()->dueIn(-10)->create();
    $soon = Recommendation::factory()->from($this->evaluation)->open()->dueIn(10)->create();
    $done = Recommendation::factory()->from($this->evaluation)->implemented($this->owner)->dueIn(-10)->create();
    $undated = Recommendation::factory()->from($this->evaluation)->open()->create(['due_on' => null]);

    expect($late->isOverdue())->toBeTrue()
        ->and($late->daysToDue())->toBe(-10)
        ->and($soon->isOverdue())->toBeFalse()
        ->and($soon->daysToDue())->toBe(10)
        // A closed row stops being chased.
        ->and($done->isOverdue())->toBeFalse()
        // No deadline means nothing to be late for — not "late since 1970".
        ->and($undated->isOverdue())->toBeFalse()
        ->and($undated->daysToDue())->toBeNull();

    // The SQL twin of isOverdue() must agree with it, row for row.
    expect(Recommendation::query()->overdue()->pluck('id')->all())->toBe([$late->id]);
});

it('turns overdue the day after the deadline passes, not on it', function () {
    $recommendation = Recommendation::factory()->from($this->evaluation)->open()->create(['due_on' => '2026-06-15']);

    // Due today is not yet late: the addressee has the day.
    expect($recommendation->isOverdue())->toBeFalse();

    Carbon::setTestNow(CarbonImmutable::parse('2026-06-16 00:05:00'));

    expect(recommendationNow($recommendation)->isOverdue())->toBeTrue()
        ->and(Recommendation::query()->overdue()->count())->toBe(1);
});

it('makes an overdue recommendation findable on the register, in the stat row and in the export', function () {
    Recommendation::factory()->from($this->evaluation)->open()->dueIn(-30)->create([
        'title' => 'Re-sequence the drainage works ahead of the wet season',
    ]);

    Recommendation::factory()->from($this->evaluation)->open()->dueIn(30)->create([
        'title' => 'Publish the quarterly beneficiary survey',
    ]);

    $component = Livewire::actingAs($this->officer)->test(RecommendationIndex::class);

    // The stat row answers "are we behind?", so it counts overdue separately
    // from outstanding and ignores the filter bar.
    expect($component->instance()->stats())
        ->toMatchArray(['outstanding' => 2, 'overdue' => 1, 'implemented' => 0, 'closed' => 0]);

    $component->set('overdue', true)
        ->assertSee('Re-sequence the drainage works ahead of the wet season')
        ->assertDontSee('Publish the quarterly beneficiary survey');

    expect($component->instance()->recommendations()->total())->toBe(1);

    ob_start();
    $component->instance()->export()->sendContent();
    $csv = (string) ob_get_clean();

    // The export carries the rows on screen under the filters in force — and
    // an explicit overdue column, because "implemented" with nothing beside it
    // is the document this module exists to prevent.
    expect($csv)->toContain('Re-sequence the drainage works ahead of the wet season')
        ->and($csv)->not->toContain('Publish the quarterly beneficiary survey');
});

it('orders the register most pressing first, longest ignored next', function () {
    Recommendation::factory()->from($this->evaluation)->open()
        ->priority(RecommendationPriority::Low)->dueIn(-60)->create(['title' => 'Low, very late']);

    Recommendation::factory()->from($this->evaluation)->open()
        ->priority(RecommendationPriority::Critical)->dueIn(-1)->create(['title' => 'Critical, just late']);

    Recommendation::factory()->from($this->evaluation)->open()
        ->priority(RecommendationPriority::Critical)->dueIn(-20)->create(['title' => 'Critical, long late']);

    $titles = Livewire::actingAs($this->officer)
        ->test(RecommendationIndex::class)
        ->instance()
        ->recommendations()
        ->pluck('title')
        ->all();

    // A board sorted by created-at tells a commissioner what was written most
    // recently, which is the one question they are not asking.
    expect($titles)->toBe(['Critical, long late', 'Critical, just late', 'Low, very late']);
});

/* -------------------------------------------------------------------------- */
/* The nightly sweep */
/* -------------------------------------------------------------------------- */

it('announces an overdue recommendation once, to its owner and to the entity admin', function () {
    $recommendation = Recommendation::factory()
        ->from($this->evaluation)
        ->addressedTo($this->owner)
        ->open()
        ->dueIn(-10)
        ->create();

    $this->current->forget();

    $totals = app(FlagOverdueRecommendations::class)();

    expect($totals['flagged'])->toBe(1)
        ->and(recommendationNow($recommendation)->overdue_flagged_at)->not->toBeNull();

    // Both, not just the addressee: someone accountable for the register has
    // to learn of a silent addressee without running a report.
    Notification::assertSentTo($this->owner, RecommendationOverdue::class);
    Notification::assertSentTo($this->admin, RecommendationOverdue::class);
});

it('is idempotent — running the sweep twice announces nothing more', function () {
    Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->open()->dueIn(-10)->create();

    $this->current->forget();

    $first = app(FlagOverdueRecommendations::class)();
    $second = app(FlagOverdueRecommendations::class)();

    // Idempotency is structural: `overdue_flagged_at` is a null-check gate
    // advanced under the SAME row lock as the dispatch, so a double cron run,
    // an overlapping worker or a replayed job cannot double-send.
    expect($first['flagged'])->toBe(1)
        ->and($second['flagged'])->toBe(0);

    Notification::assertSentToTimes($this->owner, RecommendationOverdue::class, 1);
    Notification::assertSentToTimes($this->admin, RecommendationOverdue::class, 1);
});

it('stays silent about a recommendation it has already announced', function () {
    Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->alreadyFlagged()->create();

    $this->current->forget();

    expect(app(FlagOverdueRecommendations::class)()['flagged'])->toBe(0);

    Notification::assertNothingSentTo($this->owner);
});

it('stays silent about a recommendation that was closed before the sweep ran', function (string $state) {
    /** @var Recommendation $recommendation */
    $recommendation = Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->{$state}()->create();
    $recommendation->forceFill(['due_on' => CarbonImmutable::now()->subDays(30)->toDateString()])->save();

    $this->current->forget();

    // A recommendation somebody declined with a reason does not keep nagging
    // them, and neither does one that was actually done.
    expect(app(FlagOverdueRecommendations::class)()['flagged'])->toBe(0);

    Notification::assertNothingSentTo($this->owner);
})->with([
    'implemented' => ['implemented'],
    'declined' => ['rejected'],
    'superseded' => ['superseded'],
]);

it('stays silent about a recommendation with no deadline at all', function () {
    Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->open()->create(['due_on' => null]);

    $this->current->forget();

    expect(app(FlagOverdueRecommendations::class)()['flagged'])->toBe(0);
});

it('sweeps every entity and stamps each notice inside its own workspace', function () {
    Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->open()->dueIn(-5)->create();

    $healthOwner = $this->current->runAs($this->health, function (): User {
        $owner = memberOf(User::factory()->create(), $this->health, Role::MeOfficer);

        actingOnTenant($this->health);

        $project = Project::factory()->ongoing()->create();
        $evaluation = Evaluation::factory()->forProject($project)->create();

        Recommendation::factory()->from($evaluation)->addressedTo($owner)->open()->dueIn(-5)->create();

        return $owner;
    });

    $this->current->forget();

    expect(app(FlagOverdueRecommendations::class)()['flagged'])->toBe(2);

    Notification::assertSentTo($this->owner, RecommendationOverdue::class);
    Notification::assertSentTo($healthOwner, RecommendationOverdue::class);

    // The entity admin of Works must not hear about Health's register.
    Notification::assertSentToTimes($this->admin, RecommendationOverdue::class, 1);
});

it('skips an inactive entity entirely', function () {
    Recommendation::factory()->from($this->evaluation)->addressedTo($this->owner)->open()->dueIn(-5)->create();

    $this->current->forget();
    $this->works->forceFill(['is_active' => false])->save();

    expect(app(FlagOverdueRecommendations::class)()['flagged'])->toBe(0);
});
