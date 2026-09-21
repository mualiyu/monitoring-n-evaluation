<?php

namespace Database\Factories;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Issues are tenant-owned: this factory NEVER sets tenant_id. Run it inside a
 * bound tenant context and BelongsToTenant fills it.
 *
 * `status` and the whole chain are deliberately not fillable —
 * TransitionIssueStatus is their only writer in application code. Factories
 * run unguarded, which is the point: a fixture may state where a record IS,
 * while only an Action may move it there.
 *
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    protected $model = Issue::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->ongoing(),
            'source_type' => null,
            'source_id' => null,
            'title' => 'Access road impassable after culvert collapse',
            'description' => 'The culvert at chainage 2+150 failed after three days of rain and the haul route is cut. '
                .'No material has reached the site since Monday.',
            'category' => IssueCategory::Access,
            'severity' => IssueSeverity::Medium,
            'status' => IssueStatus::Open,
            'owner_id' => null,
            'corrective_action' => null,
            'due_date' => null,
            'resolution_note' => null,
            'raised_by_id' => User::factory(),
            'acknowledged_by_id' => null,
            'acknowledged_at' => null,
            'resolved_by_id' => null,
            'resolved_at' => null,
            'closed_by_id' => null,
            'closed_at' => null,
            'escalated_at' => null,
            'status_changed_at' => CarbonImmutable::now(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    /** Raised off the record that surfaced it — a report, an inspection. */
    public function from(Model $source): static
    {
        return $this->state([
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
    }

    public function raisedBy(User $raiser): static
    {
        return $this->state(['raised_by_id' => $raiser->id]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(['owner_id' => $owner->id]);
    }

    public function category(IssueCategory $category): static
    {
        return $this->state(['category' => $category]);
    }

    public function severity(IssueSeverity $severity): static
    {
        return $this->state(['severity' => $severity]);
    }

    public function critical(): static
    {
        return $this->severity(IssueSeverity::Critical);
    }

    public function open(): static
    {
        return $this->state(['status' => IssueStatus::Open]);
    }

    public function acknowledged(?User $actor = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => IssueStatus::Acknowledged,
            'acknowledged_by_id' => $actor->id ?? $attributes['raised_by_id'],
            'acknowledged_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->acknowledged()->state([
            'status' => IssueStatus::InProgress,
            'corrective_action' => 'Temporary bailey crossing being installed; LGA works department mobilised.',
        ]);
    }

    /** Already up the ladder — with the gate spent, as the engine leaves it. */
    public function escalated(): static
    {
        return $this->state([
            'status' => IssueStatus::Escalated,
            'escalated_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function resolved(?User $actor = null): static
    {
        return $this->inProgress()->state(fn (array $attributes): array => [
            'status' => IssueStatus::Resolved,
            'resolved_by_id' => $actor->id ?? $attributes['raised_by_id'],
            'resolved_at' => CarbonImmutable::now(),
            'resolution_note' => 'Crossing reinstated and haul route reopened on 14 March.',
        ]);
    }

    public function closed(?User $actor = null): static
    {
        return $this->resolved($actor)->state(fn (array $attributes): array => [
            'status' => IssueStatus::Closed,
            'closed_by_id' => $actor->id ?? $attributes['raised_by_id'],
            'closed_at' => CarbonImmutable::now(),
        ]);
    }

    /** A due date N days from now — negative for one already missed. */
    public function dueIn(int $days): static
    {
        return $this->state(['due_date' => CarbonImmutable::now()->addDays($days)->toDateString()]);
    }

    /** Past its corrective-action deadline and still open. */
    public function overdue(int $daysLate = 5): static
    {
        return $this->dueIn(-$daysLate);
    }

    /** Raised N days ago — what the escalation ladder measures. */
    public function raisedDaysAgo(int $days): static
    {
        return $this->state([
            'created_at' => CarbonImmutable::now()->subDays($days),
            'updated_at' => CarbonImmutable::now()->subDays($days),
        ]);
    }
}
