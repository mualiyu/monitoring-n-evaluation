<?php

namespace App\Console\Commands\Workplans;

use App\Actions\Workplans\FlagOverdueActivities;
use Illuminate\Console\Command;

/**
 * Announces work-plan activities that have passed their planned date without
 * being finished. The gate is a stamp on the activity row, so a second run the
 * same day is silent and a crashed run re-announces nothing.
 */
class FlagOverdueActivitiesCommand extends Command
{
    protected $signature = 'workplans:flag-overdue';

    protected $description = 'Notify owners and MDA administrators of overdue work-plan activities (idempotent)';

    public function handle(FlagOverdueActivities $flag): int
    {
        $totals = $flag();

        $this->info("{$totals['overdue']} overdue activity notice(s) dispatched.");

        return self::SUCCESS;
    }
}
