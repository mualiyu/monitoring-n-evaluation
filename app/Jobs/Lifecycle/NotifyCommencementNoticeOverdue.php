<?php

namespace App\Jobs\Lifecycle;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Lifecycle\Concerns\ResolvesLifecycleRecipients;
use App\Models\CommencementNotice;
use App\Notifications\Lifecycle\CommencementNoticeOverdue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the MDA administrator that an award has gone past its statutory window
 * with no notice served. Sent once per notice, ever — the
 * `overdue_notified_at` stamp written under a row lock before dispatch is the
 * guarantee.
 *
 * IDS, NOT MODELS: see NotifyCommencementNoticeIssued.
 */
class NotifyCommencementNoticeOverdue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesLifecycleRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $noticeId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $notice = CommencementNotice::query()
            ->with(['project', 'tenant'])
            ->find($this->noticeId);

        if ($notice === null) {
            return;
        }

        // Served between the sweep and the worker — the complaint is stale and
        // sending it would teach the recipient that this channel is noise.
        if ($notice->status->isServed()) {
            return;
        }

        $recipients = $this->mdaAdmins();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CommencementNoticeOverdue(
            $notice,
            max($notice->daysLate(), 0),
        ));
    }
}
