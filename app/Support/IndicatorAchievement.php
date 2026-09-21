<?php

namespace App\Support;

use App\Enums\IndicatorUnit;
use App\Enums\TargetType;
use App\Models\Indicator;

/**
 * THE definition of "how far along is this indicator" — one computation, used
 * by the register, the detail screen, the logframe builder, the validation
 * queue and every export. A second definition anywhere else is a bug: two
 * screens quoting different achievement percentages for the same figure is
 * how an M&E system loses the only thing it sells, which is trust in its
 * numbers.
 *
 * WHY THE MATHS IS NOT JUST actual / target
 * -----------------------------------------
 * The manual (digest §2) requires a baseline on every indicator, and a
 * baseline is not decoration: for "reduce under-five mortality from 45 to 20",
 * a naive actual/target reports 150% achievement when the figure sits at 30 —
 * worse than baseline read as half again better than target. Achievement is
 * therefore movement ALONG the intended distance:
 *
 *     (actual − baseline) / (target − baseline)
 *
 * with the sign handled by direction, so a reduction indicator is scored on
 * how much of the intended reduction has happened.
 *
 * DIRECTION is taken from the baseline where there is one (target below
 * baseline means lower is better) and from the unit otherwise: a `time`
 * measure is a turnaround — days to process a permit, hours to respond — and
 * lower is better by default. Everything else counts up.
 *
 * TARGET TYPES (App\Enums\TargetType)
 *  - continuous / time_bound: the formula above. The difference between the
 *    two is WHEN the target is judged (a date on the target row), not how the
 *    percentage is computed — so there is one formula, not two.
 *  - percentage_achievement: the actual is already stated as a percentage of
 *    an agreed total, so the baseline plays no part; it is actual / target.
 *
 * UNITS (App\Enums\IndicatorUnit)
 *  - number / percentage: as above.
 *  - time: as above, defaulting to lower-is-better.
 *  - one_off: a milestone. It happened or it did not: 100 or 0. Reporting
 *    "60% of a commissioning ceremony" is not information.
 *
 * The percentage is deliberately NOT clamped. Over 100 means over-delivery and
 * a negative means the figure moved backwards from baseline; both are true
 * things a reader needs, and hiding either behind a clamp is how a regression
 * gets read as "no progress".
 *
 * Arithmetic runs through bcmath on the decimal(18,4) strings: a measurement
 * does not go through a float (rules/coding-standards.md), and the division is
 * the one place rounding would compound.
 */
final class IndicatorAchievement
{
    /** Scale used for the intermediate division — four decimals in, six out. */
    private const SCALE = 6;

    public const ON_TRACK = 'on_track';

    public const AT_RISK = 'at_risk';

    public const OFF_TRACK = 'off_track';

    public const NO_DATA = 'no_data';

    private function __construct(
        public readonly ?float $percent,
        public readonly string $band,
        public readonly ?string $actual,
        public readonly ?string $target,
        public readonly ?string $baseline,
    ) {}

    /**
     * @param  string|null  $actual  the countable reading's value
     * @param  string|null  $target  the target for the period being judged
     */
    public static function for(Indicator $indicator, ?string $actual, ?string $target): self
    {
        if ($actual === null || $target === null) {
            return self::unknown($actual, $target, $indicator->baseline_value);
        }

        $percent = self::percentFor($indicator, $actual, $target);

        if ($percent === null) {
            return self::unknown($actual, $target, $indicator->baseline_value);
        }

        return new self(
            $percent,
            self::bandFor($percent),
            $actual,
            $target,
            $indicator->baseline_value,
        );
    }

    /** An indicator with no target, or no reading anyone may quote yet. */
    public static function unknown(?string $actual = null, ?string $target = null, ?string $baseline = null): self
    {
        return new self(null, self::NO_DATA, $actual, $target, $baseline);
    }

    /**
     * The percentage itself. Public and static so a test can hammer the maths
     * without building a screen, and so a bulk query can band thousands of
     * rows without instantiating an object per row.
     */
    public static function percentFor(Indicator $indicator, string $actual, string $target): ?float
    {
        // A milestone happened or it did not.
        if ($indicator->unit === IndicatorUnit::OneOff) {
            return bccomp($actual, $target, 4) >= 0 ? 100.0 : 0.0;
        }

        // A percentage-achievement target states the actual as a share of an
        // agreed total, so it starts from zero delivery by construction and
        // the baseline plays no part.
        if ($indicator->target_type === TargetType::PercentageAchievement) {
            return self::ratioUpwards($actual, $target);
        }

        $baseline = $indicator->baseline_value;
        $hasUsableBaseline = $baseline !== null && bccomp($baseline, $target, 4) !== 0;

        $lowerIsBetter = $hasUsableBaseline
            ? bccomp($target, (string) $baseline, 4) < 0
            : $indicator->unit === IndicatorUnit::Time;

        if (! $hasUsableBaseline) {
            // No baseline, or a target that asks for no change ("hold 95%
            // coverage"): there is no distance to travel, so achievement is
            // the plain ratio, read in the indicator's natural direction.
            return $lowerIsBetter
                ? self::ratioDownwards($actual, $target)
                : self::ratioUpwards($actual, $target);
        }

        /** @var string $baseline */
        return $lowerIsBetter
            // Distance travelled downwards, over distance intended.
            ? self::divide(bcsub($baseline, $actual, 4), bcsub($baseline, $target, 4))
            : self::divide(bcsub($actual, $baseline, 4), bcsub($target, $baseline, 4));
    }

