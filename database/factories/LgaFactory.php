<?php

namespace Database\Factories;

use App\Models\Lga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictional LGA names only — a real LGA list would hard-code a client state
 * into a white-label platform.
 *
 * @extends Factory<Lga>
 */
class LgaFactory extends Factory
{
    protected $model = Lga::class;

    public function definition(): array
    {
        return [
            'code' => 'LGA-'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement([
                'Central', 'Riverside', 'Northgate', 'Hilltop', 'Lakeside',
                'Eastvale', 'Westfield', 'Southbank',
            ]),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
