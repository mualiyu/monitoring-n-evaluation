<?php

/**
 * The life of one figure: draft → submitted → validated → published, and the
 * rejection loop back to draft (App\Enums\IndicatorReadingStatus,
 * App\Actions\Indicators\TransitionIndicatorReadingStatus).
 *
 * Every allowed transition is driven end to end with the actor the design
 * names for it, and the forbidden ones are asserted individually, because
 * what each of them would let somebody do is different:
 *
 *  - draft → validated skips the reviewer entirely;
 *  - submitted → published skips data-quality review;
 *  - published → anything would let a figure that has been quoted outside the
 *    platform be rewritten after the fact.
 *
 * Chain stamps are asserted alongside the status, because a screen reads the
 * stamps: a row that is once again a draft while still carrying `validated_at`
 * is a screen claiming a rejected number cleared review.
 *
 * The transition Action is cross-surface by construction — the reviewer works
 * a cross-MDA queue with NO tenant bound, and the write re-enters the
 * reading's own workspace. Both contexts are exercised here on purpose.
 */

use App\Actions\Indicators\RecordIndicatorReading;
use App\Actions\Indicators\TransitionIndicatorReadingStatus;
use App\Enums\IndicatorReadingStatus;
use App\Enums\ReadingSourceType;
use App\Enums\Role;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Exceptions\Indicators\InvalidReadingTransition;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorReadingEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    actingOnTenant($this->works);

    $this->indicator = Indicator::factory()->active()->create([
        'name' => 'Boreholes commissioned and handed over',
    ]);

    $this->current->forget();

    // The assurance side lives on the oversight surface, with no tenant bound.
    $this->reviewer = userWithRole(Role::DataQualityReviewer);
    $this->publisher = userWithRole(Role::StateAdmin);

    $this->current->forget();
});

/** Re-read a reading through the model, inside its own workspace. */
function reloadReading(IndicatorReading $reading): IndicatorReading
{
    return app(CurrentTenant::class)->runAs(
        $reading->loadMissing('tenant')->tenant,
        fn (): IndicatorReading => IndicatorReading::query()->whereKey($reading->getKey())->firstOrFail(),
    );
}

/** The ledger for a reading, oldest first. */
function readingLedger(IndicatorReading $reading): array
{
    return app(CurrentTenant::class)->runAs(
        $reading->loadMissing('tenant')->tenant,
        fn (): array => IndicatorReadingEvent::query()
            ->where('indicator_reading_id', $reading->id)
            ->orderBy('id')
            ->get()
            ->map(fn (IndicatorReadingEvent $event): array => [
                $event->from_status?->value,
                $event->to_status->value,
                $event->actor_id,
            ])
            ->all(),
    );
}

/* -------------------------------------------------------------------------- */
/* Capture: the figure starts as a draft and nowhere else */
/* -------------------------------------------------------------------------- */

it('records a captured figure as a draft, stamped with whoever measured it', function () {
    actingOnTenant($this->works);

    $reading = (new RecordIndicatorReading)($this->indicator, [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'actual_value' => '37.0000',
        'source_type' => ReadingSourceType::Primary,
        'collection_method' => 'Verified against handover certificates.',
    ], $this->officer);

    // Capture stops at draft: submission is a separate act with a separate
    // consequence, which is what keeps the transition Action the only writer
    // of `status`.
    expect($reading->status)->toBe(IndicatorReadingStatus::Draft)
        ->and($reading->recorded_by_id)->toBe($this->officer->id)
        ->and($reading->submitted_by_id)->toBeNull()
        // The ledger starts where the figure did, not at its first move.
        ->and(readingLedger($reading))->toBe([[null, 'draft', $this->officer->id]]);
});

it('refuses a figure against an indicator with no agreed baseline yet', function () {
    actingOnTenant($this->works);

    $draftIndicator = Indicator::factory()->withoutBaseline()->create(['name' => 'Wells rehabilitated']);

    expect(fn () => (new RecordIndicatorReading)($draftIndicator, [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'actual_value' => '10.0000',
        'source_type' => ReadingSourceType::Primary,
    ], $this->officer))->toThrow(IndicatorRuleViolation::class, 'not active');
});

it('refuses a second live figure for a period that already has one', function () {
    actingOnTenant($this->works);

    $attributes = [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'actual_value' => '37.0000',
        'source_type' => ReadingSourceType::Primary,
    ];

    (new RecordIndicatorReading)($this->indicator, $attributes, $this->officer);

    // Two actuals for one period is how the same delivery gets counted twice
    // in the annual consolidation.
    expect(fn () => (new RecordIndicatorReading)($this->indicator, $attributes, $this->officer))
        ->toThrow(IndicatorRuleViolation::class, 'already has a live reading');
});

