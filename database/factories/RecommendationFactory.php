<?php

namespace Database\Factories;

use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Recommendations are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * `status`, the follow-up stamps and `overdue_flagged_at` are deliberately not
 * fillable — TransitionRecommendationStatus and FlagOverdueRecommendations are
 * their only writers in application code. Factories run unguarded: a fixture
 * may state where a record IS, while only an Action may move it there.
 *
 * Money attributes are decimal strings ("1250000.00"), never floats.
 *
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    protected $model = Recommendation::class;

    public function definition(): array
    {
        return [
            'source_type' => Evaluation::class,
            'source_id' => Evaluation::factory(),
            'project_id' => null,
            'title' => 'Re-sequence the drainage works ahead of the wet season',
            'body' => 'Drainage structures scheduled for the fourth quarter should be brought forward, since '
                .'carriageway works completed before them will be undermined by the first heavy rains.',
            'addressee_id' => null,
            'addressee_body' => 'Directorate of Works',
            'priority' => RecommendationPriority::High,
            'status' => RecommendationStatus::Open,
            'estimated_cost' => '1250000.00',
            'timeline' => 'Before the next rainy season',
            'due_on' => CarbonImmutable::now()->addMonths(2)->toDateString(),
            'implementation_evidence' => null,
            'follow_up_notes' => null,
            'overdue_flagged_at' => null,
            'raised_by_id' => User::factory(),
            'raised_at' => CarbonImmutable::now(),
            'accepted_by_id' => null,
            'accepted_at' => null,
            'implemented_by_id' => null,
            'implemented_at' => null,
            'closure_reason' => null,
            'superseded_by_id' => null,
            'status_changed_at' => CarbonImmutable::now(),
        ];
    }

    /** Raised by any tenant-owned monitoring record — an evaluation, a return. */
    public function from(Model $source): static
    {
        return $this->state([
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'project_id' => $source->getAttribute('project_id'),
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    /** Addressed to a person the platform can chase, rather than to a body. */
    public function addressedTo(User $user): static
    {
        return $this->state([
            'addressee_id' => $user->id,
            'addressee_body' => null,
        ]);
    }

    public function priority(RecommendationPriority $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function critical(): static
    {
        return $this->priority(RecommendationPriority::Critical);
    }

    public function open(): static
    {
        return $this->state(['status' => RecommendationStatus::Open]);
    }

    public function accepted(?User $by = null): static
    {
        return $this->state([
            'status' => RecommendationStatus::Accepted,
            'accepted_by_id' => $by?->id ?? User::factory(),
            'accepted_at' => CarbonImmutable::now(),
        ]);
    }

    public function inProgress(?User $by = null): static
    {
        return $this->accepted($by)->state(['status' => RecommendationStatus::InProgress]);
    }

    public function implemented(?User $by = null): static
    {
        return $this->accepted($by)->state([
            'status' => RecommendationStatus::Implemented,
            'implemented_by_id' => $by?->id ?? User::factory(),
            'implemented_at' => CarbonImmutable::now(),
            'implementation_evidence' => 'Revised works programme issued 14 May; drainage now precedes '
                .'carriageway on all three sections. Contractor instruction attached to the project vault.',
        ]);
    }

    public function rejected(string $reason = 'No budget line exists for re-sequencing within the current appropriation.'): static
    {
        return $this->state([
            'status' => RecommendationStatus::Rejected,
            'closure_reason' => $reason,
        ]);
    }

    public function superseded(?Recommendation $replacement = null): static
    {
        return $this->state([
            'status' => RecommendationStatus::Superseded,
            'closure_reason' => 'Replaced by a wider drainage master-plan recommendation.',
            'superseded_by_id' => $replacement?->id,
        ]);
    }

    /** Due in N days; a negative N is already late. */
    public function dueIn(int $days): static
    {
        return $this->state(['due_on' => CarbonImmutable::now()->addDays($days)->toDateString()]);
    }

    /** Outstanding and past its date — what the nightly sweep announces. */
    public function overdue(int $daysLate = 10): static
    {
        return $this->open()->dueIn(-$daysLate);
    }

    /** Overdue and already announced — the sweep's idempotency gate, set. */
    public function alreadyFlagged(int $daysLate = 10): static
    {
        return $this->overdue($daysLate)->state([
            'overdue_flagged_at' => CarbonImmutable::now()->subDay(),
        ]);
    }
}
