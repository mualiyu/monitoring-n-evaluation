<?php

namespace App\Enums;

/**
 * How finely an activity's schedule is stated. The manual's implementation
 * plan (ondo-manual-digest §6, Appendix A) is drawn as `month × week`: most
 * lines are planned to the month, while the short, dated ones — a validation
 * workshop, a dissemination event — are planned to the week.
 *
 * Both granularities store REAL DATES (planned_start / planned_end). This enum
 * says how to PRESENT and how to snap them, never where the truth lives: a
 * schedule kept as "month 4, week 2" cannot answer "is this late today?"
 * without re-deriving a date on every read.
 */
enum ActivityScheduleGranularity: string
{
    case Month = 'month';
    case Week = 'week';

    public function label(): string
    {
        return match ($this) {
            self::Month => __('Month'),
            self::Week => __('Week'),
        };
    }

    /** Short form for a Gantt bar's accessible description. */
    public function unitLabel(): string
    {
        return match ($this) {
            self::Month => __('months'),
            self::Week => __('weeks'),
        };
    }

    /** PHP date format for the schedule column headings of a Gantt grid. */
    public function headingFormat(): string
    {
        return match ($this) {
            self::Month => 'M',
            self::Week => '\WW',
        };
    }
}
