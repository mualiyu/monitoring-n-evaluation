<?php

namespace App\Actions\Reporting;

use App\Enums\ProgressReportStatus;
use App\Exceptions\Reporting\InvalidReportTransition;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Jobs\Reporting\NotifyProgressReportChain;
use App\Models\ProgressReport;
use App\Models\ProgressReportEvent;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of ProgressReport::$status and of the whole approval chain
 * (progress-reporting.md §2). Nothing else in the codebase assigns those
 * columns — which is why none of them is fillable, why this class is greppable
 * as the one chokepoint, and why the separation guards below cannot be routed
 * around by a form payload or by a second entry path.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the chain table (an impossible move is impossible for everyone),
 *   2. authorization (per TARGET status — see abilityFor()),
 *   3. domain preconditions (a reason was given; the narrative exists; the
 *      window accepts this submission; a decrease is explained),
 *   4. the separation guards — reviewer ≠ submitter, approver ≠ submitter,
 *      approver ≠ reviewer (§2.2),
 *   5. the write + the typed ledger row, in one transaction,
 *   6. the queued, tenant-aware notification, once the transaction has closed.
 */
class TransitionProgressReportStatus
{
    /**
     * @param  array<string, mixed>  $extraChanges  columns the calling Action
     *                                              owns (audit snapshots at
     *                                              approval); never `status`.
     */
    public function __invoke(
        ProgressReport $report,
        ProgressReportStatus $to,
        User $actor,
        ?string $reason = null,
        array $extraChanges = [],
    ): ProgressReport {
        $from = $report->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidReportTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($from, $to), $report);

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($report, $to, $reason);
        $this->assertSeparation($report, $to, $actor);

        $changes = [
            ...$extraChanges,
            ...$this->chainStamps($report, $to, $actor, $reason),
            'status' => $to,
        ];

