<?php

namespace App\Jobs\Reporting;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Reporting\Concerns\ResolvesObligationRecipients;
use App\Models\ReportObligation;
use App\Notifications\Reporting\ReportObligationDueSoon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * One rung of the reminder ladder, delivered.
 *
 * IDS, NOT MODELS: SerializesModels restores relations before the job
 * middleware binds tenancy, and a tenant-owned model re-queried with no tenant
 * bound throws on the fail-closed scope. The obligation is loaded inside
 * handle(), where SetTenantContext has put the worker in the right workspace.
 *
 * The job carries NO idempotency logic of its own and needs none: the sweep
 * advanced `reminder_stage` under a row lock before dispatching, so a replayed
 * job re-sends one rung at worst — while a missing counter would re-send every
 * rung, every morning.
 */
class NotifyReportObligationDueSoon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesObligationRecipients, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $obligationId,
        private readonly int $daysBefore,
    ) {
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

        Notification::send($recipients, new ReportObligationDueSoon($obligation, $this->daysBefore));
    }
}
