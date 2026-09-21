<?php

namespace App\Console\Commands\Inspections;

use App\Actions\Inspections\FlagOverdueInspectionReports;
use Illuminate\Console\Command;

/**
 * The outstanding-field-work sweep: a visit that happened but whose Field Trip
 * Report never arrived. Safe to run twice — the notice is gated by
 * `report_overdue_notified_at`, stamped under a row lock in the same
 * transaction as the dispatch.
 */
class FlagOverdueInspectionReportsCommand extends Command
{
    protected $signature = 'inspections:flag-overdue';

    protected $description = 'Flag site inspections whose Field Trip Report is past its deadline (idempotent)';

    public function handle(FlagOverdueInspectionReports $flag): int
    {
        $count = $flag();

        $this->info("{$count} overdue inspection report(s) flagged.");

        return self::SUCCESS;
    }
}
