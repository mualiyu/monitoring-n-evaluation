<?php

namespace App\Jobs\Issues;

use App\Jobs\Concerns\TenantAware;
use App\Models\Issue;
use App\Models\User;
use App\Notifications\Issues\IssueAssigned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the new owner that an obstruction is theirs to clear.
 *
 * IDS, NOT MODELS, on purpose: SerializesModels restores relations in
 * __unserialize — i.e. BEFORE the job middleware binds tenancy — so a
 * serialized Issue would be re-queried with no tenant bound and the
 * fail-closed scope would throw before handle() ever ran. Everything is loaded
 * inside handle(), where SetTenantContext has already put the worker in the
 * right workspace, which is also what makes the mail render with the right
 * MDA's branding.
 */
class NotifyIssueAssigned implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $issueId,
        private readonly int $ownerId,
        private readonly int $actorId,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $issue = Issue::query()->with('project')->find($this->issueId);

        if ($issue === null) {
            return; // deleted between dispatch and execution — nothing to say
        }

        // THE ROW IS THE AUTHORITY, NOT THE CONSTRUCTOR ARGUMENT. Assignment
        // is one save and a reassignment moments later is perfectly ordinary;
        // trusting the argument would tell somebody an issue is theirs when
        // the register says it is not, and nobody would ever correct that
        // mail. Re-reading also makes the job idempotent under replay.
        if ($issue->owner_id !== $this->ownerId) {
            return;
        }

        $owner = User::query()->where('is_active', true)->find($this->ownerId);

        if ($owner === null || $owner->id === $this->actorId) {
            return;
        }

        $owner->notify(new IssueAssigned($issue));
    }
}