        DB::transaction(function () use ($report, $from, $to, $actor, $reason, $changes): void {
            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $report->forceFill($changes)->save();

            $event = new ProgressReportEvent([
                'progress_report_id' => $report->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            // Explicit property write (tenant_id is not fillable): the report
            // itself is always the authority on which MDA this belongs to,
            // and a console/oversight caller may hold no bound tenant.
            $event->tenant_id = $report->tenant_id;
            $event->save();
        });

        // Dispatched after the write closes, never inside it. When this Action
        // runs nested in ApproveProgressReport's transaction the dispatch
        // still precedes that outer commit by microseconds — accepted, exactly
        // as in TransitionProjectStatus: the job re-reads the row and is
        // idempotent.
        NotifyProgressReportChain::dispatch($report->id, $to->value, $actor->id, $reason);

        return $report;
    }

    /**
     * Who signed for this step and when — the CURRENT chain state that lists
     * and the league table read. The history lives in progress_report_events.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(
        ProgressReport $report,
        ProgressReportStatus $to,
        User $actor,
        ?string $reason,
    ): array {
        $now = now();

        return match ($to) {
            ProgressReportStatus::Submitted => [
                'submitted_by_id' => $actor->id,
                'submitted_at' => $now,
                // Lateness is judged at FIRST submission and never recomputed:
                // a report returned for rework and resubmitted after the
                // deadline stays as on-time as it was when it was filed
                // (§9.6). Re-deriving it here would punish an MDA for its own
                // reviewer's turnaround.
                'submitted_late' => $report->submitted_at !== null
                    ? $report->submitted_late
                    : $report->due_at->isBefore($now),
            ],
            ProgressReportStatus::Reviewed => [
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => $now,
            ],
            ProgressReportStatus::Approved => [
                'approved_by_id' => $actor->id,
                'approved_at' => $now,
            ],
            ProgressReportStatus::Returned => [
                'returned_by_id' => $actor->id,
                'returned_at' => $now,
                'return_reason' => $reason,
            ],
            ProgressReportStatus::Draft => [],
        };
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: filing is the author's act, reviewing is the
     * focal officer's, approving is the director's signature — and approval is
     * what moves the project's attested figures.
     *
     * `returned` is the one target keyed on its ORIGIN: returning from
     * `submitted` is the reviewer's call, returning from `reviewed` is the
     * approver's. Reading it the other way would let an officer overturn a
     * review that had already reached the director.
     */
    private function abilityFor(ProgressReportStatus $from, ProgressReportStatus $to): string
    {
        return match ($to) {
            ProgressReportStatus::Submitted => 'submit',
            ProgressReportStatus::Reviewed => 'review',
            ProgressReportStatus::Approved => 'approve',
            ProgressReportStatus::Returned => $from === ProgressReportStatus::Reviewed ? 'approve' : 'review',
            ProgressReportStatus::Draft => 'update',
        };
    }

    private function assertPreconditions(ProgressReport $report, ProgressReportStatus $to, ?string $reason): void
    {
        if ($to === ProgressReportStatus::Returned && ($reason === null || $reason === '')) {
            throw ReportRuleViolation::reasonRequired();
        }

        if ($to !== ProgressReportStatus::Submitted) {
            return;
        }

        if (trim($report->narrative_work_done) === '') {
            throw ReportRuleViolation::narrativeRequired();
        }

        $this->assertWindowAccepts($report);
        $this->assertClaimIsExplained($report);
    }

    /**
     * A window that has not opened never accepts a return. A window past its
     * hard close accepts one only where the instance allows late submission —
     * the default, because an MDA blocked from reporting simply never reports,
     * which is worse for the data than a flagged late return (§9.5).
     */
    private function assertWindowAccepts(ProgressReport $report): void
    {
        // loadMissing, not the bare accessor: preventLazyLoading is on outside
        // production and an Action must not depend on its caller's eager set.
        $period = $report->loadMissing('reportingPeriod')->reportingPeriod;

        if ($period->isUpcoming()) {
            throw ReportRuleViolation::periodNotOpen($period->code);
        }

        if ($period->isClosed()
            && ! app(SettingsRepository::class)->bool('reporting', 'allow_late_submission', true)) {
            throw ReportRuleViolation::periodClosed($period->code);
        }
    }

    /**
     * Physical progress may go DOWN — an inspection that finds the reported
     * 60% is really 45% must be recordable, or the number everyone trusts
     * becomes the number nobody can correct. It may not go down silently.
     */
    private function assertClaimIsExplained(ProgressReport $report): void
    {
        $project = $report->loadMissing('project')->project;

        // decimal(5,2) percentages compared as integer basis points — the
        // comparison never touches floating point.
        $claimed = (int) round(((float) $report->physical_progress_claimed) * 100);
        $current = (int) round(((float) $project->physical_progress) * 100);

        if ($claimed < $current && trim((string) $report->progress_decrease_reason) === '') {
            throw ReportRuleViolation::decreaseRequiresReason(
                $report->physical_progress_claimed,
                $project->physical_progress,
            );
        }
    }

    /**
     * §2.2 — the identity guards. A consultant never reaches them, because a
     * consultant holds neither `reports.review` nor `reports.approve` and step
     * 2 refused them already. These exist for the ON-BEHALF path, where an
     * M&E officer files a contractor's return and could otherwise review it,
     * and for the single-office MDA where the same director would review and
     * approve.
     */
    private function assertSeparation(ProgressReport $report, ProgressReportStatus $to, User $actor): void
    {
        if ($to === ProgressReportStatus::Reviewed && $report->submitted_by_id === $actor->id) {
            throw ReportRuleViolation::reviewerIsSubmitter();
        }

        if ($to !== ProgressReportStatus::Approved) {
            return;
        }

        if ($report->submitted_by_id === $actor->id) {
            throw ReportRuleViolation::approverIsSubmitter();
        }

        if ($report->reviewed_by_id === $actor->id
            && app(SettingsRepository::class)->bool('reporting', 'require_separate_approver', true)) {
            throw ReportRuleViolation::approverIsReviewer();
        }
    }
}
