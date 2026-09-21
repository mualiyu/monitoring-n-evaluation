<?php

namespace App\Exceptions\Evaluation;

use App\Enums\EvaluationStatus;
use App\Enums\RecommendationStatus;
use DomainException;

/**
 * The lifecycle table said no. Thrown by TransitionEvaluationStatus and
 * TransitionRecommendationStatus — the only writers of those two status
 * columns — before any permission or precondition is considered, because an
 * impossible move is impossible for everyone.
 */
class InvalidEvaluationTransition extends DomainException
{
    public static function between(EvaluationStatus $from, EvaluationStatus $to): self
    {
        $allowed = array_map(
            fn (EvaluationStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'An evaluation cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is final' : implode(', ', $allowed),
        ));
    }

    public static function betweenRecommendationStates(RecommendationStatus $from, RecommendationStatus $to): self
    {
        $allowed = array_map(
            fn (RecommendationStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A recommendation cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is closed' : implode(', ', $allowed),
        ));
    }
}
