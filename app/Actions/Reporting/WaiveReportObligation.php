<?php

namespace App\Actions\Reporting;

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Enums\ReportObligationStatus;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\ReportObligation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Excuses one obligation, on the record (progress-reporting.md §4).
 *
 * Real programmes produce windows nobody can report against — a project
 * suspended by a court injunction, a site inaccessible through a flood, a
 * contract terminated mid-period. Without a waiver the only options are a
 * fabricated return or a permanent black mark on the league table, and both
 * corrupt the compliance number the manual's sanctions rest on.
 *
 * A waiver therefore needs authority (`reports.waive` — MDA admin or state
 * oversight) and a stated reason, and it silences the deadline engine for that
 * row: `ReportObligationStatus::isOutstanding()` is what every sweep filters on.
 */
class WaiveReportObligation
{
    public function __invoke(ReportObligation $obligation, User $actor, string $reason): ReportObligation
    {
        Gate::forUser($actor)->authorize('waive', $obligation);

        $reason = trim($reason);

        if ($reason === '') {
            throw ReportRuleViolation::waiverRequiresReason();
        }

        if (! $obligation->isOutstanding()) {
            throw ReportRuleViolation::obligationNotOutstanding($obligation->status->value);
        }

        // forceFill: the compliance columns are not fillable, so the waiver is
        // an explicit act by this Action and nothing else.
        $obligation->forceFill([
            'status' => ReportObligationStatus::Waived,
            'waived_by_id' => $actor->id,
            'waived_at' => now(),
            'waiver_reason' => $reason,
        ])->save();

        Cache::forget(BuildComplianceLeagueTable::cacheKey($obligation->reporting_period_id));

        return $obligation;
    }
}
