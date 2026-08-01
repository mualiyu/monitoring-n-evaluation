<?php

namespace App\Enums;

/**
 * Unit of measurement for an indicator, per the M&E manual's indicator
 * definition sheet (number / percentage / time / one-off milestone).
 */
enum IndicatorUnit: string
{
    case Number = 'number';
    case Percentage = 'percentage';
    case Time = 'time';
    case OneOff = 'one_off';

    public function label(): string
    {
        return match ($this) {
            self::Number => __('Number'),
            self::Percentage => __('Percentage'),
            self::Time => __('Time'),
            self::OneOff => __('One-off Milestone'),
        };
    }

    /** Suffix appended when rendering a reading (e.g. "42%"). */
    public function suffix(): string
    {
        return $this === self::Percentage ? '%' : '';
    }
}
