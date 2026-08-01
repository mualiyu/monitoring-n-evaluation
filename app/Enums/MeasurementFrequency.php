<?php

namespace App\Enums;

/**
 * How often an indicator is measured. The Phase 2 deadline engine derives
 * reporting periods from this, so it must exist from Phase 1.
 */
enum MeasurementFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Biannual = 'biannual';
    case Annual = 'annual';
    case OneOff = 'one_off';

    /** Expected readings per year (0 for one-off measurements). */
    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Weekly => 52,
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Biannual => 2,
            self::Annual => 1,
            self::OneOff => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Biannual => __('Bi-annual'),
            self::Annual => __('Annual'),
            self::OneOff => __('One-off'),
        };
    }
}
