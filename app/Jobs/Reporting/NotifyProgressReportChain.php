<?php

namespace App\Jobs\Reporting;

use App\Enums\ProgressReportStatus;
use App\Enums\Role;
use App\Jobs\Concerns\TenantAware;
use App\Models\ProgressReport;
use App\Models\User;
use App\Notifications\Reporting\ProgressReportChainUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the next person in the approval chain that a return has moved.
 *
 * IDS, NOT MODELS, on purpose: SerializesModels restores relations in
 * __unserialize — i.e. BEFORE the job middleware binds tenancy — so a
 * serialized ProgressReport would be re-queried with no tenant bound and the
 * fail-closed scope would throw before handle() ever ran. Everything is loaded
 * inside handle(), where SetTenantContext has already put the worker in the
 * right workspace, which is also what makes the mail render with the right
 * MDA's branding and terminology.
 *
 * Recipients are a property of the STEP (design §3):
 *   submitted → whoever may review in this MDA
 *   reviewed  → whoever may approve
 *   returned  → the author, who has to fix it
 *   approved  → the author and the project manager
 */
class NotifyProgressReportChain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $reportId,
        private readonly string $toStatus,
        private readonly int $actorId,
        private readonly ?string $reason = null,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $report = ProgressReport::query()
            ->with(['project.tenant', 'reportingPeriod'])
            ->find($this->reportId);

        if ($report === null) {
            return; // discarded between dispatch and execution — nothing to say
        }

        $to = ProgressReportStatus::from($this->toStatus);

        // THE ROW IS THE AUTHORITY, NOT THE CONSTRUCTOR ARGUMENT. The dispatch
        // happens inside the writing transaction (TransitionProgressReportStatus
        // fires it after its own commit, but ApproveProgressReport wraps that in
        // an outer transaction), so a step that is rolled back afterwards has
        // already queued its mail. Approval is where that bites: the propagation
        // to the project runs after the transition and can refuse — a certified
        // project's record is frozen — which rolls the approval back entirely.
        // Trusting $this->toStatus would then tell the author and the project
        // manager that a return was approved when the database says it is still
        // awaiting a decision, and nobody would ever correct that mail.
        //
        // Re-reading costs one query and makes the job idempotent under replay
        // as well. The trade-off is deliberate: a step the chain has since moved
        // past goes unannounced rather than announced wrongly — and by then the
        // move that superseded it has sent its own notification anyway.
        if ($report->status !== $to) {
            return;
        }
        $recipients = $this->recipientsFor($report, $to)
            // The actor knows what they just did; telling them is noise, and
            // noise is how people learn to ignore this channel.
            ->reject(fn (User $user): bool => $user->id === $this->actorId)
            ->filter(fn (User $user): bool => $user->is_active);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ProgressReportChainUpdated($report, $to, $this->reason));
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(ProgressReport $report, ProgressReportStatus $to): Collection
    {
        return match ($to) {
            ProgressReportStatus::Submitted => $this->tenantUsersWithRole([Role::MdaAdmin, Role::MeOfficer]),
            ProgressReportStatus::Reviewed => $this->tenantUsersWithRole([Role::MdaAdmin]),
            ProgressReportStatus::Returned, ProgressReportStatus::Approved, ProgressReportStatus::Draft => $this->authorAndManager($report),
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
    private function authorAndManager(ProgressReport $report): Collection
    {
        $ids = array_values(array_unique(array_filter([
            $report->created_by_id,
            $report->project->manager_id,
        ])));

        return User::query()->whereIn('id', $ids)->get();
    }
}
