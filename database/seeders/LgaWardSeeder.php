<?php

namespace Database\Seeders;

use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Database\Seeder;

/**
 * FICTIONAL LGAs and wards. A real LGA list would hard-code a client state
 * into a white-label platform; every deployment replaces this with its own
 * gazetted list. Idempotent on `code` / (lga, code).
 */
class LgaWardSeeder extends Seeder
{
    public function run(): void
    {
        $lgas = [
            'CEN' => ['name' => 'Central', 'wards' => ['Market', 'Township', 'Old Quarter']],
            'RIV' => ['name' => 'Riverside', 'wards' => ['Riverbank', 'Ferry Point', 'Delta']],
            'NGT' => ['name' => 'Northgate', 'wards' => ['Gate', 'Junction', 'New Layout']],
            'HLT' => ['name' => 'Hilltop', 'wards' => ['Ridge', 'Upper Hill', 'Valley']],
            'LKS' => ['name' => 'Lakeside', 'wards' => ['Shoreline', 'Fisher Camp', 'Marina']],
        ];

        foreach ($lgas as $code => $definition) {
            $lga = Lga::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $definition['name'], 'is_active' => true],
            );

            foreach ($definition['wards'] as $index => $ward) {
                Ward::query()->updateOrCreate(
                    ['lga_id' => $lga->id, 'code' => $code.'-W'.($index + 1)],
                    ['name' => $ward.' Ward', 'is_active' => true],
                );
            }
        }
    }
}
