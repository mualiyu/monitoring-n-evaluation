<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionStatus;
use App\Models\SiteInspection;
use App\Models\User;

/**
 * The inspector has arrived and opened the conduct form (plan §4).
 *
 * Thin by design — the whole act is one guarded transition — but it exists as
 * its own Action rather than as a chokepoint call in a Livewire component,
 * because `scheduled → in_progress` is where the visit's real clock starts:
 * `conducted_at` is stamped NOW and `report_due_at` is derived from it and
 * frozen. A component that called the chokepoint directly would be the second
 * place in the codebase that knows the visit happens when the form opens.
 *
 * Idempotent: opening the form twice (a refresh, a second tab, a reconnect
 * after the 3G dropped) returns the same in-progress inspection rather than
 * re-stamping the visit time, which would quietly move the report deadline
 * every time a field monitor's phone lost signal.
 */
class StartInspection
{
    public function __invoke(SiteInspection $inspection, User $actor): SiteInspection
    {
        if ($inspection->status === InspectionStatus::InProgress) {
            return $inspection;
        }

        return app(TransitionInspectionStatus::class)(
            $inspection,
            InspectionStatus::InProgress,
            $actor,
        );
    }
}
