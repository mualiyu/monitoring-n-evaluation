<?php

namespace App\Exceptions\Issues;

use App\Enums\ExceptionStatus;
use DomainException;

/**
 * The exception-report chain said no. Thrown by
 * App\Actions\Issues\TransitionExceptionStatus before any permission is
 * considered.
 */
class InvalidExceptionTransition extends DomainException
{
    public static function between(ExceptionStatus $from, ExceptionStatus $to): self
    {
        $allowed = array_map(
            fn (ExceptionStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'An exception report cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — the deviation is resolved' : implode(', ', $allowed),
        ));
    }
}
