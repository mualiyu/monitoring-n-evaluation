<?php

namespace App\Exceptions\Consolidation;

use App\Enums\ConsolidationStatus;
use DomainException;

/**
 * The chain table said no. Thrown by TransitionConsolidationStatus — the only
 * writer of ConsolidatedReport::$status — before any permission or
 * precondition is considered, because an impossible transition is impossible
 * for everyone.
 */
class InvalidConsolidationTransition extends DomainException
{
    public static function between(ConsolidationStatus $from, ConsolidationStatus $to): self
    {
        $allowed = array_map(
            fn (ConsolidationStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A consolidation cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it has been published' : implode(', ', $allowed),
        ));
    }
}
