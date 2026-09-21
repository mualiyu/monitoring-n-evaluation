<?php

namespace App\Actions\Indicators;

use App\Enums\IndicatorReadingStatus;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Captures (or corrects) a DRAFT actual for one period. Submission is a
 * separate act — this Action never moves a figure past `draft`, which is what
 * keeps TransitionIndicatorReadingStatus the only writer of `status`.
 *
 * `recorded_by_id` is stamped here and nowhere else: it is the identity the
 * data-quality separation weighs later, so it has to be the person who
 * actually entered the measurement rather than whoever last touched the row.
 *
 * A figure is only accepted against an ACTIVE indicator. An indicator without
 * an agreed baseline has nothing to measure movement from, so a reading
 * against it is a number with no meaning (design: ActivateIndicator).
 */
class RecordIndicatorReading
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(
        Indicator $indicator,
        array $attributes,
        User $actor,
        ?IndicatorReading $existing = null,
    ): IndicatorReading {
        if ($existing === null) {
            Gate::forUser($actor)->authorize('create', IndicatorReading::class);
        } else {
            Gate::forUser($actor)->authorize('update', $existing);
        }

        if (! $indicator->is_active) {
            throw IndicatorRuleViolation::indicatorNotActive($indicator->name);
        }

        if ($existing !== null && ! $existing->status->isEditable()) {
            throw IndicatorRuleViolation::readingNotEditable($existing->status);
        }

        $start = Carbon::parse((string) $attributes['period_start']);
        $end = Carbon::parse((string) $attributes['period_end']);

        if ($end->lessThan($start)) {
            throw IndicatorRuleViolation::periodInverted();
        }

        return DB::transaction(function () use ($indicator, $attributes, $actor, $existing, $start, $end): IndicatorReading {
            $this->assertPeriodIsFree($indicator, $start, $end, $existing);

            $payload = [
                'indicator_id' => $indicator->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'actual_value' => (string) $attributes['actual_value'],
                'source_type' => $attributes['source_type'],
                'collection_method' => $attributes['collection_method'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'recorded_by_id' => $actor->id,
            ];

            if ($existing !== null) {
                $existing->update($payload);

                return $existing;
            }

            $reading = IndicatorReading::query()->create($payload);

            // Not fillable: `status` belongs to TransitionIndicatorReadingStatus.
            // Stating the opening value here rather than leaning on the column
            // default means the in-memory model is never a null status waiting
            // for a re-read to become real — the ledger row below reads it, and
            // so does every caller of this Action. (Same reason
            // RaiseRecommendation states its opening status explicitly.)
            $reading->forceFill(['status' => IndicatorReadingStatus::Draft])->save();

            // The ledger starts where the figure did, not at its first move.
            (new TransitionIndicatorReadingStatus)->recordCreation($reading, $actor);

            return $reading;
        });
    }

    /**
     * One live actual per indicator per period. Two actuals for one period is
     * how the same delivery gets counted twice in a consolidation, and the
     * manual's whole annual return is a consolidation.
     *
     * The check runs inside the transaction and under a lock so two officers
     * filing the same quarter at the same moment cannot both pass it. It is
     * not a unique index: a superseded figure is soft-deleted rather than
     * erased (it is reported government data), and a unique index would then
     * refuse the very correction that replaces it. `readings()` excludes
     * soft-deleted rows, so "live" is exactly what is checked.
     *
     * whereDate(), not where(): Eloquent writes a date-cast attribute through
     * the CONNECTION's format ('Y-m-d H:i:s'), so the stored value is only
     * ever 'Y-m-d' because a MySQL DATE column truncates it on the way in. A
     * plain equality against 'Y-m-d' therefore matches on MySQL and matches
     * nothing on SQLite, where the time part survives — which would let this
     * guard pass silently and let the same quarter be counted twice.
     */
    private function assertPeriodIsFree(
        Indicator $indicator,
        Carbon $start,
        Carbon $end,
        ?IndicatorReading $existing,
    ): void {
        $clash = $indicator->readings()
            ->lockForUpdate()
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->when($existing !== null, fn (Builder $query) => $query->whereKeyNot($existing?->getKey()))
            ->exists();

        if ($clash) {
            throw IndicatorRuleViolation::duplicateReadingForPeriod();
        }
    }
}
