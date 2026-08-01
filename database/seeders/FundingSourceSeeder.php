<?php

namespace Database\Seeders;

use App\Enums\FundingSourceType;
use App\Models\FundingSource;
use Illuminate\Database\Seeder;

/**
 * Funding sources an MDA can attribute a project to. `name` is the label the
 * instance uses; `type` is the stable classification reports aggregate along.
 * Idempotent on `code`.
 */
class FundingSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['code' => 'IGR', 'name' => 'Internally Generated Revenue', 'type' => FundingSourceType::InternalRevenue],
            ['code' => 'FAA', 'name' => 'Federal Allocation', 'type' => FundingSourceType::FederalAllocation],
            ['code' => 'WBC', 'name' => 'World Bank Credit', 'type' => FundingSourceType::Loan],
            ['code' => 'DNG', 'name' => 'Donor Grant', 'type' => FundingSourceType::Donor],
            // The state's own share alongside a donor/credit line — without it
            // a co-funded project cannot be represented honestly.
            ['code' => 'CPT', 'name' => 'Counterpart Funding', 'type' => FundingSourceType::Counterpart],
            ['code' => 'PPP', 'name' => 'Public-Private Partnership', 'type' => FundingSourceType::Ppp],
            ['code' => 'BND', 'name' => 'State Bond', 'type' => FundingSourceType::Loan],
        ];

        foreach ($sources as $source) {
            FundingSource::query()->updateOrCreate(
                ['code' => $source['code']],
                [
                    'name' => $source['name'],
                    'type' => $source['type'],
                    'is_active' => true,
                ],
            );
        }
    }
}
