<?php

namespace App\Console\Commands\Reporting;

use App\Actions\Reporting\GenerateReportingPeriods;
use Illuminate\Console\Command;

/**
 * Builds (or rebuilds) a year of the statutory reporting calendar.
 *
 * Runs yearly ahead of the new year and on install; safe to run by hand at any
 * time, because every window is upserted on its code.
 */
class GenerateReportingPeriodsCommand extends Command
{
    protected $signature = 'reporting:generate-periods {--year= : Calendar year to generate (defaults to the current year)}';

    protected $description = 'Generate the statutory reporting calendar for a year (idempotent)';

    public function handle(GenerateReportingPeriods $generate): int
    {
        $year = (int) ($this->option('year') ?? now()->year);

        $count = $generate($year);

        $this->info("Reporting calendar for {$year}: {$count} window(s) generated or refreshed.");

        return self::SUCCESS;
    }
}
