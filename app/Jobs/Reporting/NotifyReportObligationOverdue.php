<?php

namespace App\Jobs\Reporting;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Reporting\Concerns\ResolvesObligationRecipients;
use App\Models\ReportObligation;
use App\Notifications\Reporting\ReportObligationOverdue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * The "this is late" notice, delivered to the people accountable for the
 * project. Sent once per obligation ever — the `overdue_notified_at` stamp
 * written under a row lock before dispatch is the guarantee.
 *
 * IDS, NOT MODELS: see NotifyReportObligationDueSoon.
 */
class NotifyReportObligationOverdue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesObligationRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $obligationId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $obligation = ReportObligation::query()
            ->with(['project.tenant', 'reportingPeriod', 'tenant'])
            ->find($this->obligationId);

        if ($obligation === null) {
            return;
        }

        $recipients = $this->accountableFor($obligation);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ReportObligationOverdue($obligation));
    }
}
