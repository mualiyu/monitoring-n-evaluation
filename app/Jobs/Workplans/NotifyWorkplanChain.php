<?php

namespace App\Jobs\Workplans;

use App\Enums\Role;
use App\Enums\WorkplanStatus;
use App\Jobs\Concerns\TenantAware;
use App\Models\User;
use App\Models\Workplan;
use App\Notifications\Workplans\WorkplanChainUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the next person in the chain that a work plan has moved.
 *
 * IDS, NOT MODELS, on purpose: SerializesModels restores relations in
 * __unserialize — i.e. BEFORE the job middleware binds tenancy — so a
 * serialized Workplan would be re-queried with no tenant bound and the
 * fail-closed scope would throw before handle() ever ran. Everything is loaded
 * inside handle(), where SetTenantContext has already put the worker in the
 * right workspace, which is also what makes the mail render with the right
 * MDA's branding and terminology.
 *
 * Recipients are a property of the STEP:
 *   submitted            → whoever may approve in this MDA
 *   approved / active    → the owner and the author
 *   rejected             → the owner and the author, who have to fix it
 *   closed               → the owner
 */
class NotifyWorkplanChain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $workplanId,
        private readonly string $toStatus,
        private readonly int $actorId,
        private readonly ?string $reason = null,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $workplan = Workplan::query()->with('tenant')->find($this->workplanId);

        if ($workplan === null) {
            return; // discarded between dispatch and execution — nothing to say
        }

        $to = WorkplanStatus::from($this->toStatus);

        // THE ROW IS THE AUTHORITY, NOT THE CONSTRUCTOR ARGUMENT. The dispatch
        // happens after the transition's own commit, but ApproveWorkplan wraps
        // the approve+activate pair in an outer transaction — so a step that
        // is rolled back afterwards has already queued its mail. Trusting
        // $this->toStatus would announce an approval the database never kept.
        //
        // Re-reading costs one query and makes the job idempotent under replay
        // too. The trade-off is deliberate: a step the chain has since moved
        // past goes unannounced rather than announced wrongly — and the move
        // that superseded it sends its own notification anyway.
        if ($workplan->status !== $to) {
            return;
        }

        $recipients = $this->recipientsFor($workplan, $to)
            // The actor knows what they just did; telling them is noise, and
            // noise is how people learn to ignore this channel.
            ->reject(fn (User $user): bool => $user->id === $this->actorId)
            ->filter(fn (User $user): bool => $user->is_active);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new WorkplanChainUpdated($workplan, $to, $this->reason));
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(Workplan $workplan, WorkplanStatus $to): Collection
    {
        return match ($to) {
            WorkplanStatus::Submitted => $this->tenantUsersWithRole([Role::MdaAdmin]),
            WorkplanStatus::Approved,
            WorkplanStatus::Active,
            WorkplanStatus::Rejected,
            WorkplanStatus::Closed,
            WorkplanStatus::Draft => $this->ownerAndAuthor($workplan),
        };
    }

    /**
     * The workspace's holders of a role. spatie resolves the `role` scope
     * against the CURRENT permission team, which SetTenantContext has already
     * bound — so this can only ever return users of this MDA.
     *
     * @param  list<Role>  $roles
     * @return Collection<int, User>
     */
    private function tenantUsersWithRole(array $roles): Collection
    {
        return User::query()
            ->role(array_map(fn (Role $role): string => $role->value, $roles))
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function ownerAndAuthor(Workplan $workplan): Collection
    {
        $ids = array_values(array_unique(array_filter([
            $workplan->owner_id,
            $workplan->created_by_id,
        ])));

        return User::query()->whereIn('id', $ids)->get();
    }
}
