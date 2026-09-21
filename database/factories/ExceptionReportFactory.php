<?php

namespace Database\Factories;

use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Exception reports are tenant-owned: this factory NEVER sets tenant_id. Run
 * it inside a bound tenant context and BelongsToTenant fills it.
 *
 * `status` and the chain are deliberately not fillable — a fixture may state
 * where a record IS, while only TransitionExceptionStatus may move it there.
 *
 * @extends Factory<ExceptionReport>
 */
class ExceptionReportFactory extends Factory
{
    protected $model = ExceptionReport::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->ongoing(),
            'issue_id' => null,
            'trigger' => ExceptionTrigger::ScheduleSlippage,
            'severity' => IssueSeverity::High,
            'status' => ExceptionStatus::Open,
            'narrative' => 'Physical progress has fallen behind the elapsed contract schedule by more than the '
                .'configured tolerance. Measured at 32.00 percentage points against a tolerance of 15.00.',
            'measured_value' => '32.00',
            'threshold_value' => '15.00',
            'physical_progress' => '38.00',
            'schedule_elapsed' => '70.00',
            'financial_progress' => '45.00',
            'measured_at' => CarbonImmutable::now(),
            // Null = raised by the threshold engine, which is the commonest
            // case and therefore the default.
            'raised_by_id' => null,
            'acknowledged_by_id' => null,
            'acknowledged_at' => null,
            'resolved_by_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    public function trigger(ExceptionTrigger $trigger): static
    {
        return $this->state([
            'trigger' => $trigger,
            'severity' => $trigger->defaultSeverity(),
        ]);
    }

    public function scheduleSlippage(): static
    {
        return $this->trigger(ExceptionTrigger::ScheduleSlippage);
    }

    public function expenditureVariance(): static
    {
        return $this->trigger(ExceptionTrigger::ExpenditureVariance)->state([
            'narrative' => 'Expenditure has run ahead of physical progress by more than the configured tolerance.',
            'measured_value' => '27.00',
            'threshold_value' => '20.00',
        ]);
    }

    public function reportingOverdue(): static
    {
        return $this->trigger(ExceptionTrigger::ReportingOverdue)->state([
            'narrative' => 'A statutory progress return for this project has been outstanding past the configured tolerance.',
            'measured_value' => '21.00',
            'threshold_value' => '14.00',
        ]);
    }

    /** The one trigger that also reaches state oversight. */
    public function criticalIncident(?User $raiser = null): static
    {
        return $this->trigger(ExceptionTrigger::CriticalIncident)->state([
            'narrative' => 'A pier of the completed span has cracked and the crossing has been closed to traffic.',
            'measured_value' => null,
            'threshold_value' => null,
            'schedule_elapsed' => null,
            'financial_progress' => null,
            'raised_by_id' => $raiser->id ?? User::factory(),
        ]);
    }

    public function manual(?User $raiser = null): static
    {
        return $this->trigger(ExceptionTrigger::Manual)->state([
            'measured_value' => null,
            'threshold_value' => null,
            'raised_by_id' => $raiser->id ?? User::factory(),
        ]);
    }

    public function raisedBy(User $raiser): static
    {
        return $this->state(['raised_by_id' => $raiser->id]);
    }

    public function severity(IssueSeverity $severity): static
    {
        return $this->state(['severity' => $severity]);
    }

    public function acknowledged(?User $actor = null): static
    {
        return $this->state([
            'status' => ExceptionStatus::Acknowledged,
            'acknowledged_by_id' => $actor->id ?? User::factory(),
            'acknowledged_at' => CarbonImmutable::now()->subHours(6),
        ]);
    }

    public function resolved(?User $actor = null): static
    {
        return $this->acknowledged($actor)->state([
            'status' => ExceptionStatus::Resolved,
            'resolved_by_id' => $actor->id ?? User::factory(),
            'resolved_at' => CarbonImmutable::now(),
            'resolution_note' => 'Extension of time approved; the revised programme brings the works back inside tolerance.',
        ]);
    }

    public function linkedTo(Issue $issue): static
    {
        return $this->state(['issue_id' => $issue->id]);
    }
}
