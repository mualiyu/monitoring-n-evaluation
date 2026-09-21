<?php

namespace App\Actions\Consolidation;

use App\Enums\ConsolidationStatus;
use App\Exceptions\Consolidation\ConsolidationRuleViolation;
use App\Exceptions\Consolidation\InvalidConsolidationTransition;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of ConsolidatedReport::$status and of the whole
 * compile → review → sign-off chain. Nothing else in the codebase assigns
 * those columns — which is why none of them is fillable, why this class is
 * greppable as the one chokepoint, and why the separation guard below cannot
 * be routed around by a form payload or by a second entry path.
 *
 * Modelled on App\Actions\Reporting\TransitionProgressReportStatus, down to
 * the ordering, because the property that matters is the same: the cheapest
 * question fails first, and no guard can be skipped by arriving from a
 * different screen.
 *
 *   1. the chain table (an impossible move is impossible for everyone),
 *   2. authorization, per TARGET status,
 *   3. domain preconditions (a reason was given; figures exist; the summary
 *      has been written),
 *   4. the separation guards — approver ≠ compiler, approver ≠ submitter,
 *   5. the write + the typed ledger row, inside ONE transaction, over a row
 *      held with lockForUpdate so two officers pressing Approve at the same
 *      moment cannot both win,
 *   6. nothing after the transaction: the consolidation notifies nobody
 *      automatically, because the state chain is a meeting, not an inbox.
 */
class TransitionConsolidationStatus
{
    /**
     * @param  array<string, mixed>  $extraChanges  columns the calling Action
     *                                              owns (the frozen snapshot at
     *                                              approval); never `status`.
     */
    public function __invoke(
        ConsolidatedReport $report,
        ConsolidationStatus $to,
        User $actor,
        ?string $reason = null,
        array $extraChanges = [],
    ): ConsolidatedReport {
        $from = $report->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidConsolidationTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($from, $to), $report);

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($report, $from, $to, $reason);
        $this->assertSeparation($report, $to, $actor);

        $changes = [
            ...$extraChanges,
            ...$this->chainStamps($to, $actor, $reason),
            'status' => $to,
        ];

        DB::transaction(function () use ($report, $from, $to, $actor, $reason, $changes): void {
            // Re-read the row under a write lock and re-check the move. Two
            // officers on two laptops both holding a stale `in_review` model
            // would otherwise each pass step 1 and write `approved` twice,
            // producing two ledger rows and two approvers for one signature.
            /** @var ConsolidatedReport $locked */
            $locked = ConsolidatedReport::query()
                ->whereKey($report->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== $from) {
                throw InvalidConsolidationTransition::between($locked->status, $to);
            }

            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $report->forceFill($changes)->save();

            ConsolidationEvent::query()->create([
                'consolidated_report_id' => $report->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
        });

        return $report;
    }

    /**
     * Who signed for this step and when — the CURRENT chain state the
     * workspace list reads. The history lives in consolidation_events.
     *
     * `compiled_by_id` is NOT stamped here: it belongs to the officer who ran
     * the compile, which CompileConsolidatedFigures records itself, and which
     * the separation guard then reads. Stamping it on the draft → compiling
     * move would credit whoever pressed the button rather than whoever the
     * figures came from.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(ConsolidationStatus $to, User $actor, ?string $reason): array
    {
        $now = now();

        return match ($to) {
            ConsolidationStatus::InReview => [
                'submitted_by_id' => $actor->id,
                'submitted_at' => $now,
            ],
            ConsolidationStatus::Approved => [
                'approved_by_id' => $actor->id,
                'approved_at' => $now,
            ],
            ConsolidationStatus::Published => [
                'published_by_id' => $actor->id,
                'published_at' => $now,
            ],
            // A return lands on `compiling`; a reset lands on `draft`. Both
            // record who sent it back and why.
            ConsolidationStatus::Compiling, ConsolidationStatus::Draft => $reason === null ? [] : [
                'returned_by_id' => $actor->id,
                'returned_at' => $now,
                'return_reason' => $reason,
            ],
        };
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: compiling and writing are the secretariat's
     * work, approval is a signature, and publication releases figures to
     * readers outside the building.
     */
    private function abilityFor(ConsolidationStatus $from, ConsolidationStatus $to): string
    {
        return match ($to) {
            ConsolidationStatus::Compiling => $from === ConsolidationStatus::InReview ? 'return' : 'compile',
            ConsolidationStatus::InReview => 'submit',
            ConsolidationStatus::Approved => 'approve',
            ConsolidationStatus::Published => 'publish',
            ConsolidationStatus::Draft => 'update',
        };
    }

    private function assertPreconditions(
        ConsolidatedReport $report,
        ConsolidationStatus $from,
        ConsolidationStatus $to,
        ?string $reason,
    ): void {
        // Going BACKWARDS always needs an explanation; going forwards never
        // does. A return with no reason leaves the secretariat guessing.
        $isReturn = $from === ConsolidationStatus::InReview && $to === ConsolidationStatus::Compiling;

        if ($isReturn && ($reason === null || $reason === '')) {
            throw ConsolidationRuleViolation::reasonRequired();
        }

        if ($to === ConsolidationStatus::InReview) {
            $this->assertReviewable($report);
        }

        if ($to === ConsolidationStatus::Published && ! is_array($report->snapshot)) {
            throw ConsolidationRuleViolation::snapshotMissing();
        }
    }

    /**
     * Figures AND an argument. Both, because a state report is neither a
     * spreadsheet nor an essay.
     */
    private function assertReviewable(ConsolidatedReport $report): void
    {
        if (! $report->hasFigures()) {
            throw ConsolidationRuleViolation::noFiguresCompiled();
        }

        $key = $report->type->requiredSectionKey();

        // loadMissing, not the bare accessor: preventLazyLoading is on outside
        // production and an Action must not depend on its caller's eager set.
        $section = $report->loadMissing('sections')->sections->firstWhere('key', $key);

        if ($section === null || ! $section->isWritten()) {
            throw ConsolidationRuleViolation::summaryRequired(
                $section->heading ?? $report->type->sectionSkeleton()[$key] ?? $key,
            );
        }
    }

    /**
     * THE separation guard, and the reason this Action exists rather than a
     * `->update(['status' => …])` in a component.
     *
     * The officer who compiled the figures cannot sign them off, and neither
     * can the officer who sent them up. A state report that one person
     * compiles, submits and approves is an assertion with three of that
     * person's signatures on it — which is exactly the thing the M&E
     * Secretariat exists to stop an MDA from producing, so it cannot be the
     * thing the Secretariat produces itself.
     */
    private function assertSeparation(ConsolidatedReport $report, ConsolidationStatus $to, User $actor): void
    {
        if ($to !== ConsolidationStatus::Approved) {
            return;
        }

        if ($report->compiled_by_id === $actor->id) {
            throw ConsolidationRuleViolation::approverIsCompiler();
        }

        if ($report->submitted_by_id === $actor->id) {
            throw ConsolidationRuleViolation::approverIsSubmitter();
        }
    }
}
