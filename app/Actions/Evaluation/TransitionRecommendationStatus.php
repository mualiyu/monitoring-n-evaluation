<?php

namespace App\Actions\Evaluation;

use App\Enums\RecommendationStatus;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Exceptions\Evaluation\InvalidEvaluationTransition;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of Recommendation::$status and of the follow-up stamps.
 * Nothing else in the codebase assigns those columns — which is why none of
 * them is fillable and why the evidence rule below cannot be routed around by
 * a form payload.
 *
 * Order, cheapest question first:
 *   1. the lifecycle table (an impossible move is impossible for everyone),
 *   2. authorization,
 *   3. the domain preconditions — a decline or a supersession needs a stated
 *      reason; a supersession names its replacement; "implemented" needs
 *      EVIDENCE,
 *   4. the write + stamps, under a row lock.
 *
 * WHY EVIDENCE IS MANDATORY. A follow-up register whose rows can be marked
 * done by pressing a button is a register that will read 100% implemented
 * within a year and mean nothing. Requiring a sentence about what actually
 * happened is the cheapest possible check on that, and it is the difference
 * between the manual's knowledge-management loop and a progress bar.
 */
class TransitionRecommendationStatus
{
    public function __invoke(
        Recommendation $recommendation,
        RecommendationStatus $to,
        User $actor,
        ?string $reason = null,
        ?string $evidence = null,
        ?Recommendation $supersededBy = null,
    ): Recommendation {
        $from = $recommendation->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidEvaluationTransition::betweenRecommendationStates($from, $to);
        }

        Gate::forUser($actor)->authorize('transition', $recommendation);

        $reason = $reason === null ? null : trim($reason);
        $evidence = $evidence === null ? null : trim($evidence);

        $this->assertPreconditions($recommendation, $to, $reason, $evidence, $supersededBy);

        DB::transaction(function () use ($recommendation, $from, $to, $actor, $reason, $evidence, $supersededBy): void {
            // Re-read the origin under the lock: two officers closing the same
            // recommendation in the same second must produce one close and one
            // refusal, not a row whose stamps disagree with its status.
            $locked = Recommendation::query()->lockForUpdate()->find($recommendation->id);

            if ($locked === null || $locked->status !== $from) {
                throw InvalidEvaluationTransition::betweenRecommendationStates(
                    $locked?->status ?? $from,
                    $to,
                );
            }

            $changes = [
                ...$this->stamps($to, $actor, $reason, $evidence, $supersededBy),
                'status' => $to,
                'status_changed_at' => now(),
            ];

            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $recommendation->forceFill($changes)->save();
        });

        return $recommendation;
    }

    /**
     * @return array<string, mixed>
     */
    private function stamps(
        RecommendationStatus $to,
        User $actor,
        ?string $reason,
        ?string $evidence,
        ?Recommendation $supersededBy,
    ): array {
        $now = now();

        return match ($to) {
            RecommendationStatus::Accepted => [
                'accepted_by_id' => $actor->id,
                'accepted_at' => $now,
            ],
            // No stamp of its own: "being implemented" is a state, not a
            // signature, and status_changed_at already dates it.
            RecommendationStatus::InProgress => [],
            RecommendationStatus::Implemented => [
                'implemented_by_id' => $actor->id,
                'implemented_at' => $now,
                'implementation_evidence' => $evidence,
                // A closed row stops being chased: clearing the flag keeps
                // "has the sweep announced this" honest if it is ever reopened
                // by a superseding entry.
                'overdue_flagged_at' => null,
            ],
            RecommendationStatus::Rejected => [
                'closure_reason' => $reason,
                'overdue_flagged_at' => null,
            ],
            RecommendationStatus::Superseded => [
                'closure_reason' => $reason,
                'superseded_by_id' => $supersededBy?->id,
                'overdue_flagged_at' => null,
            ],
            RecommendationStatus::Open => [],
        };
    }

    private function assertPreconditions(
        Recommendation $recommendation,
        RecommendationStatus $to,
        ?string $reason,
        ?string $evidence,
        ?Recommendation $supersededBy,
    ): void {
        if ($to->requiresReason() && ($reason === null || $reason === '')) {
            throw EvaluationRuleViolation::reasonRequired();
        }

        if ($to === RecommendationStatus::Implemented && ($evidence === null || $evidence === '')) {
            throw EvaluationRuleViolation::evidenceRequired();
        }

        if ($to !== RecommendationStatus::Superseded) {
            return;
        }

        if ($supersededBy === null) {
            throw EvaluationRuleViolation::supersedingRecommendationRequired();
        }

        if ($supersededBy->id === $recommendation->id) {
            throw EvaluationRuleViolation::cannotSupersedeItself();
        }
    }
}
