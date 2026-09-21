<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionStatus;
use App\Models\SiteInspection;
use App\Models\User;

/**
 * Takes a visit out of the diary — a flooded access road, a contractor off
 * site, a project suspended overnight.
 *
 * The reason is mandatory and enforced in the chokepoint. It is not a
 * formality: an MDA's inspection coverage is a compliance number, and a visit
 * that silently disappears is indistinguishable from one nobody bothered to
 * make. The cancellation is attributed, timestamped and written to the
 * append-only ledger, where oversight reads it.
 *
 * A filed report is never cancellable — the lifecycle table refuses
 * `submitted → cancelled` for everyone. A government record is corrected by
 * another record, not withdrawn.
 */
class CancelInspection
{
    public function __invoke(SiteInspection $inspection, User $actor, string $reason): SiteInspection
    {
        return app(TransitionInspectionStatus::class)(
            $inspection,
            InspectionStatus::Cancelled,
            $actor,
            $reason,
        );
    }
}
