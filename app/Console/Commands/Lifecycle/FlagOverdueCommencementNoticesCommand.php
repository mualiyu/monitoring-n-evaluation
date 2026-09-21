<?php

namespace App\Console\Commands\Lifecycle;

use App\Actions\Lifecycle\FlagOverdueCommencementNotices;
use Illuminate\Console\Command;

/**
 * Finds awards that never got a commencement notice, gives each one a pending
 * notice to be late on, and tells the MDA administrator once.
 *
 * Both gates are columns on the notice row — the row's existence, and
 * `overdue_notified_at` — so a second run the same day is silent.
 */
class FlagOverdueCommencementNoticesCommand extends Command
{
    protected $signature = 'lifecycle:flag-overdue-notices';

    protected $description = 'Flag contract awards with no commencement notice past the statutory window (idempotent)';

    public function handle(FlagOverdueCommencementNotices $flag): int
    {
        $totals = $flag();

        $this->info("{$totals['materialised']} outstanding notice(s) recorded and {$totals['flagged']} overdue notice(s) announced.");

        return self::SUCCESS;
    }
}
