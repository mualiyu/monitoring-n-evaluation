<?php

namespace App\Actions\Evaluation;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\User;

/**
 * Approves the findings.
 *
 * THE SEPARATION RULE LIVES IN THE CHOKEPOINT, not here: the evaluation lead
 * cannot approve their own evaluation, and neither can whoever sent it up for
 * review. Putting the guard in TransitionEvaluationStatus rather than in this
 * Action is the whole point of having a chokepoint — a future second approval
 * path (a console command, an oversight screen, a bulk action) inherits the
 * rule instead of having to remember it.
 *
 * Approval settles the findings but does NOT make them public: publication is
 * a separate, deliberate act (PublishEvaluation), because what an MDA has
 * accepted internally and what the state has put in front of citizens are two
 * different decisions.
 */
class ApproveEvaluation
{
    public function __construct(private readonly TransitionEvaluationStatus $transition) {}

    public function __invoke(Evaluation $evaluation, User $actor, ?string $note = null): Evaluation
    {
        return ($this->transition)($evaluation, EvaluationStatus::Approved, $actor, $note);
    }
}
