<?php

namespace Database\Factories;

use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ward>
 */
class WardFactory extends Factory
{
    protected $model = Ward::class;

    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            'code' => 'WRD-'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement([
                'Ward A', 'Ward B', 'Ward C', 'Market Ward', 'Township Ward',
                'Junction Ward', 'Riverbank Ward',
            ]),
            'is_active' => true,
        ];
    }

    public function forLga(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
