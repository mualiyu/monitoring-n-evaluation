<?php

namespace App\Console\Commands\Reporting;

use App\Actions\Reporting\GenerateReportingPeriods;
use App\Support\InstanceTime;
use Illuminate\Console\Command;

/**
 * Builds (or rebuilds) the statutory reporting calendar.
 *
 * WITH NO --year IT GENERATES THIS YEAR **AND** NEXT. That is the whole point
 * of the scheduled run: the cron fires on 1 December, and a run that produced
 * only the current year would rebuild the twelve windows everyone has already
 * reported against and create nothing for January — so on New Year's Day
 * reporting:generate-obligations would find no open window, no obligation would
 * be raised, no reminder would go out, and the deadline engine would go dark
 * with nothing in the logs to say so. Generating a year ahead means the
 * calendar is always at least one month deep, whichever day the sweep runs.
 *
 * Idempotent either way: every window is upserted on its code, so this is safe
 * on install, by hand, and twice in the same minute.
 *
 * The year is read on the INSTANCE's wall clock — between midnight in Lagos and
 * midnight UTC on 31 December, `now()->year` in UTC is still the old year.
 */
class GenerateReportingPeriodsCommand extends Command
{
    protected $signature = 'reporting:generate-periods {--year= : Calendar year to generate (defaults to the current year and the next)}';

    protected $description = 'Generate the statutory reporting calendar (idempotent; defaults to this year and next)';

    public function handle(GenerateReportingPeriods $generate): int
    {
        foreach ($this->years() as $year) {
            $count = $generate($year);

            $this->info("Reporting calendar for {$year}: {$count} window(s) generated or refreshed.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        $year = $this->option('year');

        if ($year !== null) {
            return [(int) $year];
        }

        $current = InstanceTime::now()->year;

        return [$current, $current + 1];
    }
}
