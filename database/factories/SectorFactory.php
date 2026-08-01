<?php

namespace Database\Factories;

use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    protected $model = Sector::class;

    public function definition(): array
    {
        return [
            'code' => 'SEC-'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement([
                'Agriculture & Rural Development', 'Education', 'Health',
                'Works & Transport', 'Water Resources', 'Environment',
                'Justice & Security', 'Commerce & Industry', 'ICT',
                'Social Development',
            ]),
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function subSectorOf(Sector $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'sort_order' => 1,
        ]);
    }
}
