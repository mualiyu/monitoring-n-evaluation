<?php

namespace App\Actions\Evaluation;

use App\Enums\EvaluationStatus;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Exceptions\Evaluation\InvalidEvaluationTransition;
use App\Jobs\Evaluation\NotifyEvaluationChain;
use App\Models\Evaluation;
use App\Models\EvaluationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of Evaluation::$status and of the whole approval chain.
 * Nothing else in the codebase assigns those columns — which is why none of
 * them is fillable, why this class is greppable as the one chokepoint, and why
 * the separation guard below cannot be routed around by a form payload or by a
 * second entry path.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the lifecycle table (an impossible move is impossible for everyone),
 *   2. authorization (per TARGET status — see abilityFor()),
 *   3. domain preconditions (a reason was given; the report is written; every
 *      criterion is scored and justified),
 *   4. the separation guard — THE EVALUATION LEAD CANNOT APPROVE THEIR OWN
 *      EVALUATION, and neither can whoever sent it up for review,
 *   5. the write + the typed ledger row, in one transaction under a row lock,
 *   6. the queued, tenant-aware notification, once the transaction has closed.
 */
class TransitionEvaluationStatus
{
    /**
     * @param  array<string, mixed>  $extraChanges  columns the calling Action
     *                                              owns; never `status`.
     */
    public function __invoke(
        Evaluation $evaluation,
        EvaluationStatus $to,
        User $actor,
        ?string $reason = null,
        array $extraChanges = [],
    ): Evaluation {
        $from = $evaluation->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidEvaluationTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($from, $to), $evaluation);

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($evaluation, $from, $to, $reason);
        $this->assertSeparation($evaluation, $to, $actor);

        $changes = [
            ...$extraChanges,
            ...$this->chainStamps($to, $actor, $reason),
            'status' => $to,
            'status_changed_at' => now(),
        ];

