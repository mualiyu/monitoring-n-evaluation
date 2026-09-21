<?php

namespace App\Jobs\Workplans;

use App\Enums\Role;
use App\Jobs\Concerns\TenantAware;
use App\Models\User;
use App\Models\WorkplanActivity;
use App\Notifications\Workplans\WorkplanActivityOverdue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * "This activity has slipped." To the owner AND the MDA administrator, once —
 * the idempotence gate is on the row (`overdue_notified_at`), advanced under a
 * lock by FlagOverdueActivities before this job is ever dispatched.
 *
 * Re-checks the row on arrival: an officer who recorded 100% in the minutes
 * between the sweep and the worker should not then be told the work is late.
 */
class NotifyActivityOverdue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(private readonly int $activityId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $activity = WorkplanActivity::query()
            ->with(['workplan.tenant'])
            ->find($this->activityId);

        if ($activity === null || ! $activity->isOverdue()) {
            return;
        }

        $daysLate = (int) $activity->planned_end->startOfDay()->diffInDays(now()->startOfDay(), false);

        $recipients = $this->recipients($activity)->filter(fn (User $user): bool => $user->is_active);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new WorkplanActivityOverdue($activity, max(1, $daysLate)));
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(WorkplanActivity $activity): Collection
    {
        // spatie resolves the `role` scope against the CURRENT permission
        // team, which SetTenantContext has already bound — so the admins can
        // only ever be this MDA's.
        $admins = User::query()->role(Role::MdaAdmin->value)->get();

        if ($activity->owner_id === null) {
            return $admins;
        }

        $owner = User::query()->whereKey($activity->owner_id)->first();

        return $owner === null
            ? $admins
            : $admins->reject(fn (User $user): bool => $user->id === $owner->id)->push($owner)->values();
    }
}
