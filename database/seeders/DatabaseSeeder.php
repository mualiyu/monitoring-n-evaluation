<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order is load-bearing: roles before the permissions synced onto
        // them; reference data before projects (every FK is restrictOnDelete);
        // tenants + users before contractors and projects, which need a
        // created_by_id and a workspace to be created in.
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            SectorSeeder::class,
            FundingSourceSeeder::class,
            LgaWardSeeder::class,
            DemoTenantSeeder::class,
            ContractorSeeder::class,
            DemoProjectSeeder::class,
            // The statutory calendar is global reference data and ships in
            // production too; the demo reporting history that hangs off it
            // does not.
            ReportingPeriodSeeder::class,
            DemoProgressReportSeeder::class,
        ]);
    }
}
