<?php

namespace App\Actions\Evaluation;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\User;

/**
 * Sends the drafted report up for review — the manual's "stakeholder
 * validation of the draft" step made into a state change.
 *
 * Everything that makes a submission legal is asserted by
 * TransitionEvaluationStatus, the one writer of `status`: every required
 * section written, every criterion scored AND justified, the chain permitting
 * the move, the actor holding `evaluations.manage` over this workspace's
 * record. This Action exists so the intent has a name a screen and a test can
 * both say out loud, and so the chokepoint is never called with a bare enum
 * from a component.
 */
class SubmitEvaluationForReview
{
    public function __construct(private readonly TransitionEvaluationStatus $transition) {}

    public function __invoke(Evaluation $evaluation, User $actor): Evaluation
    {
        return ($this->transition)($evaluation, EvaluationStatus::UnderReview, $actor);
    }
}
