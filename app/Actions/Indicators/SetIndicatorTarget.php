<?php

namespace App\Actions\Indicators;

use App\Enums\MeasurementFrequency;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Indicator;
use App\Models\IndicatorTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Sets (or revises) the target for one period.
 *
 * An UPSERT on (indicator, period_start, period_end), matching the unique
 * index: an annual target and its four quarterly milestones coexist because
 * they are different periods, while revising Q2 rewrites Q2 rather than
 * quietly adding a second Q2 that some query would later pick at random.
 *
 * Revision is allowed and logged, never blocked. A target quietly lowered to
 * meet the actual is the classic M&E fabrication, and the answer to it is the
 * before/after pair activitylog keeps on `target_value` — not a rule that
 * stops a state correcting a genuinely wrong figure.
 */
class SetIndicatorTarget
{
    public function __invoke(
        Indicator $indicator,
        MeasurementFrequency $periodType,
        string $periodStart,
        string $periodEnd,
        string $value,
        User $actor,
        ?string $notes = null,
    ): IndicatorTarget {
        Gate::forUser($actor)->authorize('update', $indicator);

        $start = Carbon::parse($periodStart);
        $end = Carbon::parse($periodEnd);

        if ($end->lessThan($start)) {
            throw IndicatorRuleViolation::periodInverted();
        }

        // Matched with whereDate(), not updateOrCreate()'s plain equality:
        // Eloquent writes a date-cast attribute through the CONNECTION's
        // format ('Y-m-d H:i:s'), and the stored value is only ever 'Y-m-d'
        // because a MySQL DATE column truncates it on the way in. An equality
        // against 'Y-m-d' therefore matches on MySQL and matches nothing on
        // SQLite — where the revision below would miss the existing row and
        // collide with unique(indicator_id, period_start, period_end) instead.
        $target = $indicator->targets()
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();

        $figures = [
            'period_type' => $periodType,
            'target_value' => $value,
            'notes' => $notes,
        ];

        if ($target instanceof IndicatorTarget) {
            $target->update($figures);

            return $target;
        }

        /** @var IndicatorTarget $created */
        $created = $indicator->targets()->create([
            ...$figures,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]);

        return $created;
    }
}
