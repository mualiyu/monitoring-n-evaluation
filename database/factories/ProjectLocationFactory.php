<?php

namespace Database\Factories;

use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory).
 *
 * @extends Factory<ProjectLocation>
 */
class ProjectLocationFactory extends Factory
{
    protected $model = ProjectLocation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'site_name' => fake()->randomElement([
                'Main Site', 'Township Section', 'Community Annex',
                'Central Facility', 'Riverbank Section',
            ]),
            'description' => fake()->sentence(),
            'lga_id' => Lga::factory(),
            'ward_id' => null,
            // Coordinates as decimal strings — never floats.
            'latitude' => fake()->numerify('#.######'),
            'longitude' => fake()->numerify('#.######'),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }

    public function at(Lga $lga, ?Ward $ward = null): static
    {
        return $this->state([
            'lga_id' => $lga->id,
            'ward_id' => $ward?->id,
        ]);
    }

    /**
     * A site at an exact fix. Decimal strings, never floats: coordinates are
     * printed on a government report and must round-trip unchanged, and the
     * geofence judgement is made against them.
     */
    public function coordinates(string $latitude, string $longitude): static
    {
        return $this->state([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /** A site recorded before anyone captured a GPS reading. */
    public function withoutCoordinates(): static
    {
        return $this->state([
            'latitude' => null,
            'longitude' => null,
        ]);
    }
}
