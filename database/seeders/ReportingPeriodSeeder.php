<?php

namespace Database\Seeders;

use App\Actions\Reporting\GenerateReportingPeriods;
use Illuminate\Database\Seeder;

/**
 * The statutory calendar for the current year, built by the SAME Action the
 * yearly cron runs (progress-reporting.md §8). Seeding through the Action
 * rather than through literals means a demo database and a production install
 * cannot disagree about when a return is due — and the seeder is idempotent
 * for free, because the Action upserts on the window code.
 *
 * Global data, unlike the demo seeders: no tenant context, and it runs in
 * production too. Every MDA reports against these windows.
 */
class ReportingPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $generate = new GenerateReportingPeriods;

        // Last year as well as this one: the demo portfolio has projects that
        // have been running for months, and a calendar that starts in January
        // of the current year would leave them with no history to show.
        $generate(now()->year - 1, 'seeder');
        $generate(now()->year, 'seeder');
    }
}
