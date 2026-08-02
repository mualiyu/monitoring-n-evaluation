<?php

namespace App\Console\Commands\Reporting;

use App\Actions\Reporting\FlagOverdueObligations;
use Illuminate\Console\Command;

/**
 * Announces overdue returns once and escalates them on the configured ladder —
 * MDA admin first, state oversight second. Both gates are counters on the
 * obligation row, so a second run the same day is silent.
 */
class FlagOverdueReportsCommand extends Command
{
    protected $signature = 'reporting:flag-overdue';

    protected $description = 'Notify and escalate overdue report obligations (idempotent per stage)';

    public function handle(FlagOverdueObligations $flag): int
    {
        $totals = $flag();

        $this->info("{$totals['overdue']} overdue notice(s) and {$totals['escalated']} escalation(s) dispatched.");

        return self::SUCCESS;
    }
}
