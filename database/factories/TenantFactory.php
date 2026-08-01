<?php

namespace Database\Factories;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = 'Ministry of '.fake()->unique()->randomElement([
            'Works & Infrastructure', 'Health', 'Education', 'Agriculture',
            'Water Resources', 'Environment', 'Transportation', 'Housing',
            'Youth & Sports', 'Women Affairs', 'Rural Development', 'Energy',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug(Str::before(Str::after($name, 'Ministry of '), ' &')),
            'type' => TenantType::Ministry,
            'branding' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function agency(): static
    {
        return $this->state([
            'type' => TenantType::Agency,
        ]);
    }
}
