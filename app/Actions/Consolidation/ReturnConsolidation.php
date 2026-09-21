<?php

namespace App\Actions\Consolidation;

use App\Enums\ConsolidationStatus;
use App\Models\ConsolidatedReport;
use App\Models\User;

/**
 * Send a roll-up back to the secretariat's desk with a reason.
 *
 * It lands on `compiling`, not `draft`: that is where the narrative lives, and
 * the figures the reviewer questioned are still attached to argue with. The
 * reason is mandatory and enforced in the chokepoint — a return with no
 * explanation leaves the secretariat guessing at what a Commissioner objected
 * to.
 */
class ReturnConsolidation
{
    public function __invoke(ConsolidatedReport $report, User $actor, string $reason): ConsolidatedReport
    {
        return app(TransitionConsolidationStatus::class)(
            $report,
            ConsolidationStatus::Compiling,
            $actor,
            $reason,
        );
    }
}
