<?php

namespace App\Actions\Reporting;

use App\Enums\ProgressReportStatus;
use App\Enums\ReportEntryMode;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\Contractor;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opens the return for one project in one window — the entry point of both
 * paths (progress-reporting.md §2.1).
 *
 * **Self-service**: an assigned consultant starts their own return
 * (`entry_mode = self_service`). **On behalf**: an M&E officer or MDA admin
 * types the contractor's return, and `contractor_id` records *whose* figures
 * these are — provenance recorded rather than blurred, because an auditor must
 * be able to see that an officer entered a firm's numbers.
 *
 * ONE LIVE REPORT per (project, period), enforced here under a row lock rather
 * than by a DB unique: soft deletes make `unique(project_id, period_id)` either
 * block re-creation after a discard or, with `deleted_at` in the key, enforce
 * nothing. Calling this twice returns the same draft, which is exactly what an
 * autosaving wizard reloaded in a second tab needs.
 */
class StartProgressReport
{
    public function __invoke(
        Project $project,
        ReportingPeriod $period,
        User $actor,
        ?Contractor $contractor = null,
        ?ReportEntryMode $entryMode = null,
    ): ProgressReport {
        Gate::forUser($actor)->authorize('create', ProgressReport::class);

        // …and on THIS project. `reports.create` says a consultant may file
        // returns; it does not say which projects are theirs. ProjectPolicy@view
        // asks Project::scopeVisibleTo — the single definition of project
        // visibility — so a consultant naming a project they are not assigned
        // to, or one belonging to a workspace they merely have a URL for, is
        // refused HERE rather than only by the wizard's option list. The screen
        // validating against what it offers is a good screen; it is not a
        // control, because a Livewire endpoint takes any payload.
        Gate::forUser($actor)->authorize('view', $project);

        $this->assertReportable($project);

        return DB::transaction(function () use ($project, $period, $actor, $contractor, $entryMode): ProgressReport {
            $existing = ProgressReport::query()
                ->forPeriod($project->id, $period->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // A filed return is not restartable — it is reviewed, returned
                // or approved, and starting a second one would give the window
                // two answers.
                if (! $existing->isEditable()) {
                    throw ReportRuleViolation::liveReportExists();
                }

                return $existing;
            }

            $obligation = ReportObligation::query()
                ->where('reporting_period_id', $period->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            $mode = $entryMode ?? $this->modeFor($actor);

            $report = new ProgressReport([
                'project_id' => $project->id,
                'reporting_period_id' => $period->id,
                'report_obligation_id' => $obligation?->id,
                'narrative_work_done' => '',
                // The claim starts from where the project actually is, so a
                // contractor confirms or advances a figure rather than
                // retyping it from memory.
                'physical_progress_claimed' => $project->physical_progress,
                'period_expenditure' => '0.00',
                'entry_mode' => $mode,
                'contractor_id' => $contractor->id ?? $this->contractorFor($project, $mode),
                'created_by_id' => $actor->id,
            ]);

            // Not fillable, so assigned explicitly here:
            //  - `status` is the chokepoint's column; stating the opening
            //    value in code rather than leaning on a DB default means the
            //    in-memory model is never a `null` status waiting for a
            //    refresh to become real,
            //  - `due_at` is the deadline this return is judged against,
            //    snapshotted so a later calendar edit cannot retroactively
            //    make a filed report late (§1.3).
            $report->forceFill([
                'status' => ProgressReportStatus::Draft,
                'due_at' => $obligation->due_at ?? $period->due_at,
            ])->save();

            return $report;
        });
    }

    /**
     * Only projects in a status that owes reports. Which statuses those are is
     * configurable (`reporting.obligation_statuses`) — a state that stops
     * reporting at practical completion changes a setting, not this class.
     */
    private function assertReportable(Project $project): void
    {
        $reportable = app(SettingsRepository::class)->strings(
            'reporting',
            'obligation_statuses',
            ['mobilized', 'in_progress', 'completed'],
        );

        if (! in_array($project->status->value, $reportable, true)) {
            throw ReportRuleViolation::projectNotReportable($project->status->value);
        }
    }

    /**
     * An actor who can review returns is MDA staff, so anything they type is
     * on someone else's behalf. A consultant files their own.
     */
    private function modeFor(User $actor): ReportEntryMode
    {
        return $actor->can('reports.review')
            ? ReportEntryMode::OnBehalf
            : ReportEntryMode::SelfService;
    }

    /**
     * Whose figures an on-behalf return carries: the firm on the project's
     * original award. Variations are raised against that contract and name the
     * same firm, so the earliest non-variation contract is the executing party.
     */
    private function contractorFor(Project $project, ReportEntryMode $mode): ?int
    {
        if ($mode !== ReportEntryMode::OnBehalf) {
            return null;
        }

        $contractorId = $project->contracts()
            ->whereNull('varies_contract_id')
            ->orderBy('id')
            ->value('contractor_id');

        return $contractorId === null ? null : (int) $contractorId;
    }
}
