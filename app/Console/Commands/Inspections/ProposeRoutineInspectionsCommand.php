<?php

namespace App\Console\Commands\Inspections;

use App\Actions\Inspections\ProposeRoutineInspections;
use Illuminate\Console\Command;

/**
 * The routine-monitoring sweep. Safe to run twice in one day, or twice in one
 * minute: every proposal carries a deterministic schedule_key and the table
 * holds a unique index on (tenant, project, schedule_key), so a second run
 * finds the row it would have created and returns it.
 */
class ProposeRoutineInspectionsCommand extends Command
{
    protected $signature = 'inspections:propose-routine';

    protected $description = 'Propose routine site inspections for projects past their monitoring interval (idempotent)';

    public function handle(ProposeRoutineInspections $propose): int
    {
        $count = $propose();

        $this->info("{$count} routine inspection(s) proposed.");

        return self::SUCCESS;
    }
}
