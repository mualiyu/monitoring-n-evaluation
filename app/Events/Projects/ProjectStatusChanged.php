<?php

namespace App\Events\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A project moved through its lifecycle (design §2.1 step 4). Fired ONLY by
 * App\Actions\Projects\TransitionProjectStatus, immediately after the write
 * transaction closes, carrying the transition rather than just the project:
 * a listener must never have to re-read the row to learn what changed.
 *
 * Listeners: BustPortfolioSummaryCache (synchronous — the oversight board must
 * not serve a stale count) and NotifyProjectAssignees (queues the notification
 * work, tenant-aware).
 */
class ProjectStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Project $project,
        public readonly ?ProjectStatus $from,
        public readonly ProjectStatus $to,
        public readonly int $actorId,
        public readonly ?string $reason = null,
    ) {}
}
