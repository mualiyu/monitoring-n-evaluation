<?php

namespace App\Jobs\Inspections;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Inspections\Concerns\ResolvesInspectionRecipients;
use App\Models\SiteInspection;
use App\Notifications\Inspections\InspectionReportOverdue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * The single "your report is late" notice, delivered to the inspector who owes
 * it and to the officers waiting for it.
 *
 * The job carries NO idempotency logic of its own and needs none: the sweep
 * stamped `report_overdue_notified_at` under a row lock before dispatching, so
 * a replayed job re-sends one notice at worst — while a missing gate would
 * re-send every morning until the report arrived.
 *
 * IDS, NOT MODELS: see NotifyInspectionScheduled.
 */
class NotifyInspectionReportOverdue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesInspectionRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $inspectionId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $inspection = SiteInspection::query()
            ->with(['project.tenant', 'tenant', 'leadInspector'])
            ->find($this->inspectionId);

        if ($inspection === null || $inspection->status->isFiled()) {
            return;
        }

        $daysLate = $inspection->report_due_at === null
            ? 1
            : (int) $inspection->report_due_at->startOfDay()->diffInDays(now()->startOfDay(), false);

        $recipients = $this->inspector($inspection)
            ->merge($this->reviewers($inspection))
            ->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new InspectionReportOverdue($inspection, $daysLate));
    }
}
