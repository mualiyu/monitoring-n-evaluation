<?php

namespace Database\Factories;

use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Evaluations are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * `status` and the whole chain are deliberately not fillable —
 * TransitionEvaluationStatus is their only writer in application code.
 * Factories run unguarded, which is the point: a fixture may state where a
 * record IS, while only an Action may move it there.
 *
 * Money attributes are decimal strings ("8500000.00"), never floats.
 *
 * @extends Factory<Evaluation>
 */
class EvaluationFactory extends Factory
{
    protected $model = Evaluation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->ongoing(),
            'scope' => 'project',
            'subject_name' => null,
            'type' => EvaluationType::MidTerm,
            'status' => EvaluationStatus::Planned,
            'title' => 'Mid-term evaluation of the township road rehabilitation programme',
            'purpose' => 'Establish whether the intervention is on course to deliver its outcome targets, and '
                .'identify the changes in delivery that the remaining period requires.',
            'evaluation_questions' => "1. Is the intervention still relevant to the beneficiaries' priorities?\n"
                .'2. Are outputs being delivered at the planned cost and pace?',
            'methodology_summary' => 'Desk review of monitoring returns, structured site observation, key '
                .'informant interviews and two beneficiary focus group discussions.',
            'sponsor' => 'State M&E Secretariat',
            'phases' => [
                ['label' => 'Desk review', 'starts_on' => null, 'ends_on' => null],
                ['label' => 'Fieldwork', 'starts_on' => null, 'ends_on' => null],
                ['label' => 'Stakeholder validation', 'starts_on' => null, 'ends_on' => null],
                ['label' => 'Reporting', 'starts_on' => null, 'ends_on' => null],
            ],
            'budget' => '4500000.00',
            'starts_on' => CarbonImmutable::now()->subMonth()->toDateString(),
            'ends_on' => CarbonImmutable::now()->addMonths(2)->toDateString(),
            'report_due_on' => CarbonImmutable::now()->addMonths(3)->toDateString(),
            'created_by_id' => User::factory(),
            'commissioned_by_id' => null,
            'commissioned_at' => CarbonImmutable::now()->subMonth(),
            'submitted_by_id' => null,
            'submitted_at' => null,
            'approved_by_id' => null,
            'approved_at' => null,
            'published_by_id' => null,
            'published_at' => null,
            'cancellation_reason' => null,
            'status_changed_at' => CarbonImmutable::now()->subMonth(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state([
            'project_id' => $project->id,
            'scope' => 'project',
            'subject_name' => null,
        ]);
    }

    /**
     * An evaluation of a programme rather than of one project — the case the
     * nullable project_id exists for.
     */
    public function programme(string $name = 'Rural Water Supply Programme'): static
    {
        return $this->state([
            'project_id' => null,
            'scope' => 'programme',
            'subject_name' => $name,
        ]);
    }

    public function by(User $author): static
    {
        return $this->state(['created_by_id' => $author->id, 'commissioned_by_id' => $author->id]);
    }

    public function ofType(EvaluationType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function midTerm(): static
    {
        return $this->ofType(EvaluationType::MidTerm);
    }

    public function terminal(): static
    {
        return $this->ofType(EvaluationType::Terminal);
    }

    public function impact(): static
    {
        return $this->ofType(EvaluationType::Impact);
    }

    public function planned(): static
    {
        return $this->state(['status' => EvaluationStatus::Planned]);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => EvaluationStatus::InProgress]);
    }

    public function draftReport(): static
    {
        return $this->state(['status' => EvaluationStatus::DraftReport]);
    }

    public function underReview(?User $submitter = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EvaluationStatus::UnderReview,
            'submitted_by_id' => $submitter->id ?? $attributes['created_by_id'],
            'submitted_at' => CarbonImmutable::now()->subDays(2),
        ]);
    }

    public function approved(?User $approver = null): static
    {
        return $this->underReview()->state([
            'status' => EvaluationStatus::Approved,
            'approved_by_id' => $approver->id ?? User::factory(),
            'approved_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function published(?User $publisher = null): static
    {
        return $this->approved()->state([
            'status' => EvaluationStatus::Published,
            'published_by_id' => $publisher->id ?? User::factory(),
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    public function cancelled(string $reason = 'The programme was restructured before fieldwork began.'): static
    {
        return $this->state([
            'status' => EvaluationStatus::Cancelled,
            'cancellation_reason' => $reason,
        ]);
    }

    /** Past its report deadline and still unsettled — what the board flags. */
    public function reportOverdue(int $daysLate = 14): static
    {
        return $this->state([
            'status' => EvaluationStatus::InProgress,
            'report_due_on' => CarbonImmutable::now()->subDays($daysLate)->toDateString(),
        ]);
    }
}
