<?php

namespace App\Console\Commands\Reporting;

use App\Actions\Reporting\CloseReportingPeriods;
use Illuminate\Console\Command;

/**
 * Marks the returns that never arrived. Only windows with a hard close are
 * swept — under the default configuration late returns are accepted and
 * `closes_at` is null, so this command does nothing until a state configures
 * otherwise or a secretariat closes a specific window.
 */
class CloseReportingPeriodsCommand extends Command
{
    protected $signature = 'reporting:close-periods';

    protected $description = 'Mark pending obligations of hard-closed reporting windows as missed';

    public function handle(CloseReportingPeriods $close): int
    {
        $count = $close();

        $this->info("{$count} obligation(s) marked missed.");

        return self::SUCCESS;
    }
}
