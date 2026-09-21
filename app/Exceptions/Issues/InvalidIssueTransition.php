<?php

namespace App\Exceptions\Issues;

use App\Enums\IssueStatus;
use DomainException;

/**
 * The lifecycle table said no. Thrown by App\Actions\Issues\TransitionIssueStatus
 * — the only writer of Issue::$status — before any permission or precondition
 * is considered, because an impossible transition is impossible for everyone.
 */
class InvalidIssueTransition extends DomainException
{
    public static function between(IssueStatus $from, IssueStatus $to): self
    {
        $allowed = array_map(
            fn (IssueStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'An issue cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is closed' : implode(', ', $allowed),
        ));
    }
}
