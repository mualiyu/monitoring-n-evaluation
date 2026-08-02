<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The four statutory reporting rhythms of the state calendar
 * (progress-reporting.md §1.1). Deliberately narrower than
 * MeasurementFrequency, which governs *indicator readings*: nobody files a
 * weekly progress report, and a one-off measurement has no reporting window.
 *
 * Period BOUNDARIES live here because they are calendar facts (a quarter is a
 * quarter in every state). Due DATES do not — they are configurable policy and
 * are computed in App\Actions\Reporting\GenerateReportingPeriods from the
 * `platform.reporting` settings.
 */
enum ReportingCadence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Biannual = 'biannual';
    case Annual = 'annual';

    /** How many windows this cadence produces in a calendar year. */
    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Biannual => 2,
            self::Annual => 1,
        };
    }

    /**
     * First day of window $ordinal (1-based) of $year.
     */
    public function startOfPeriod(int $year, int $ordinal): CarbonImmutable
    {
        $month = match ($this) {
            self::Monthly => $ordinal,
            self::Quarterly => ($ordinal - 1) * 3 + 1,
            self::Biannual => ($ordinal - 1) * 6 + 1,
            self::Annual => 1,
        };

        // createStrict, not create(): the latter returns null for an
        // impossible date, and a nullable calendar boundary would leak a
        // "maybe" into every deadline computed from it.
        return CarbonImmutable::createStrict($year, $month, 1)->startOfDay();
    }

    /**
     * Last day of window $ordinal (1-based) of $year.
     */
    public function endOfPeriod(int $year, int $ordinal): CarbonImmutable
    {
        $months = match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Biannual => 6,
            self::Annual => 12,
        };

        return $this->startOfPeriod($year, $ordinal)->addMonths($months)->subDay()->endOfDay();
    }

    /**
     * The stable public code of a window — `2026-M03`, `2026-Q1`, `2026-H1`,
     * `2026-A`. It is the natural key the generator upserts on, so re-running
     * the command converges instead of duplicating the calendar.
     */
    public function codeFor(int $year, int $ordinal): string
    {
        return match ($this) {
            self::Monthly => sprintf('%d-M%02d', $year, $ordinal),
            self::Quarterly => sprintf('%d-Q%d', $year, $ordinal),
            self::Biannual => sprintf('%d-H%d', $year, $ordinal),
            self::Annual => sprintf('%d-A', $year),
        };
    }

    /** Human label for a window — "March 2026", "First Half 2026". */
    public function labelFor(int $year, int $ordinal): string
    {
        return match ($this) {
            self::Monthly => $this->startOfPeriod($year, $ordinal)->translatedFormat('F Y'),
            self::Quarterly => __(':ordinal Quarter :year', ['ordinal' => $this->ordinalWord($ordinal), 'year' => $year]),
            self::Biannual => __(':ordinal Half :year', ['ordinal' => $this->ordinalWord($ordinal), 'year' => $year]),
            self::Annual => __('Year :year', ['year' => $year]),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Biannual => __('Bi-annual'),
            self::Annual => __('Annual'),
        };
    }

    private function ordinalWord(int $ordinal): string
    {
        return match ($ordinal) {
            1 => __('First'),
            2 => __('Second'),
            3 => __('Third'),
            default => __('Fourth'),
        };
    }
}
