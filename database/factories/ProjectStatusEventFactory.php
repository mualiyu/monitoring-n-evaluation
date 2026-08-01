<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory). The ledger is
 * append-only: the model refuses updates and deletes, so states here describe
 * the row at creation time.
 *
 * @extends Factory<ProjectStatusEvent>
 */
class ProjectStatusEventFactory extends Factory
{
    protected $model = ProjectStatusEvent::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'from_status' => null,
            'to_status' => ProjectStatus::Draft,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => now()->subMonths(6),
        ];
    }

    public function transition(?ProjectStatus $from, ProjectStatus $to): static
    {
        return $this->state([
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }

    public function withReason(string $reason): static
    {
        return $this->state(['reason' => $reason]);
    }

    public function by(User $actor): static
    {
        return $this->state(['actor_id' => $actor->id]);
    }
}
