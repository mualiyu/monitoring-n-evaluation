<?php

namespace App\Jobs\Lifecycle;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Lifecycle\Concerns\ResolvesLifecycleRecipients;
use App\Models\CommencementNotice;
use App\Notifications\Lifecycle\CommencementNoticeIssued;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the contractor's people on the project that they have been instructed
 * to commence.
 *
 * IDS, NOT MODELS: SerializesModels restores relations in __unserialize — i.e.
 * BEFORE the job middleware binds tenancy — so a serialized notice would be
 * re-queried with no tenant bound and the fail-closed scope would throw before
 * handle() ever ran. Everything is loaded inside handle(), where
 * SetTenantContext has already put the worker in the right workspace.
 */
class NotifyCommencementNoticeIssued implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesLifecycleRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $noticeId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        // `tenant` and `project` are eager-loaded because the mail builds the
        // workspace URL and names the project; lazy loading is prevented
        // outside production, which is the point.
        $notice = CommencementNotice::query()
            ->with(['project', 'tenant'])
            ->find($this->noticeId);

        if ($notice === null) {
            return; // withdrawn between dispatch and execution
        }

        $recipients = $this->contractorsOn($notice->project_id);

        if ($recipients->isEmpty()) {
            // Normal, not exceptional: many MDAs record work on behalf of
            // contractors who hold no account. The served PDF is the notice;
            // this channel is a convenience on top of it.
            return;
        }

        Notification::send($recipients, new CommencementNoticeIssued($notice));
    }
}
