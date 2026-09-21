<?php

namespace App\Actions\Consolidation;

use App\Enums\ConsolidationStatus;
use App\Models\ConsolidatedReport;
use App\Models\User;

/**
 * Send the roll-up up the chain (manual digest §1: MDA → Secretariat →
 * Commissioner; this is the last hop).
 *
 * The preconditions — figures compiled, summary written — live in the
 * chokepoint, not here, so that they hold whichever screen or console command
 * triggers the move.
 */
class SubmitConsolidationForReview
{
    public function __invoke(ConsolidatedReport $report, User $actor): ConsolidatedReport
    {
        return app(TransitionConsolidationStatus::class)(
            $report,
            ConsolidationStatus::InReview,
            $actor,
        );
    }
}
