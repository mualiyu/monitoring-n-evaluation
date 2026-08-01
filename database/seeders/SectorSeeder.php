<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Generic sector taxonomy — instance-configurable reference data, and
 * deliberately free of any client state's naming. Idempotent on `code`.
 *
 * One level only: `parent_id` exists for clients whose MTSS/NEMSF list needs
 * sub-sectors, but nothing here presumes it.
 */
class SectorSeeder extends Seeder
{
    public function run(): void
    {
        $sectors = [
            ['code' => 'AGR', 'name' => 'Agriculture & Rural Development'],
            ['code' => 'EDU', 'name' => 'Education'],
            ['code' => 'HLT', 'name' => 'Health'],
            ['code' => 'WKS', 'name' => 'Works & Transport'],
            ['code' => 'WAT', 'name' => 'Water Resources'],
            ['code' => 'ENV', 'name' => 'Environment'],
            ['code' => 'JUS', 'name' => 'Justice & Security'],
            ['code' => 'COM', 'name' => 'Commerce & Industry'],
            ['code' => 'ICT', 'name' => 'Information & Communications Technology'],
            ['code' => 'SOC', 'name' => 'Social Development'],
        ];

        foreach ($sectors as $index => $sector) {
            Sector::query()->updateOrCreate(
                ['code' => $sector['code']],
                [
                    'name' => $sector['name'],
                    'parent_id' => null,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                ],
            );
        }
    }
}
