<?php

namespace Database\Factories;

use App\Enums\InspectionStatus;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: this factory NEVER sets tenant_id. Run it inside a bound
 * tenant context and BelongsToTenant fills it.
 *
 * The model is append-only and refuses updates and deletes, so a fixture
 * builds the ledger it wants in one pass — there is no "then correct it".
 *
 * @extends Factory<SiteInspectionEvent>
 */
class SiteInspectionEventFactory extends Factory
{
    protected $model = SiteInspectionEvent::class;

    public function definition(): array
    {
        return [
            'site_inspection_id' => SiteInspection::factory(),
            'from_status' => null,
            'to_status' => InspectionStatus::Scheduled,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forInspection(SiteInspection $inspection): static
    {
        return $this->state(['site_inspection_id' => $inspection->id]);
    }

    public function by(User $actor): static
    {
        return $this->state(['actor_id' => $actor->id]);
    }

    public function step(?InspectionStatus $from, InspectionStatus $to, ?CarbonImmutable $at = null): static
    {
        return $this->state([
            'from_status' => $from,
            'to_status' => $to,
            'occurred_at' => $at ?? CarbonImmutable::now(),
        ]);
    }

    public function cancelled(string $reason = 'Access road impassable after three days of rain.'): static
    {
        return $this->state([
            'from_status' => InspectionStatus::Scheduled,
            'to_status' => InspectionStatus::Cancelled,
            'reason' => $reason,
        ]);
    }
}
