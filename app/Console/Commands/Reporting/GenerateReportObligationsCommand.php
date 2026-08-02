<?php

namespace App\Console\Commands\Reporting;

use App\Actions\Reporting\GenerateReportObligations;
use Illuminate\Console\Command;

/**
 * Materializes "who owes a return" for every open window, across every active
 * MDA. Idempotent — re-running refreshes deadlines on unfulfilled rows and
 * inserts nothing twice.
 */
class GenerateReportObligationsCommand extends Command
{
    protected $signature = 'reporting:generate-obligations';

    protected $description = 'Create report obligations for every open window and reporting project (idempotent)';

    public function handle(GenerateReportObligations $generate): int
    {
        $count = $generate();

        $this->info("{$count} obligation(s) created or refreshed.");

        return self::SUCCESS;
    }
}
