<?php

namespace Database\Factories;

use App\Models\FundingSource;
use App\Models\Project;
use App\Models\ProjectFundingSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory).
 *
 * @extends Factory<ProjectFundingSource>
 */
class ProjectFundingSourceFactory extends Factory
{
    protected $model = ProjectFundingSource::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'funding_source_id' => FundingSource::factory(),
            'amount' => null,
            'percentage' => '100.00',
            'is_primary' => true,
        ];
    }

    /** One slice of a co-funded project. */
    public function share(int $percent, bool $isPrimary = false): static
    {
        return $this->state([
            'percentage' => $percent.'.00',
            'is_primary' => $isPrimary,
        ]);
    }

    public function ofAmount(string $decimalNaira): static
    {
        return $this->state(['amount' => $decimalNaira]);
    }
}
