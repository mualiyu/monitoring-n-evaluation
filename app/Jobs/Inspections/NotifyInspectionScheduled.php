<?php

namespace App\Jobs\Inspections;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\Inspections\Concerns\ResolvesInspectionRecipients;
use App\Models\SiteInspection;
use App\Notifications\Inspections\InspectionScheduled;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the lead inspector a visit is in their diary.
 *
 * IDS, NOT MODELS: SerializesModels restores relations in __unserialize — i.e.
 * BEFORE the job middleware binds tenancy — so a serialized SiteInspection
 * would be re-queried with no tenant bound and the fail-closed scope would
 * throw before handle() ever ran. The inspection is loaded inside handle(),
 * where SetTenantContext has put the worker in the right workspace.
 */
class NotifyInspectionScheduled implements ShouldQueue
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

        if ($inspection === null) {
            return; // cancelled and purged between dispatch and execution
        }

        $recipients = $this->inspector($inspection);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new InspectionScheduled($inspection));
    }
}
