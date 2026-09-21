<?php

namespace App\Exceptions\Inspections;

use App\Enums\InspectionStatus;
use DomainException;

/**
 * The lifecycle table said no. Thrown by TransitionInspectionStatus — the only
 * writer of SiteInspection::$status — before any permission or precondition is
 * considered, because an impossible transition is impossible for everyone.
 */
class InvalidInspectionTransition extends DomainException
{
    public static function between(InspectionStatus $from, InspectionStatus $to): self
    {
        $allowed = array_map(
            fn (InspectionStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A site inspection cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is final' : implode(', ', $allowed),
        ));
    }
}
