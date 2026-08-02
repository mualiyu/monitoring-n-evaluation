<?php

namespace Database\Factories;

use App\Enums\ReportObligationStatus;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Obligations are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context (`CurrentTenant::runAs()` or the
 * `actingOnTenant()` test helper) and BelongsToTenant fills it — an unbound
 * factory throws, which is the intended teaching moment.
 *
 * The compliance and reminder columns are not fillable in application code
 * (they are the deadline engine's idempotency state); factories run unguarded,
 * so a fixture may state where a row IS while only an Action may move it there.
 *
 * @extends Factory<ReportObligation>
 */
class ReportObligationFactory extends Factory
{
    protected $model = ReportObligation::class;

    public function definition(): array
    {
        return [
            // The calendar is global and uniquely keyed: two MDAs owing a
            // return for March 2026 point at ONE window, they do not each
            // invent their own (§1.1).
            'reporting_period_id' => fn (): int|ReportingPeriodFactory => ReportingPeriodFactory::currentOrNew(),
            'project_id' => Project::factory()->ongoing(),
            'due_at' => CarbonImmutable::now()->addDays(7)->endOfDay(),
            'status' => ReportObligationStatus::Pending,
            'progress_report_id' => null,
            'fulfilled_at' => null,
            'submitted_late' => false,
            'reminder_stage' => 0,
            'reminder_last_sent_at' => null,
            'overdue_notified_at' => null,
            'escalation_stage' => 0,
            'escalated_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    public function forPeriod(ReportingPeriod $period): static
    {
        return $this->state([
            'reporting_period_id' => $period->id,
            'due_at' => $period->due_at,
        ]);
    }

    public function pending(): static
    {
        return $this->state(['status' => ReportObligationStatus::Pending]);
    }

    /** Filed. `$late` is what the league table's on-time column excludes. */
    public function fulfilled(bool $late = false): static
    {
        return $this->state([
            'status' => ReportObligationStatus::Fulfilled,
            'fulfilled_at' => CarbonImmutable::now()->subDay(),
            'submitted_late' => $late,
        ]);
    }

    /** Never filed, window hard-closed — the permanent black mark. */
    public function missed(): static
    {
        return $this->state([
            'status' => ReportObligationStatus::Missed,
            'due_at' => CarbonImmutable::now()->subDays(10)->endOfDay(),
            'overdue_notified_at' => CarbonImmutable::now()->subDays(9),
            'escalation_stage' => 1,
            'escalated_at' => CarbonImmutable::now()->subDays(9),
        ]);
    }

    public function waived(string $reason = 'Site inaccessible for the whole period following flooding.'): static
    {
        return $this->state([
            'status' => ReportObligationStatus::Waived,
            'waived_at' => CarbonImmutable::now()->subDay(),
            'waiver_reason' => $reason,
        ]);
    }

    /** Deadline $days from now — negative for an obligation already overdue. */
    public function dueIn(int $days): static
    {
        return $this->state([
            'due_at' => CarbonImmutable::now()->addDays($days)->endOfDay(),
        ]);
    }

    /**
     * Reminder rungs already climbed — the fixture for "does the engine
     * re-send what it has already sent?".
     */
    public function reminded(int $stage): static
    {
        return $this->state([
            'reminder_stage' => $stage,
            'reminder_last_sent_at' => CarbonImmutable::now()->subDay(),
        ]);
    }
}
