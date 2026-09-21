<?php

namespace App\Actions\Evaluation;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\User;

/**
 * Publishes an approved evaluation — the manual's final two steps,
 * "publication" and "dissemination".
 *
 * This is the PUBLISHING GATE that rules/security.md requires: the public
 * portal serves published data only, and what becomes public is an explicit
 * act with a named actor and a timestamp, never a side effect of approval.
 * The chain enforces that only an `approved` evaluation can reach `published`,
 * and `published` is terminal — findings are corrected by a superseding
 * evaluation, not by quietly unpublishing the ones an MDA has come to dislike.
 */
class PublishEvaluation
{
    public function __construct(private readonly TransitionEvaluationStatus $transition) {}

    public function __invoke(Evaluation $evaluation, User $actor): Evaluation
    {
        return ($this->transition)($evaluation, EvaluationStatus::Published, $actor);
    }
}
