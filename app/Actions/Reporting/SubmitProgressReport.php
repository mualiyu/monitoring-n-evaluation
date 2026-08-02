<?php

namespace App\Actions\Reporting;

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Enums\ProgressReportStatus;
use App\Enums\ReportObligationStatus;
use App\Models\ProgressReport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Files the return (progress-reporting.md §4). Both entry paths land here —
 * the consultant filing their own and the officer filing on a contractor's
 * behalf — and `submitted_by_id` records which person actually pressed it,
 * because that identity is what the separation guards read afterwards.
 *
 * Everything that makes a submission legal (the window is open or late
 * submission is allowed, a narrative exists, a downward claim is explained,
 * the chain permits it) is asserted by TransitionProgressReportStatus, the one
 * writer of `status`. This Action owns the two consequences: the obligation
 * becomes fulfilled, and the compliance board is busted.
 *
 * FULFILMENT IS MEASURED AT SUBMISSION, not approval (§3.1). The manual's
 * rewards and sanctions ask whether the MDA filed on time; counting only
 * approved returns would change the denominator and punish an MDA for its
 * director's inaction. Approval quality is a separate metric.
 */
class SubmitProgressReport
{
    public function __construct(private readonly TransitionProgressReportStatus $transition) {}

    public function __invoke(ProgressReport $report, User $actor): ProgressReport
    {
        DB::transaction(function () use ($report, $actor): void {
            ($this->transition)($report, ProgressReportStatus::Submitted, $actor);

            $this->fulfilObligation($report);
        });

        Cache::forget(BuildComplianceLeagueTable::cacheKey($report->reporting_period_id));

        return $report;
    }

    /**
     * The obligation is fulfilled ONCE, at first submission. A report returned
     * for rework and resubmitted does not re-stamp `fulfilled_at` or
     * re-evaluate lateness — on-time is judged at the moment the MDA filed
     * (§9.6), and the report row carries the same rule.
     */
    private function fulfilObligation(ProgressReport $report): void
    {
        $obligation = $report->loadMissing('obligation')->obligation;

        if ($obligation === null || $obligation->status === ReportObligationStatus::Waived) {
            return;
        }

        $changes = [
            'status' => ReportObligationStatus::Fulfilled,
            'progress_report_id' => $report->id,
        ];

        if ($obligation->fulfilled_at === null) {
            $changes['fulfilled_at'] = now();
            $changes['submitted_late'] = $report->submitted_late;
        }

        // forceFill: the compliance columns are deliberately not fillable, so
        // this Action and WaiveReportObligation are the only writers.
        $obligation->forceFill($changes)->save();
    }
}