        DB::transaction(function () use ($evaluation, $from, $to, $actor, $reason, $changes): void {
            // The lock is taken on the row we are about to move, and the
            // origin status is re-read under it: two directors pressing
            // "approve" on the same draft in the same second must produce one
            // approval and one refusal, not two ledger entries.
            $locked = Evaluation::query()->lockForUpdate()->find($evaluation->id);

            if ($locked === null || $locked->status !== $from) {
                throw InvalidEvaluationTransition::between(
                    $locked?->status ?? $from,
                    $to,
                );
            }

            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $evaluation->forceFill($changes)->save();

            $event = new EvaluationEvent([
                'evaluation_id' => $evaluation->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            // Explicit property write (tenant_id is not fillable): the
            // evaluation itself is always the authority on which MDA this
            // belongs to, and a console/oversight caller may hold no bound
            // tenant.
            $event->tenant_id = $evaluation->tenant_id;
            $event->save();
        });

        // Dispatched after the write closes, never inside it. The job re-reads
        // the row and refuses to announce a step the record has since moved
        // past, exactly as NotifyProgressReportChain does.
        NotifyEvaluationChain::dispatch($evaluation->id, $to->value, $actor->id, $reason);

        return $evaluation;
    }

    /**
     * Who signed for this step and when — the CURRENT chain state that lists
     * read. The history lives in evaluation_events.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(EvaluationStatus $to, User $actor, ?string $reason): array
    {
        $now = now();

        return match ($to) {
            EvaluationStatus::UnderReview => [
                'submitted_by_id' => $actor->id,
                'submitted_at' => $now,
            ],
            EvaluationStatus::Approved => [
                'approved_by_id' => $actor->id,
                'approved_at' => $now,
            ],
            EvaluationStatus::Published => [
                'published_by_id' => $actor->id,
                'published_at' => $now,
            ],
            EvaluationStatus::Cancelled => [
                'cancellation_reason' => $reason,
            ],
            // Sent back for rework: the submission stamps are cleared, because
            // "who filed this" must mean the person who filed the version now
            // in front of the approver. The ledger keeps the earlier one.
            EvaluationStatus::DraftReport => [
                'submitted_by_id' => null,
                'submitted_at' => null,
            ],
            EvaluationStatus::Planned, EvaluationStatus::InProgress => [],
        };
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: running the fieldwork is the team's work,
     * filing the report is the lead's act, approving it is the director's
     * signature, and publishing it is what makes findings quotable outside
     * the platform.
     *
     * `draft_report` is the one target keyed on its ORIGIN: reaching it from
     * `in_progress` is the team marking their own draft complete, while
     * reaching it from `under_review` is the APPROVER sending it back — a
     * different authority entirely, and reading it the other way would let the
     * team withdraw a report that had already reached the director.
     */
    private function abilityFor(EvaluationStatus $from, EvaluationStatus $to): string
    {
        return match ($to) {
            EvaluationStatus::InProgress => 'update',
            EvaluationStatus::DraftReport => $from === EvaluationStatus::UnderReview ? 'approve' : 'update',
            EvaluationStatus::UnderReview => 'submit',
            EvaluationStatus::Approved => 'approve',
            EvaluationStatus::Published => 'publish',
            EvaluationStatus::Cancelled => 'cancel',
            EvaluationStatus::Planned => 'update',
        };
    }

    private function assertPreconditions(
        Evaluation $evaluation,
        EvaluationStatus $from,
        EvaluationStatus $to,
        ?string $reason,
    ): void {
        // Cancelling, and sending a report back, both need a reason: an
        // abandoned commission with nothing on the record explaining why is
        // how an inconvenient evaluation disappears.
        $needsReason = $to === EvaluationStatus::Cancelled
            || ($to === EvaluationStatus::DraftReport && $from === EvaluationStatus::UnderReview);

        if ($needsReason && ($reason === null || $reason === '')) {
            throw EvaluationRuleViolation::reasonRequired();
        }

        if ($to === EvaluationStatus::DraftReport && $from === EvaluationStatus::InProgress) {
            $this->assertReportIsWritten($evaluation);
        }

        if ($to === EvaluationStatus::UnderReview) {
            // Both gates, not one: a complete report with an unscored
            // scorecard and a full scorecard with an unwritten findings
            // chapter are the same failure — a review that has nothing to
            // review.
            $this->assertReportIsWritten($evaluation);
            $this->assertScorecardIsComplete($evaluation);
        }
    }

    private function assertReportIsWritten(Evaluation $evaluation): void
    {
        // load(), not loadMissing() and not the bare accessor. The bare
        // accessor would lazy-load, and preventLazyLoading is on outside
        // production; loadMissing() would read whatever the CALLER happened to
        // have in memory, which is the dangerous half — a screen that eager-
        // loaded a complete set, then had a section cleared in another tab,
        // would carry the stale "complete" straight through this gate. A gate
        // that decides whether findings may be signed for asks the database.
        $missing = $evaluation->load('sections')->missingRequiredSections();

        if ($missing !== []) {
            throw EvaluationRuleViolation::reportIncomplete($missing);
        }
    }

    private function assertScorecardIsComplete(Evaluation $evaluation): void
    {
        // load(), for the same reason as assertReportIsWritten().
        $unscored = $evaluation->load('criterionScores')->unscoredCriteria();

        if ($unscored !== []) {
            throw EvaluationRuleViolation::scorecardIncomplete($unscored);
        }
    }

    /**
     * SEPARATION OF DUTIES. The evaluation lead cannot approve their own
     * evaluation: approval is an independent judgement on the findings, or it
     * is a signature on your own homework. The submitter guard is the same
     * rule one step earlier — the person who decided the report was ready
     * cannot also be the person who decides it is right.
     *
     * Enforced HERE and not in the policy, because both are facts about *who
     * already acted on this row* rather than about what a role may do; a
     * policy that answered them would make an authorization result depend on
     * chain history that every screen would then have to duplicate.
     */
    private function assertSeparation(Evaluation $evaluation, EvaluationStatus $to, User $actor): void
    {
        if ($to !== EvaluationStatus::Approved) {
            return;
        }

        if ($evaluation->isLedBy($actor)) {
            throw EvaluationRuleViolation::approverIsLead();
        }

        if ($evaluation->submitted_by_id === $actor->id) {
            throw EvaluationRuleViolation::approverIsSubmitter();
        }
    }
}