it('refuses a measurement period that ends before it starts', function () {
    actingOnTenant($this->works);

    expect(fn () => (new RecordIndicatorReading)($this->indicator, [
        'period_start' => '2026-03-31',
        'period_end' => '2026-01-01',
        'actual_value' => '37.0000',
        'source_type' => ReadingSourceType::Primary,
    ], $this->officer))->toThrow(IndicatorRuleViolation::class);
});

it('lets the recorder correct their own draft, and freezes it at submission', function () {
    actingOnTenant($this->works);

    $reading = (new RecordIndicatorReading)($this->indicator, [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'actual_value' => '37.0000',
        'source_type' => ReadingSourceType::Primary,
    ], $this->officer);

    (new RecordIndicatorReading)($this->indicator, [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'actual_value' => '41.0000',
        'source_type' => ReadingSourceType::Primary,
    ], $this->officer, $reading);

    expect(reloadReading($reading)->actual_value)->toBe('41.0000');

    (new TransitionIndicatorReadingStatus)($reading, IndicatorReadingStatus::Submitted, $this->officer);

    // A figure freezes at submission: everything past draft is corrected by a
    // reviewer sending it back, never by a quiet edit.
    expect(fn () => (new RecordIndicatorReading)($this->indicator, [
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'actual_value' => '99.0000',
        'source_type' => ReadingSourceType::Primary,
    ], $this->officer, reloadReading($reading)))->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* Every allowed transition, with the actor the design names for it */
/* -------------------------------------------------------------------------- */

it('walks a figure the whole way from capture to publication', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->consultant)
        ->ofValue('37.0000')
        ->create();

    $transition = new TransitionIndicatorReadingStatus;

    // 1. The consultant who measured it files it.
    $transition($reading, IndicatorReadingStatus::Submitted, $this->consultant);
    expect(reloadReading($reading)->status)->toBe(IndicatorReadingStatus::Submitted);

    // 2. The Data Quality Reviewer clears it — from the oversight surface,
    //    with no tenant bound, which is the context that desk actually runs in.
    $this->current->forget();
    $transition($reading, IndicatorReadingStatus::Validated, $this->reviewer);

    $validated = reloadReading($reading);
    expect($validated->status)->toBe(IndicatorReadingStatus::Validated)
        ->and($validated->validated_by_id)->toBe($this->reviewer->id)
        ->and($validated->validated_at)->not->toBeNull();

    // 3. Publication is a further explicit act — nothing reaches a public
    //    surface merely by having been checked.
    $this->current->forget();
    $transition($reading, IndicatorReadingStatus::Published, $this->publisher);

    $published = reloadReading($reading);
    expect($published->status)->toBe(IndicatorReadingStatus::Published)
        ->and($published->published_by_id)->toBe($this->publisher->id)
        // The ledger holds the whole history, in order, with who did what.
        ->and(readingLedger($reading))->toBe([
            ['draft', 'submitted', $this->consultant->id],
            ['submitted', 'validated', $this->reviewer->id],
            ['validated', 'published', $this->publisher->id],
        ]);
});

it('sends a submitted figure back to the person who measured it, with a reason', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->officer)
        ->submitted($this->officer)
        ->create();

    $this->current->forget();

    (new TransitionIndicatorReadingStatus)(
        $reading,
        IndicatorReadingStatus::Draft,
        $this->reviewer,
        'The figure counts households reached rather than households with a working connection.',
    );

    $rejected = reloadReading($reading);

    expect($rejected->status)->toBe(IndicatorReadingStatus::Draft)
        ->and($rejected->rejected_by_id)->toBe($this->reviewer->id)
        ->and($rejected->rejection_reason)->toContain('working connection')
        // The submission stamps survive — who filed it last is still true,
        // and it is what the separation guard weighs next time round.
        ->and($rejected->submitted_by_id)->toBe($this->officer->id);
});

it('pulls a validated figure back before publication, clearing the validation it undoes', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->officer)
        ->validated($this->reviewer)
        ->create();

    $this->current->forget();

    // The reviewer who finds the error at 16:00 must not have to publish it
    // first in order to correct it.
    (new TransitionIndicatorReadingStatus)(
        $reading,
        IndicatorReadingStatus::Draft,
        $this->reviewer,
        'The denominator is the 2019 projection, not the 2026 one.',
    );

    $pulled = reloadReading($reading);

    expect($pulled->status)->toBe(IndicatorReadingStatus::Draft)
        // Leaving validated_at behind on a row that is once again a draft is
        // how a screen ends up claiming a rejected number cleared review.
        ->and($pulled->validated_by_id)->toBeNull()
        ->and($pulled->validated_at)->toBeNull();
});

