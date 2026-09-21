<?php

namespace App\Jobs\Lifecycle;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Lifecycle\Concerns\ResolvesLifecycleRecipients;
use App\Models\Certificate;
use App\Notifications\Lifecycle\CompletionCertificateIssued;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Announces a completion certificate to the MDA administrator and to state
 * oversight.
 *
 * TWO SENDS, NOT ONE LIST: the two audiences get different links (workspace
 * versus state register), because a URL on a subdomain the recipient cannot
 * reach is a dead end. They also live in different permission teams — see
 * ResolvesLifecycleRecipients.
 *
 * IDS, NOT MODELS: see NotifyCommencementNoticeIssued.
 */
class NotifyCertificateIssued implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesLifecycleRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $certificateId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $certificate = Certificate::query()
            ->with(['project', 'tenant'])
            ->find($this->certificateId);

        if ($certificate === null) {
            return;
        }

        $admins = $this->mdaAdmins();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new CompletionCertificateIssued($certificate));
        }

        $oversight = $this->stateOversight();

        if ($oversight->isNotEmpty()) {
            Notification::send($oversight, new CompletionCertificateIssued($certificate, forOversight: true));
        }
    }
}
