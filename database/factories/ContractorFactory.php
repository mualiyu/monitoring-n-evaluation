<?php

namespace Database\Factories;

use App\Enums\FirmType;
use App\Models\Contractor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The vendor registry is GLOBAL: no tenant_id, and `created_by_tenant_id` is
 * provenance only. Leave it null unless a test is specifically asserting
 * provenance.
 *
 * @extends Factory<Contractor>
 */
class ContractorFactory extends Factory
{
    protected $model = Contractor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Ltd',
            'rc_number' => 'RC'.fake()->unique()->numerify('######'),
            'type' => FirmType::Contractor,
            'category' => fake()->randomElement(['Civil Works', 'Building', 'Supply', 'Consultancy']),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->numerify('080########'),
            'address' => fake()->address(),
            'is_blacklisted' => false,
            'blacklist_reason' => null,
            'performance_score' => null,
            'created_by_id' => User::factory(),
            'created_by_tenant_id' => null,
        ];
    }

    public function blacklisted(string $reason = 'Abandoned site without notice.'): static
    {
        return $this->state([
            'is_blacklisted' => true,
            'blacklist_reason' => $reason,
        ]);
    }

    /** An informal firm with no RC number — nullable-unique tolerates many. */
    public function withoutRcNumber(): static
    {
        return $this->state(['rc_number' => null]);
    }

    public function consultantFirm(): static
    {
        return $this->state([
            'type' => FirmType::ConsultantFirm,
            'category' => 'Consultancy',
        ]);
    }

    public function supplier(): static
    {
        return $this->state([
            'type' => FirmType::Supplier,
            'category' => 'Supply',
        ]);
    }

    public function scored(string $score): static
    {
        return $this->state(['performance_score' => $score]);
    }

    /** Records which workspace first registered the firm (provenance only). */
    public function registeredBy(Tenant $tenant): static
    {
        return $this->state(['created_by_tenant_id' => $tenant->id]);
    }
}
