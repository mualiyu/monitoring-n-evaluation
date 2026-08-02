<?php

namespace App\Actions\Reporting;

use App\Jobs\Reporting\EscalateReportObligation;
use App\Jobs\Reporting\NotifyReportObligationOverdue;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The overdue rung of the deadline engine (progress-reporting.md §3): a return
 * that missed its statutory deadline is announced once to the people
 * accountable for it, then escalated up the chain — MDA admin first, state
 * oversight second.
 *
 * Two independent gates, both structural and both advanced under the same row
 * lock as the dispatch:
 *   `overdue_notified_at`  — null check; the "you are late" notice is sent once
 *   `escalation_stage`     — monotonic counter over `overdue_escalation_days`
 *
 * The escalation ladder answers the manual's sanctions process: the point of
 * escalating is that a director, and then the secretariat, learns of a silent
 * MDA without anyone having to run a report.
 *
 * Waived and missed obligations are silent — `outstanding()` filters on
 * `pending`, so a waiver a director signed does not keep nagging them.
 */
class FlagOverdueObligations
{
    /**
     * @return array{overdue: int, escalated: int}
     */
    public function __invoke(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $totals = ['overdue' => 0, 'escalated' => 0];

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $tenantTotals = $current->runAs($tenant, fn (): array => $this->forTenant($asOf));

            $totals['overdue'] += $tenantTotals['overdue'];
            $totals['escalated'] += $tenantTotals['escalated'];
        }

        return $totals;
    }

    /**
     * @return array{overdue: int, escalated: int}
     */
    private function forTenant(CarbonImmutable $asOf): array
    {
        $ladder = $this->ladder();
        $totals = ['overdue' => 0, 'escalated' => 0];

        $obligations = ReportObligation::query()
            ->outstanding()
            ->where('due_at', '<', $asOf)
            ->orderBy('due_at')
            ->cursor();

        foreach ($obligations as $obligation) {
            $daysLate = (int) $obligation->due_at->startOfDay()->diffInDays($asOf->startOfDay(), false);

            $totals['overdue'] += $this->notifyOverdue($obligation);
            $totals['escalated'] += $this->escalate($obligation, $ladder, $daysLate);
        }

        return $totals;
    }

    /** The "this is late" notice — exactly once per obligation, ever. */
    private function notifyOverdue(ReportObligation $obligation): int
    {
        if ($obligation->overdue_notified_at !== null) {
            return 0;
        }

        return DB::transaction(function () use ($obligation): int {
            $locked = ReportObligation::query()->lockForUpdate()->find($obligation->id);

            if ($locked === null || $locked->overdue_notified_at !== null) {
                return 0;
            }

            $locked->forceFill(['overdue_notified_at' => now()])->save();

            NotifyReportObligationOverdue::dispatch($locked->id);

            return 1;
        });
    }

    /**
     * @param  list<int>  $ladder  rungs in days-past-due, soonest first
     */
    private function escalate(ReportObligation $obligation, array $ladder, int $daysLate): int
    {
        $stage = 0;

        foreach ($ladder as $rung) {
            if ($daysLate >= $rung) {
                $stage++;
            }
        }

        if ($stage === 0 || $stage <= $obligation->escalation_stage) {
            return 0;
        }

        return DB::transaction(function () use ($obligation, $stage): int {
            $locked = ReportObligation::query()->lockForUpdate()->find($obligation->id);

            if ($locked === null || $stage <= $locked->escalation_stage) {
                return 0;
            }

            $locked->forceFill([
                'escalation_stage' => $stage,
                'escalated_at' => now(),
            ])->save();

            EscalateReportObligation::dispatch($locked->id, $stage);

            return 1;
        });
    }

    /**
     * @return list<int> rungs sorted soonest-first, so stage 1 is the MDA
     *                   admin and stage 2 reaches state oversight
     */
    private function ladder(): array
    {
        $ladder = app(SettingsRepository::class)->ints('reporting', 'overdue_escalation_days', [1, 7]);

        sort($ladder);

        return array_values(array_unique($ladder));
    }
}
