<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory).
 *
 * @extends Factory<ProjectAssignment>
 */
class ProjectAssignmentFactory extends Factory
{
    protected $model = ProjectAssignment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'role' => ProjectRole::FocalOfficer,
            'assigned_by_id' => User::factory(),
            'assigned_at' => now()->subMonths(2),
            'unassigned_at' => null,
        ];
    }

    public function consultant(): static
    {
        return $this->state(['role' => ProjectRole::Consultant]);
    }

    public function fieldMonitor(): static
    {
        return $this->state(['role' => ProjectRole::FieldMonitor]);
    }

    public function supervisor(): static
    {
        return $this->state(['role' => ProjectRole::Supervisor]);
    }

    /** The row is retained; only unassigned_at is stamped. */
    public function unassigned(): static
    {
        return $this->state(['unassigned_at' => now()->subWeek()]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }
}
