<?php

namespace App\Actions\Reporting;

use App\Enums\ProgressReportStatus;
use App\Enums\ReportObligationStatus;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\ProgressReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Throws away a draft that was started by mistake (progress-reporting.md §4).
 *
 * DRAFTS ONLY. A `returned` report is not discardable — it has been filed,
 * read and sent back, and that history is a government record; the author fixes
 * it instead. Anything past `submitted` is untouchable for the same reason.
 *
 * The obligation returns to `pending` so the deadline engine picks the project
 * up again: a discarded draft must not read as "this MDA reported".
 */
class DiscardProgressReportDraft
{
    public function __invoke(ProgressReport $report, User $actor): void
    {
        Gate::forUser($actor)->authorize('discard', $report);

        if ($report->status !== ProgressReportStatus::Draft) {
            throw ReportRuleViolation::discardRequiresDraft($report->status);
        }

        DB::transaction(function () use ($report): void {
            $obligation = $report->loadMissing('obligation')->obligation;

            if ($obligation !== null && $obligation->progress_report_id === $report->id) {
                $obligation->forceFill([
                    'status' => ReportObligationStatus::Pending,
                    'progress_report_id' => null,
                    'fulfilled_at' => null,
                    'submitted_late' => false,
                ])->save();
            }

            // Soft delete: the row leaves every query (which frees the one
            // live report per window) and stays on disk for the record.
            $report->delete();
        });
    }
}
