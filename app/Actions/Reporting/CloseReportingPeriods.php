<?php

namespace App\Actions\Reporting;

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Enums\ReportObligationStatus;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Closes the book on a window (progress-reporting.md §3): once a period is past
 * its hard close, everything still `pending` becomes `missed` — the permanent
 * entry on the compliance league table.
 *
 * A period only has a hard close when the instance refuses late returns
 * (`reporting.allow_late_submission = false`), or when a secretariat sets one
 * on a specific window by hand. Under the default configuration `closes_at` is
 * null, nothing is ever swept, and a late MDA files a flagged-late return
 * instead of being locked out — which is the design's position (§9.5): a
 * blocked MDA simply never reports, and that is worse data than a late one.
 *
 * Marking a miss is a compliance write, so the league-table cache is busted for
 * every period the sweep touched.
 */
class CloseReportingPeriods
{
    /**
     * @return int the number of obligations marked missed
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        /** @var list<int> $periodIds */
        $periodIds = ReportingPeriod::query()
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', $asOf)
            ->pluck('id')
            ->all();

        if ($periodIds === []) {
            return 0;
        }

        $current = app(CurrentTenant::class);
        $missed = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $missed += $current->runAs($tenant, function () use ($periodIds): int {
                $count = 0;

                $obligations = ReportObligation::query()
                    ->outstanding()
                    ->whereIn('reporting_period_id', $periodIds)
                    ->cursor();

                foreach ($obligations as $obligation) {
                    // Row by row rather than a mass update: `status` is not
                    // fillable, activitylog records the change per obligation,
                    // and a compliance miss is exactly the kind of write that
                    // should leave an audit trail per record.
                    $obligation->forceFill(['status' => ReportObligationStatus::Missed])->save();
                    $count++;
                }

                return $count;
            });
        }

        if ($missed > 0) {
            foreach ($periodIds as $periodId) {
                Cache::forget(BuildComplianceLeagueTable::cacheKey($periodId));
            }
        }

        return $missed;
    }
}
