<?php

namespace App\Actions\Reporting;

use App\Actions\Projects\RecordProjectProgress;
use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The director's signature — and the ONLY point in this module where a
 * progress report touches the project's headline figures (§2.3).
 *
 * One transaction: transition (which writes the status, the chain stamps and
 * the ledger row) → propagate through App\Actions\Projects\RecordProjectProgress
 * → the audit snapshots land on the report.
 *
 * PROPAGATION GOES THROUGH THE PROJECTS CHOKEPOINT, never directly. That Action
 * is the only writer of `physical_progress` and `expenditure_to_date`, so the
 * mid-term evaluation flag (projects §2.2) and the certified field-freeze keep
 * working unchanged, and the progress-range guard applies to a reported figure
 * exactly as it applies to a typed one. It also authorizes
 * `projects.progress.update`, which MdaAdmin holds — so the chain grants no
 * authority the approver did not already have.
 *
 * The money model is deliberate: the report states the spend for ITS period,
 * and the project total is the roll-up. Nothing here reads a claimed
 * cumulative, because two sources for one figure is two answers.
 */
class ApproveProgressReport
{
    public function __construct(
        private readonly TransitionProgressReportStatus $transition,
        private readonly RecordProjectProgress $recordProgress,
    ) {}

    public function __invoke(ProgressReport $report, User $actor): ProgressReport
    {
        $project = $report->loadMissing('project')->project;

        return DB::transaction(function () use ($report, $actor, $project): ProgressReport {
            $progressBefore = $project->physical_progress;
            $cumulative = $project->expenditure_to_date->plus($report->period_expenditure);

            ($this->transition)($report, ProgressReportStatus::Approved, $actor, null, [
                // Audit snapshots: what the project said before this return
                // was believed, and what it said afterwards.
                'physical_progress_before' => $progressBefore,
                'cumulative_expenditure_snapshot' => $cumulative,
            ]);

            ($this->recordProgress)($project, $actor, $report->physical_progress_claimed, $cumulative);

            return $report;
        });
    }
}
