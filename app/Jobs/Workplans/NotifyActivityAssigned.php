<?php

namespace App\Jobs\Workplans;

use App\Jobs\Concerns\TenantAware;
use App\Models\User;
use App\Models\WorkplanActivity;
use App\Notifications\Workplans\WorkplanActivityAssigned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * "You own this activity." Ids, not models — see the note on
 * NotifyWorkplanChain: a serialized tenant-owned model is re-queried before
 * the tenancy middleware runs.
 *
 * Re-reads the owner from the row rather than trusting a constructor argument,
 * so a reassignment that happened between dispatch and execution tells the
 * right person.
 */
class NotifyActivityAssigned implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $activityId,
        private readonly int $actorId,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $activity = WorkplanActivity::query()
            ->with(['workplan.tenant'])
            ->find($this->activityId);

        if ($activity === null || $activity->owner_id === null || $activity->owner_id === $this->actorId) {
            return;
        }

        $owner = User::query()->whereKey($activity->owner_id)->first();

        if ($owner === null || ! $owner->is_active) {
            return;
        }

        Notification::send($owner, new WorkplanActivityAssigned($activity));
    }
}
