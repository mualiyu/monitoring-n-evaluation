<?php

namespace App\Exceptions\Indicators;

use App\Enums\IndicatorReadingStatus;
use DomainException;

/**
 * The validation chain said no. Thrown by
 * App\Actions\Indicators\TransitionIndicatorReadingStatus — the only writer of
 * IndicatorReading::$status — before any permission or precondition is
 * considered, because an impossible transition is impossible for everyone.
 */
class InvalidReadingTransition extends DomainException
{
    public static function between(IndicatorReadingStatus $from, IndicatorReadingStatus $to): self
    {
        $allowed = array_map(
            fn (IndicatorReadingStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'An indicator reading cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === []
                ? 'nothing — it is published, and a published figure is corrected by recording another reading'
                : implode(', ', $allowed),
        ));
    }
}