    /**
     * Higher is better, no baseline in play. A zero target is only ever met by
     * a zero actual — "commission 0 boreholes" is achieved by commissioning
     * none, and missed by any other number.
     */
    private static function ratioUpwards(string $actual, string $target): ?float
    {
        if (bccomp($target, '0', 4) === 0) {
            return bccomp($actual, '0', 4) === 0 ? 100.0 : 0.0;
        }

        return self::divide($actual, $target);
    }

    /**
     * Lower is better: at or below target is full achievement, and beyond it
     * the shortfall is target/actual — 10 days against a 5-day target is 50%.
     */
    private static function ratioDownwards(string $actual, string $target): ?float
    {
        if (bccomp($actual, $target, 4) <= 0) {
            return 100.0;
        }

        // $actual is strictly greater than $target here, and a turnaround
        // target is never negative, so $actual cannot be zero.
        return self::divide($target, $actual);
    }

    /** numerator / denominator × 100, rounded to two places. Null on a zero denominator. */
    private static function divide(string $numerator, string $denominator): ?float
    {
        if (bccomp($denominator, '0', 4) === 0) {
            return null;
        }

        return round((float) bcmul(bcdiv($numerator, $denominator, self::SCALE), '100', self::SCALE), 2);
    }

    /**
     * The traffic-light band. Thresholds come from the settings chain (tenant
     * override → instance setting → config default), never from a literal:
     * one state calls 85% on track where its neighbour calls 90%.
     */
    public static function bandFor(float $percent): string
    {
        $settings = app(SettingsRepository::class);

        $onTrack = $settings->int('indicators', 'on_track_percent', 90);
        // A misconfigured at_risk ABOVE on_track would make the amber band
        // empty and silently promote at-risk indicators to green. Clamp rather
        // than trust: a settings screen is not a compiler.
        $atRisk = min($settings->int('indicators', 'at_risk_percent', 70), $onTrack);

        return match (true) {
            $percent >= $onTrack => self::ON_TRACK,
            $percent >= $atRisk => self::AT_RISK,
            default => self::OFF_TRACK,
        };
    }

    public function hasData(): bool
    {
        return $this->percent !== null;
    }

    public function isOnTrack(): bool
    {
        return $this->band === self::ON_TRACK;
    }

    public function label(): string
    {
        return match ($this->band) {
            self::ON_TRACK => __('On track'),
            self::AT_RISK => __('At risk'),
            self::OFF_TRACK => __('Off track'),
            default => __('No data'),
        };
    }

    /**
     * Status is conveyed by icon + text, never colour alone
     * (rules/ui-design-system.md) — a board pack printed in greyscale, a
     * colour-blind permanent secretary, a cheap panel in direct sun.
     */
    public function icon(): string
    {
        return match ($this->band) {
            self::ON_TRACK => 'check-circle',
            self::AT_RISK => 'exclamation-circle',
            self::OFF_TRACK => 'exclamation-triangle',
            default => 'question-mark-circle',
        };
    }

    /** Semantic token name, for <x-ui.badge> and <x-ui.stat>. */
    public function tone(): string
    {
        return match ($this->band) {
            self::ON_TRACK => 'positive',
            self::AT_RISK => 'warning',
            self::OFF_TRACK => 'critical',
            default => 'neutral',
        };
    }

    /**
     * The <x-ui.badge> status key whose TONE matches this band. The badge
     * component owns the palette; this owns the meaning. Label and icon are
     * passed alongside it, so the pill always reads as icon + text and never
     * as colour alone.
     */
    public function badgeStatus(): string
    {
        return match ($this->band) {
            self::ON_TRACK => 'on_track',
            self::AT_RISK => 'behind',
            self::OFF_TRACK => 'overdue',
            default => 'pending',
        };
    }

    /** The percentage as it is shown, e.g. "62.5%" — or an em dash. */
    public function percentLabel(): string
    {
        if ($this->percent === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($this->percent, 1, '.', ','), '0'), '.').'%';
    }

    /**
     * The bands in the order a summary row reads them, with their labels —
     * so the stat row, the filter and the export all name them identically.
     *
     * @return array<string, string>
     */
    public static function bands(): array
    {
        return [
            self::ON_TRACK => __('On track'),
            self::AT_RISK => __('At risk'),
            self::OFF_TRACK => __('Off track'),
            self::NO_DATA => __('No data'),
        ];
    }
}
