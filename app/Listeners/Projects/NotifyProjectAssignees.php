<?php

namespace App\Listeners\Projects;

use App\Events\Projects\ProjectStatusChanged;
use App\Jobs\Projects\NotifyProjectStatusChange;

/**
 * Hands the notification fan-out to a queued, tenant-aware job.
 *
 * Why not a queued LISTENER: Laravel serializes a queued listener as
 * class + event data and re-instantiates it in the worker, so a tenant id
 * captured in its constructor never survives the trip — the job middleware
 * would find no tenant to rebind and the assignees query would run unscoped
 * (which the fail-closed TenantScope turns into an exception, loudly, in a
 * worker at 3am). A real Job carries its own serialized state, so
 * TenantAware works as designed.
 */
class NotifyProjectAssignees
{
    public function handle(ProjectStatusChanged $event): void
    {
        NotifyProjectStatusChange::dispatch(
            $event->project->id,
            $event->from?->value,
            $event->to->value,
            $event->actorId,
            $event->reason,
        );
    }
}
