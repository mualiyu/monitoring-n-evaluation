<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Seeder as BaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
        | Order is load-bearing, in four bands.
        |
        | 1. AUTHORITY — roles before the permissions synced onto them.
        | 2. REFERENCE DATA — global tables that ship in production too:
        |    sectors, funding sources, the LGA/ward roll, the statutory
        |    reporting calendar, the reusable indicator library and the
        |    inspection checklist templates. Every FK below is restrictOnDelete,
        |    so these must exist first.
        | 3. DEMO TENANCY — workspaces and users, then the contractor registry,
        |    then the project register. Nothing domain-shaped can be created
        |    without a tenant to own it and a user to have created it.
        | 4. DEMO DOMAIN HISTORY — progress returns, indicator readings, the
        |    monitoring lifecycle, inspections, issues, work plans, evaluations,
        |    consolidations and portal feedback. Each depends on the projects
        |    seeded in band 3, and several depend on the returns seeded before
        |    them (an exception report cites an overdue obligation; a
        |    consolidation rolls up filed returns), so this band is ordered too.
        |
        | The demo seeders each no-op in production; the reference seeders do not.
        */
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);

        $this->call([
            SectorSeeder::class,
            FundingSourceSeeder::class,
            LgaWardSeeder::class,
            ReportingPeriodSeeder::class,
            IndicatorDefinitionSeeder::class,
            InspectionChecklistSeeder::class,
        ]);

        $this->call([
            DemoTenantSeeder::class,
            ContractorSeeder::class,
            DemoProjectSeeder::class,
        ]);

        // Ordered by dependency, and tolerant of a module whose demo seeder has
        // not been written yet — a missing demo fixture must not break
        // `migrate:fresh --seed` for every other module.
        $this->callIfPresent([
            DemoProgressReportSeeder::class,
            DemoIndicatorSeeder::class,
            DemoLifecycleSeeder::class,
            DemoInspectionSeeder::class,
            DemoIssueSeeder::class,
            DemoWorkplanSeeder::class,
            'Database\\Seeders\\DemoEvaluationSeeder',
            'Database\\Seeders\\DemoConsolidationSeeder',
            'Database\\Seeders\\DemoFeedbackSeeder',
        ]);
    }

    /**
     * @param  list<class-string<BaseSeeder>|string>  $seeders
     */
    private function callIfPresent(array $seeders): void
    {
        foreach ($seeders as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
