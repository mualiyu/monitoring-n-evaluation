<?php

namespace App\Enums;

/**
 * How a progress report's figures got into the system (progress-reporting.md
 * §2.1). Provenance is recorded rather than blurred: an auditor must be able to
 * see that an M&E officer typed a contractor's numbers rather than the
 * contractor filing them.
 *
 * Switching a client between the two models is a permission-matrix change
 * (grant/revoke `reports.create` + `reports.submit` to Consultant), never a
 * schema change.
 */
enum ReportEntryMode: string
{
    /** The assigned consultant filed their own return. */
    case SelfService = 'self_service';

    /** An MDA officer entered the contractor's return on their behalf. */
    case OnBehalf = 'on_behalf';

    public function label(): string
    {
        return match ($this) {
            self::SelfService => __('Filed by the consultant'),
            self::OnBehalf => __('Entered on behalf'),
        };
    }
}
