<?php

namespace App\Actions\Inspections;

use App\Jobs\Inspections\NotifyInspectionReportOverdue;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The other half of the scheduling engine: a visit that happened but whose
 * Field Trip Report never arrived.
 *
 * This is the failure mode the manual's whole reporting discipline is aimed at
 * — an inspector walks a site, sees a problem, and the finding dies in a
 * notebook. `inspections.report_due_days` (3 by default) is the state's answer
 * to how long that may take; this sweep is what makes the number mean
 * something.
 *
 * IDEMPOTENT, structurally: `report_overdue_notified_at` is a null-check gate
 * advanced under a row lock in the same transaction as the dispatch. The
 * notice goes out exactly once, ever — a daily nag on an outstanding report
 * trains inspectors to filter this sender, and the standing signal lives on
 * the board where the M&E officer can see every late report at once, not in
 * their inbox.
 *
 * `report_due_at` is a stored snapshot (set when the visit opened), so a state
 * that retunes `report_due_days` does not retroactively make filed reports
 * late or rescue overdue ones.
 */
class FlagOverdueInspectionReports
{
    /**
     * @return int the number of overdue notices dispatched
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $flagged = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $flagged += $current->runAs($tenant, fn (): int => $this->forTenant($asOf));
        }

        return $flagged;
    }

    private function forTenant(CarbonImmutable $asOf): int
    {
        $flagged = 0;

        $inspections = SiteInspection::query()
            // Still open: a filed or cancelled visit owes nothing. `open()`
            // covers scheduled + in_progress, and only an in_progress one has
            // a report_due_at at all — the where below does the narrowing
            // without a second status list to keep in sync.
            ->open()
            ->whereNotNull('report_due_at')
            ->where('report_due_at', '<', $asOf)
            ->whereNull('report_overdue_notified_at')
            ->orderBy('report_due_at')
            ->cursor();

        foreach ($inspections as $inspection) {
            $flagged += $this->flag($inspection);
        }

        return $flagged;
    }

    private function flag(SiteInspection $inspection): int
    {
        return DB::transaction(function () use ($inspection): int {
            // Re-read under the lock: between the sweep's SELECT and here, a
            // concurrent worker may already have claimed this row — or the
            // inspector may have filed the report.
            $locked = SiteInspection::query()->lockForUpdate()->find($inspection->getKey());

            if ($locked === null
                || $locked->report_overdue_notified_at !== null
                || $locked->status->isFiled()) {
                return 0;
            }

            $locked->forceFill(['report_overdue_notified_at' => now()])->save();

            NotifyInspectionReportOverdue::dispatch($locked->id);

            return 1;
        });
    }
}
