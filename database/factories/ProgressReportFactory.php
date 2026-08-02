<?php

namespace Database\Factories;

use App\Enums\ProgressReportStatus;
use App\Enums\ReportEntryMode;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\User;
use App\Support\InstanceTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Progress reports are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * `status`, `due_at` and the whole approval chain are deliberately not
 * fillable — TransitionProgressReportStatus is their only writer in
 * application code. Factories run unguarded, which is the point: a fixture may
 * state where a record IS, while only an Action may move it there.
 *
 * Money attributes are decimal strings ("2500000.00"), never floats.
 *
 * @extends Factory<ProgressReport>
 */
class ProgressReportFactory extends Factory
{
    protected $model = ProgressReport::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->ongoing(),
            // Joins the state's calendar rather than inventing a parallel one
            // — see ReportingPeriodFactory::currentOrNew().
            'reporting_period_id' => fn (): int|ReportingPeriodFactory => ReportingPeriodFactory::currentOrNew(),
            'report_obligation_id' => null,
            'status' => ProgressReportStatus::Draft,
            'narrative_work_done' => 'Earthworks completed on the northern section; sub-base laid across 1.2 km '
                .'and drainage structures cast at three of five crossings.',
            'narrative_challenges' => 'Two weeks lost to unseasonal rainfall and a right-of-way dispute at chainage 3+400.',
            'narrative_mitigation' => 'Night shifts approved to recover the schedule; the dispute sits with the LGA lands office.',
            'narrative_next_period' => 'Base course and priming across the completed section.',
            'physical_progress_claimed' => fake()->numberBetween(10, 90).'.00',
            'physical_progress_before' => null,
            'progress_decrease_reason' => null,
            'period_expenditure' => fake()->numberBetween(2, 40).'000000.00',
            'cumulative_expenditure_snapshot' => null,
            'entry_mode' => ReportEntryMode::SelfService,
            'contractor_id' => null,
            // Snapshotted from the obligation in StartProgressReport; a
            // fixture supplies its own, because the column is not nullable.
            'due_at' => InstanceTime::endOfDay(CarbonImmutable::now()->addDays(7)),
            'submitted_late' => false,
            'created_by_id' => User::factory(),
            'submitted_by_id' => null,
            'submitted_at' => null,
            'reviewed_by_id' => null,
            'reviewed_at' => null,
            'approved_by_id' => null,
            'approved_at' => null,
            'returned_by_id' => null,
            'returned_at' => null,
            'return_reason' => null,
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

    public function forObligation(ReportObligation $obligation): static
    {
        return $this->state([
            'reporting_period_id' => $obligation->reporting_period_id,
            'project_id' => $obligation->project_id,
            'report_obligation_id' => $obligation->id,
            'due_at' => $obligation->due_at,
        ]);
    }

    public function by(User $author): static
    {
        return $this->state(['created_by_id' => $author->id]);
    }

    /** An officer typing a contractor's return — provenance recorded. */
    public function onBehalf(?int $contractorId = null): static
    {
        return $this->state([
            'entry_mode' => ReportEntryMode::OnBehalf,
            'contractor_id' => $contractorId,
        ]);
    }

    public function draft(): static
    {
        return $this->state(['status' => ProgressReportStatus::Draft]);
    }

    public function submitted(?User $submitter = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProgressReportStatus::Submitted,
            'submitted_by_id' => $submitter->id ?? $attributes['created_by_id'],
            'submitted_at' => CarbonImmutable::now()->subDays(2),
        ]);
    }

    public function reviewed(?User $reviewer = null): static
    {
        return $this->submitted()->state([
            'status' => ProgressReportStatus::Reviewed,
            'reviewed_by_id' => $reviewer->id ?? User::factory(),
            'reviewed_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function approved(?User $approver = null): static
    {
        return $this->reviewed()->state(fn (array $attributes): array => [
            'status' => ProgressReportStatus::Approved,
            'approved_by_id' => $approver->id ?? User::factory(),
            'approved_at' => CarbonImmutable::now(),
            // The audit snapshots an approval writes: where the project stood
            // before this return was believed, and its total spend after.
            'physical_progress_before' => '20.00',
            'cumulative_expenditure_snapshot' => $attributes['period_expenditure'],
        ]);
    }

    public function returned(string $reason = 'Expenditure does not reconcile with the attached valuation — please correct and resubmit.'): static
    {
        return $this->submitted()->state([
            'status' => ProgressReportStatus::Returned,
            'returned_by_id' => User::factory(),
            'returned_at' => CarbonImmutable::now(),
            'return_reason' => $reason,
        ]);
    }

    /** Filed after the deadline — what the board's on-time column excludes. */
    public function late(): static
    {
        return $this->submitted()->state([
            'due_at' => CarbonImmutable::now()->subDays(3)->endOfDay(),
            'submitted_at' => CarbonImmutable::now()->subDay(),
            'submitted_late' => true,
        ]);
    }
}
