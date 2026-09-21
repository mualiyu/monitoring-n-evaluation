<?php

namespace App\Enums;

/**
 * The three indicator tiers the manual's matrices use (digest §2):
 * PDO (project/programme-wide) → intermediate outcome (per component) →
 * output (one per annual-work-plan activity).
 *
 * A tier is a property of the INDICATOR, not of the result statement it hangs
 * off: an impact statement can carry a PDO indicator, an outcome statement can
 * carry both a PDO indicator and intermediate ones. fitsLevel() states which
 * combinations are coherent, and CreateIndicator/InstantiateIndicator enforce
 * it — an output indicator nailed to an impact statement is how a logframe
 * quietly becomes a task list.
 */
enum IndicatorTier: string
{
    case Pdo = 'pdo';
    case Intermediate = 'intermediate';
    case Output = 'output';

    /**
     * The result levels an indicator at this tier may measure.
     *
     * @return list<FrameworkLevel>
     */
    public function measurableLevels(): array
    {
        return match ($this) {
            // The objective-level indicator answers "did the programme do what
            // it was for" — it is read at impact and at outcome alike.
            self::Pdo => [FrameworkLevel::Impact, FrameworkLevel::Outcome],
            self::Intermediate => [FrameworkLevel::Outcome],
            self::Output => [FrameworkLevel::Output],
        };
    }

    public function fitsLevel(FrameworkLevel $level): bool
    {
        return in_array($level, $this->measurableLevels(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdo => __('Objective (PDO)'),
            self::Intermediate => __('Intermediate outcome'),
            self::Output => __('Output'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pdo => __('Programme-wide: the figure the objective itself is judged on.'),
            self::Intermediate => __('Per component: the change one strand of delivery is expected to produce.'),
            self::Output => __('Per work-plan activity: what was delivered in the period.'),
        };
    }
}