it('clears the rejection stamps when the corrected figure comes back round', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->officer)
        ->rejected($this->reviewer)
        ->create();

    actingOnTenant($this->works);

    (new TransitionIndicatorReadingStatus)($reading, IndicatorReadingStatus::Submitted, $this->officer);

    $resubmitted = reloadReading($reading);

    // A resubmission is a fresh answer to the rejection: the reason has served
    // its purpose and is preserved in the ledger, not on the row.
    expect($resubmitted->status)->toBe(IndicatorReadingStatus::Submitted)
        ->and($resubmitted->rejected_by_id)->toBeNull()
        ->and($resubmitted->rejected_at)->toBeNull()
        ->and($resubmitted->rejection_reason)->toBeNull()
        ->and($resubmitted->submitted_by_id)->toBe($this->officer->id);
});

it('refuses a rejection with no stated reason', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()->forIndicator($this->indicator)->submitted()->create();

    $this->current->forget();

    // The person who measured it has to know what to fix, and the rejection
    // goes on the data-quality record.
    expect(fn () => (new TransitionIndicatorReadingStatus)(
        $reading,
        IndicatorReadingStatus::Draft,
        $this->reviewer,
        '   ',
    ))->toThrow(IndicatorRuleViolation::class, 'stated reason');

    expect(reloadReading($reading)->status)->toBe(IndicatorReadingStatus::Submitted);
});

/* -------------------------------------------------------------------------- */
/* The forbidden moves */
/* -------------------------------------------------------------------------- */

it('refuses a move the chain table does not allow, before any permission is considered', function (
    string $state,
    IndicatorReadingStatus $to,
) {
    actingOnTenant($this->works);

    /** @var IndicatorReading $reading */
    $reading = IndicatorReading::factory()->forIndicator($this->indicator)->{$state}()->create();

    $this->current->forget();

    // The actor here holds every relevant permission there is. An impossible
    // move is impossible for everyone.
    expect(fn () => (new TransitionIndicatorReadingStatus)($reading, $to, $this->publisher, 'A stated reason.'))
        ->toThrow(InvalidReadingTransition::class);
})->with([
    'a draft cannot skip the reviewer' => ['draft', IndicatorReadingStatus::Validated],
    'a draft cannot be published outright' => ['draft', IndicatorReadingStatus::Published],
    'a submitted figure cannot skip data-quality review' => ['submitted', IndicatorReadingStatus::Published],
    'a published figure cannot be un-published' => ['published', IndicatorReadingStatus::Validated],
    'a published figure cannot be sent back' => ['published', IndicatorReadingStatus::Draft],
    'a published figure cannot be re-submitted' => ['published', IndicatorReadingStatus::Submitted],
    'a validated figure cannot be re-submitted' => ['validated', IndicatorReadingStatus::Submitted],
]);

it('refuses a second attempt at a move the record has already made', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()->forIndicator($this->indicator)->recordedBy($this->officer)->create();

    (new TransitionIndicatorReadingStatus)($reading, IndicatorReadingStatus::Submitted, $this->officer);

    // Two officers pressing the same button in the same second must produce
    // one submission and one refusal.
    expect(fn () => (new TransitionIndicatorReadingStatus)(
        reloadReading($reading),
        IndicatorReadingStatus::Submitted,
        $this->officer,
    ))->toThrow(InvalidReadingTransition::class);
});

/* -------------------------------------------------------------------------- */
/* The ledger is append-only */
/* -------------------------------------------------------------------------- */

it('keeps the validation ledger append-only', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()->forIndicator($this->indicator)->create();
    $event = IndicatorReadingEvent::factory()->forReading($reading)->create();

    // A validation step is corrected by recording another one; audit records
    // are retained, never edited or deleted.
    expect(fn () => $event->update(['reason' => 'rewritten']))->toThrow(RuntimeException::class)
        ->and(fn () => $event->delete())->toThrow(RuntimeException::class);
});

it('writes the ledger row into the entity that owns the figure, not the reviewer’s context', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->officer)
        ->submitted($this->officer)
        ->create();

    // No tenant bound — the reviewer's real working context.
    $this->current->forget();

    (new TransitionIndicatorReadingStatus)($reading, IndicatorReadingStatus::Validated, $this->reviewer);

    $events = $this->current->runAs(
        $this->works,
        fn () => IndicatorReadingEvent::query()->where('indicator_reading_id', $reading->id)->get(),
    );

    expect($events)->toHaveCount(1)
        ->and($events->first()->tenant_id)->toBe($this->works->id);
});
