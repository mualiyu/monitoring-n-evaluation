<?php

namespace App\Exceptions\Reporting;

use App\Enums\ProgressReportStatus;
use DomainException;

/**
 * The chain table said no (progress-reporting.md §2). Thrown by
 * TransitionProgressReportStatus — the only writer of ProgressReport::$status —
 * before any permission or precondition is considered, because an impossible
 * transition is impossible for everyone.
 */
class InvalidReportTransition extends DomainException
{
    public static function between(ProgressReportStatus $from, ProgressReportStatus $to): self
    {
        $allowed = array_map(
            fn (ProgressReportStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A progress report cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is approved and final' : implode(', ', $allowed),
        ));
    }
}
