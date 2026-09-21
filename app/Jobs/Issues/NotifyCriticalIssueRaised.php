<?php

namespace App\Jobs\Issues;

use App\Enums\IssueSeverity;
use App\Jobs\Concerns\TenantAware;
use App\Jobs\Issues\Concerns\ResolvesIssueRecipients;
use App\Models\Issue;
use App\Notifications\Issues\CriticalIssueRaised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * A critical obstruction is announced the moment it is recorded, not when
 * somebody next opens the register. `issues.create` is held by consultants and
 * field monitors precisely so the person on site can raise one — and a
 * critical raise that then sat unread for a week would make that openness
 * pointless.
 *
 * Ids, not models — see NotifyIssueAssigned.
 */
class NotifyCriticalIssueRaised implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesIssueRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $issueId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $issue = Issue::query()->with(['project', 'project.tenant', 'raisedBy'])->find($this->issueId);

        // Severity is revisable (RecordCorrectiveAction), so an officer who
        // downgraded a raiser's reading of it within the queue's latency has
        // already answered this notice.
        if ($issue === null || $issue->severity !== IssueSeverity::Critical) {
            return;
        }

        $recipients = $this->mdaAdmins()
            ->reject(fn ($user): bool => $user->id === $issue->raised_by_id)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CriticalIssueRaised($issue));
    }
}
