<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory). Money attributes
 * are decimal strings.
 *
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        $sumNaira = fake()->numberBetween(40, 850) * 1_000_000;

        return [
            'project_id' => Project::factory(),
            'contractor_id' => Contractor::factory(),
            'contract_number' => 'CTR-'.fake()->unique()->numerify('#####'),
            'type' => ContractType::Works,
            'status' => ContractStatus::Awarded,
            'sum' => $sumNaira.'.00',
            'scope_of_works' => fake()->sentence(12),
            'award_date' => now()->subMonths(6)->toDateString(),
            'commencement_date' => now()->subMonths(5)->toDateString(),
            'duration_days' => fake()->randomElement([180, 270, 365, 540]),
            'expected_completion_date' => now()->addMonths(6)->toDateString(),
            'retention_percentage' => '5.00',
            'varies_contract_id' => null,
            'variation_reason' => null,
            'created_by_id' => User::factory(),
        ];
    }

    /**
     * A variation order against an existing contract: the original award sum
     * stays immutable, the variation is its own row.
     */
    public function variation(?Contract $original = null): static
    {
        return $this->state(function (array $attributes) use ($original) {
            $variationNaira = fake()->numberBetween(2, 40) * 1_000_000;

            return [
                // A variation is executed by the firm holding the original.
                'varies_contract_id' => $original === null ? Contract::factory() : $original->id,
                'contractor_id' => $original === null ? $attributes['contractor_id'] : $original->contractor_id,
                'contract_number' => 'CTR-'.fake()->unique()->numerify('#####').'-VO1',
                'sum' => $variationNaira.'.00',
                'scope_of_works' => 'Variation: '.fake()->sentence(8),
                // Required whenever varies_contract_id is set: a variation
                // without a stated reason is an unexplained cost increase.
                'variation_reason' => 'Additional works arising from revised ground conditions.',
                'award_date' => now()->subMonths(2)->toDateString(),
                'commencement_date' => null,
            ];
        });
    }

    public function active(): static
    {
        return $this->state(['status' => ContractStatus::Active]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => ContractStatus::Completed,
            'expected_completion_date' => now()->subMonth()->toDateString(),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(['status' => ContractStatus::Terminated]);
    }

    public function ofType(ContractType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }
}
