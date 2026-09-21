<?php

namespace App\Jobs\Issues;

use App\Enums\IssueStatus;
use App\Jobs\Concerns\TenantAware;
use App\Jobs\Issues\Concerns\ResolvesIssueRecipients;
use App\Models\Issue;
use App\Notifications\Issues\IssueEscalated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * The sanctions rung of the challenges register: an obstruction nobody cleared
 * inside its severity's allowance becomes the MDA admin's problem. The point
 * of escalating is that a director learns of a stalled issue without anyone
 * having to run a report.
 *
 * Ids, not models — see NotifyIssueAssigned.
 */
class NotifyIssueEscalated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesIssueRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $issueId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $issue = Issue::query()->with(['project', 'project.tenant', 'owner'])->find($this->issueId);

        if ($issue === null || $issue->status !== IssueStatus::Escalated) {
            // Re-read rather than trusted: an officer who acknowledged the
            // issue in the seconds after the sweep escalated it has already
            // answered the escalation, and telling a director about it now
            // would be announcing a state the register has moved past.
            return;
        }

        $recipients = $this->mdaAdmins();

        // The owner hears too when there is one: the escalation is, first, a
        // statement that their item is overdue.
        if ($issue->owner !== null && $issue->owner->is_active) {
            $recipients = $recipients->push($issue->owner)->unique('id')->values();
        }

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new IssueEscalated($issue));
    }
}
