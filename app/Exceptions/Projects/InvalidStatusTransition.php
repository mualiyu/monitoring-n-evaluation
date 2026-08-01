<?php

namespace App\Exceptions\Projects;

use App\Enums\ProjectStatus;
use DomainException;

/**
 * The transition table said no (projects-module.md §2). Thrown by
 * TransitionProjectStatus — the only writer of Project::$status — before any
 * permission or precondition is considered, because an impossible transition
 * is impossible for everyone.
 */
class InvalidStatusTransition extends DomainException
{
    public static function between(ProjectStatus $from, ProjectStatus $to): self
    {
        $allowed = array_map(
            fn (ProjectStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A project cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is a terminal state' : implode(', ', $allowed),
        ));
    }
}
