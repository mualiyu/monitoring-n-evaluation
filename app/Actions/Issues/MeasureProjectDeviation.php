<?php

namespace App\Actions\Issues;

use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How far a project has drifted, in percentage points (plan §8 feature cue 4).
 *
 * TWO DEVIATIONS, and they are not the same question:
 *
 *   schedule slippage   = elapsed schedule %  −  physical progress %
 *   expenditure variance = financial progress % −  physical progress %
 *
 * The first asks "is the work behind the clock"; the second asks "has the
 * money outrun the work". A project can be perfectly on schedule and still be
 * 40 points ahead on spend, which is the classic signature of an advance
 * payment nobody has earned yet — and the manual's exception report exists
 * precisely so that pattern does not wait for a quarterly meeting to surface.
 *
 * Positive means BAD in both directions, deliberately: it keeps the
 * comparison against a configured tolerance a single `>=` in both cases
 * rather than a sign the caller has to remember.
 *
 * The arithmetic lives in pure statics so it is testable without a database,
 * a tenant, or an application container — the maths is the part that must not
 * be wrong, and a test that needs three fixtures to assert 15 − 10 = 5 does
 * not get written often enough.
 */
class MeasureProjectDeviation
{
    /**
     * @return array{
     *     physical_progress: float,
     *     schedule_elapsed: float|null,
     *     financial_progress: float|null,
     *     schedule_slippage: float|null,
     *     expenditure_variance: float|null,
     *     measured_at: CarbonImmutable,
     * }
     */
    public function __invoke(Project $project, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $physical = (float) $project->physical_progress;
        $elapsed = self::scheduleElapsed(
            $project->start_date,
            // The revised date is the one the project is actually held to: an
            // approved extension of time moves the deadline, and measuring
            // against the superseded one would raise a slippage exception on
            // every project that has ever had a variation.
            $project->revised_end_date ?? $project->expected_end_date,
            $asOf,
        );

        // Derived on the model (expenditure ÷ contract value); null when there
        // is no contract value, because an unpriced project is not a project
        // at 0% spend and must not be reported as one.
        $financial = $project->financial_progress;

        return [
            'physical_progress' => $physical,
            'schedule_elapsed' => $elapsed,
            'financial_progress' => $financial,
            'schedule_slippage' => self::slippagePoints($elapsed, $physical),
            'expenditure_variance' => self::variancePoints($financial, $physical),
            'measured_at' => $asOf,
        ];
    }

    /**
     * How much of the contract period has gone, as a percentage, clamped to
     * 0–100.
     *
     * Clamped because an overrunning project is at 100% elapsed, not 140%:
     * without the clamp the slippage of a project two years late would grow
     * without bound and the severity of every genuine 20-point slip would be
     * lost among them. "The time is gone" is the strongest statement the
     * elapsed figure can make.
     *
     * Null — not zero — when either date is missing or the window is
     * degenerate (end at or before start). A project with no dates has no
     * schedule to be behind, and reporting it as 0% elapsed would raise a
     * slippage exception against every project that has reported any progress
     * at all.
     */
    public static function scheduleElapsed(
        ?DateTimeInterface $start,
        ?DateTimeInterface $end,
        DateTimeInterface $asOf,
    ): ?float {
        if ($start === null || $end === null) {
            return null;
        }

        $startAt = CarbonImmutable::instance($start)->getTimestamp();
        $endAt = CarbonImmutable::instance($end)->getTimestamp();

        if ($endAt <= $startAt) {
            return null;
        }

        $now = CarbonImmutable::instance($asOf)->getTimestamp();

        $fraction = ($now - $startAt) / ($endAt - $startAt) * 100;

        return round(max(0.0, min(100.0, $fraction)), 2);
    }

    /**
     * Percentage points the work is behind the clock. Positive = behind.
     * Null when there is no schedule to measure against.
     */
    public static function slippagePoints(?float $scheduleElapsed, float $physicalProgress): ?float
    {
        if ($scheduleElapsed === null) {
            return null;
        }

        return round($scheduleElapsed - $physicalProgress, 2);
    }

    /**
     * Percentage points the money is ahead of the work. Positive = overspent
     * relative to delivery. Null when the project carries no contract value,
     * so there is no denominator to be a percentage of.
     */
    public static function variancePoints(?float $financialProgress, float $physicalProgress): ?float
    {
        if ($financialProgress === null) {
            return null;
        }

        return round($financialProgress - $physicalProgress, 2);
    }
}
