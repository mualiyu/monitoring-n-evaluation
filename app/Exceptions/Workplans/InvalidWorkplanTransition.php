<?php

namespace App\Exceptions\Workplans;

use App\Enums\WorkplanStatus;
use DomainException;

/**
 * The chain table said no. Thrown by TransitionWorkplanStatus — the only
 * writer of Workplan::$status — before any permission or precondition is
 * considered, because an impossible transition is impossible for everyone.
 */
class InvalidWorkplanTransition extends DomainException
{
    public static function between(WorkplanStatus $from, WorkplanStatus $to): self
    {
        $allowed = array_map(
            fn (WorkplanStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A work plan cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — the year is closed' : implode(', ', $allowed),
        ));
    }
}
