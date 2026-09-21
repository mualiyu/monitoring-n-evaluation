<?php

namespace App\Support;

use App\Enums\ActivityStatus;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use Carbon\CarbonInterface;

/**
 * THE roll-up of a work plan. Every screen, export, notification and test
 * that needs a plan's progress, budget or expenditure calls this class —
 * nothing recomputes any of it, and nothing is stored on `workplans`.
 *
 * ---------------------------------------------------------------------------
 * THE WEIGHTING CHOICE: by the explicit `weight` column, NOT by budget.
 * ---------------------------------------------------------------------------
 * Both were on the table. Budget weighting is seductive because the money is
 * already there, but it makes every zero-cost activity invisible — and the
 * manual's own Annual Work Plan is full of them: an indicator review retreat,
 * a stakeholder validation meeting, a quarterly review. Weighting by naira
 * would report a plan as 90% delivered while none of its M&E process
 * activities had happened, which is the exact failure the platform exists to
 * catch.
 *
 * `weight` defaults to 1, so the out-of-the-box behaviour is a plain
 * unweighted mean across activities — the reading an M&E officer expects — and
 * a unit that genuinely wants the road rehabilitation to count for ten
 * workshops says so explicitly. Budget remains fully visible: it is summed and
 * reported beside progress, never folded into it.
 *
 * Cancelled activities are excluded from the progress denominator (their
 * weight does not count) but are still counted in the tallies, because
 * "3 of 14 lines were dropped" is information. Their budget is excluded too:
 * a cancelled line commits nothing.
 */
final class WorkplanProgress
{
    /**
     * @param  iterable<int, WorkplanActivity>  $activities
     * @return array{
     *     progress: float|null,
     *     weight: int,
     *     activities: int,
     *     counted: int,
     *     completed: int,
     *     in_progress: int,
     *     delayed: int,
     *     cancelled: int,
     *     not_started: int,
     *     unlinked: int,
     *     budget: Money,
     *     expenditure: Money,
     *     financial_progress: float|null
     * }
     */
    public static function summarise(iterable $activities): array
    {
        $weightedProgress = 0;
        $weight = 0;
        $total = 0;
        $counted = 0;
        $completed = 0;
        $inProgress = 0;
        $delayed = 0;
        $cancelled = 0;
        $notStarted = 0;
        $unlinked = 0;
        $budget = Money::zero();
        $expenditure = Money::zero();

        foreach ($activities as $activity) {
            $total++;

            if ($activity->lacksOutputIndicator()) {
                $unlinked++;
            }

            match ($activity->status) {
                ActivityStatus::Completed => $completed++,
                ActivityStatus::InProgress => $inProgress++,
                ActivityStatus::Delayed => $delayed++,
                ActivityStatus::Cancelled => $cancelled++,
                ActivityStatus::NotStarted => $notStarted++,
            };

            if (! $activity->status->countsTowardProgress()) {
                continue;
            }

            $counted++;

            // max(1, …) so a row that somehow carries weight 0 still counts as
            // one activity rather than vanishing from its own plan.
            $rowWeight = max(1, $activity->weight);
            $weight += $rowWeight;
            $weightedProgress += $rowWeight * max(0, min(100, $activity->progress_percent));

            $budget = $budget->plus($activity->budget_amount);
            $expenditure = $expenditure->plus($activity->expenditure_to_date);
        }

        return [
            // NULL, not 0.0, for a plan with no live activities: an empty plan
            // is not a plan at 0% — <x-ui.progress> renders null as "—".
            'progress' => $weight === 0 ? null : round($weightedProgress / $weight, 2),
            'weight' => $weight,
            'activities' => $total,
            'counted' => $counted,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'delayed' => $delayed,
            'cancelled' => $cancelled,
            'not_started' => $notStarted,
            'unlinked' => $unlinked,
            'budget' => $budget,
            'expenditure' => $expenditure,
            'financial_progress' => $expenditure->percentageOf($budget),
        ];
    }

    /**
     * The same summary for a whole plan. loadMissing, not the bare accessor:
     * preventLazyLoading is on outside production, and a caller that already
     * eager-loaded the activities (every list screen does) pays no extra
     * query.
     *
     * @return array{
     *     progress: float|null, weight: int, activities: int, counted: int,
     *     completed: int, in_progress: int, delayed: int, cancelled: int,
     *     not_started: int, unlinked: int, budget: Money, expenditure: Money,
     *     financial_progress: float|null
     * }
     */
    public static function forWorkplan(Workplan $workplan): array
    {
        return self::summarise($workplan->loadMissing('activities')->activities);
    }

    /**
     * Schedule health as a single sentence's worth of facts: how much of the
     * plan's calendar has elapsed against how much of its work is done.
     *
     * Slippage is progress MINUS elapsed time, in percentage points: negative
     * means behind. Null before the plan starts (nothing to be behind on) and
     * null when there is no progress figure at all.
     *
     * @return array{elapsed: float, progress: float|null, slippage: float|null}
     */
    public static function scheduleHealth(Workplan $workplan, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();
        $summary = self::forWorkplan($workplan);

        $total = (int) $workplan->period_start->startOfDay()
            ->diffInDays($workplan->period_end->startOfDay()) + 1;
        $gone = (int) $workplan->period_start->startOfDay()
            ->diffInDays($asOf->copy()->startOfDay(), false) + 1;

        $elapsed = round(max(0, min(100, $total > 0 ? $gone * 100 / $total : 0)), 2);

        return [
            'elapsed' => $elapsed,
            'progress' => $summary['progress'],
            'slippage' => $summary['progress'] === null || $gone <= 0
                ? null
                : round($summary['progress'] - $elapsed, 2),
        ];
    }

    /**
     * Whether the plan is behind its own calendar by more than the tolerance
     * an instance sets. One definition for the stat row, the badge and the
     * oversight board.
     */
    public static function isBehindSchedule(Workplan $workplan, ?CarbonInterface $asOf = null): bool
    {
        $health = self::scheduleHealth($workplan, $asOf);

        if ($health['slippage'] === null) {
            return false;
        }

        $tolerance = app(SettingsRepository::class)->int('workplans', 'slippage_tolerance_points', 10);

        return $health['slippage'] < -$tolerance;
    }

    /**
     * The status tallies a filter bar offers, in display order — so the
     * builder, the Gantt legend and the exports agree on the vocabulary.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (ActivityStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
