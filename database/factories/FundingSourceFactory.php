<?php

namespace Database\Factories;

use App\Enums\FundingSourceType;
use App\Models\FundingSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingSource>
 */
class FundingSourceFactory extends Factory
{
    protected $model = FundingSource::class;

    public function definition(): array
    {
        return [
            'code' => 'FND-'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement([
                'Internally Generated Revenue', 'Federal Allocation',
                'Development Credit', 'Donor Grant', 'State Bond',
            ]),
            'type' => fake()->randomElement(FundingSourceType::cases()),
            'is_active' => true,
        ];
    }

    public function ofType(FundingSourceType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
