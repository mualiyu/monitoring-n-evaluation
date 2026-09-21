<?php

namespace App\Actions\Consolidation;

use App\Enums\ConsolidationStatus;
use App\Models\ConsolidatedReport;
use App\Models\User;

/**
 * Release a signed roll-up to readers outside the secretariat.
 *
 * Publication is a separate, explicit act from approval (rules/security.md —
 * nothing reaches a public surface by default), and it is terminal: a figure
 * that has been quoted outside the platform is corrected by issuing the NEXT
 * consolidation, never by editing the one people already hold.
 *
 * The chokepoint refuses to publish a report carrying no frozen snapshot,
 * which should be unreachable — but "should be unreachable" is exactly the
 * state a government artifact must not be released in on trust.
 */
class PublishConsolidation
{
    public function __invoke(ConsolidatedReport $report, User $actor): ConsolidatedReport
    {
        return app(TransitionConsolidationStatus::class)(
            $report,
            ConsolidationStatus::Published,
            $actor,
        );
    }
}
