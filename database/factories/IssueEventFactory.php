<?php

namespace Database\Factories;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ledger rows are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * @extends Factory<IssueEvent>
 */
class IssueEventFactory extends Factory
{
    protected $model = IssueEvent::class;

    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'from_status' => null,
            'to_status' => IssueStatus::Open,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forIssue(Issue $issue): static
    {
        return $this->state(['issue_id' => $issue->id]);
    }

    public function step(?IssueStatus $from, IssueStatus $to, ?string $reason = null): static
    {
        return $this->state([
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
        ]);
    }

    /** The engine's escalation rung — no human actor, by design. */
    public function bySystem(): static
    {
        return $this->state(['actor_id' => null]);
    }
}
