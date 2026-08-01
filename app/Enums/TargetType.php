<?php

namespace App\Enums;

/**
 * How an indicator target is to be read: a value maintained over time, a value
 * to be reached by a date, or a percentage of an agreed total.
 */
enum TargetType: string
{
    case Continuous = 'continuous';
    case TimeBound = 'time_bound';
    case PercentageAchievement = 'percentage_achievement';

    /** Whether a target_date is meaningful (and expected) for this type. */
    public function requiresTargetDate(): bool
    {
        return $this === self::TimeBound;
    }

    public function label(): string
    {
        return match ($this) {
            self::Continuous => __('Continuous'),
            self::TimeBound => __('Time-bound'),
            self::PercentageAchievement => __('Percentage Achievement'),
        };
    }
}
