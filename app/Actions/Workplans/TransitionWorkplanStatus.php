<?php

namespace App\Actions\Workplans;

use App\Enums\WorkplanStatus;
use App\Exceptions\Workplans\InvalidWorkplanTransition;
use App\Exceptions\Workplans\WorkplanRuleViolation;
use App\Jobs\Workplans\NotifyWorkplanChain;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanEvent;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of Workplan::$status and of the whole approval chain.
 * Nothing else in the codebase assigns those columns — which is why none of
 * them is fillable, why this class is greppable as the one chokepoint, and why
 * the separation guard below cannot be routed around by a form payload or by a
 * second entry path. Copied in shape from
 * App\Actions\Reporting\TransitionProgressReportStatus.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the chain table (an impossible move is impossible for everyone),
 *   2. authorization (per TARGET status — see abilityFor()),
 *   3. domain preconditions (the plan has activities; a reason was given; the
 *      period has begun before activation; the manual's indicator rule, where
 *      the instance enforces it),
 *   4. the separation guard — approver ≠ submitter,
 *   5. the write + the typed ledger row, in one transaction, under a row lock,
 *   6. the queued, tenant-aware notification, once the transaction has closed.
 *
 * The lock matters more here than on a progress report: an MDA's work plan is
 * the one record a director and the M&E unit open simultaneously in the week
 * before a year starts, and two concurrent approvals would otherwise write two
 * ledger rows for one decision.
 */
class TransitionWorkplanStatus
{
    public function __invoke(
        Workplan $workplan,
        WorkplanStatus $to,
        User $actor,
        ?string $reason = null,
    ): Workplan {
        $from = $workplan->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidWorkplanTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($to), $workplan);

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($workplan, $to, $reason);
        $this->assertSeparation($workplan, $to, $actor);

        DB::transaction(function () use ($workplan, $from, $to, $actor, $reason): void {
            // Re-read under a lock, then re-ask the chain table: between the
            // guard above and this line another officer may have approved the
            // same plan. Re-querying through the model (never ->fresh(),
            // which drops the TenantScope) keeps the read inside this MDA.
            $locked = Workplan::query()->lockForUpdate()->whereKey($workplan->getKey())->firstOrFail();

            if ($locked->status !== $from) {
                throw InvalidWorkplanTransition::between($locked->status, $to);
            }

            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $workplan->forceFill([
                ...$this->chainStamps($to, $actor, $reason),
                'status' => $to,
                'status_changed_at' => now(),
            ])->save();

            $event = new WorkplanEvent([
                'workplan_id' => $workplan->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            // Explicit property write (tenant_id is not fillable): the plan
            // itself is always the authority on which MDA this belongs to,
            // and a console/oversight caller may hold no bound tenant.
            $event->tenant_id = $workplan->tenant_id;
            $event->save();
        });

        // Dispatched after the write closes, never inside it. When this Action
        // runs nested in ApproveWorkplan's transaction the dispatch still
        // precedes that outer commit by microseconds — accepted, exactly as in
        // the reporting chain: the job re-reads the row and is idempotent.
        NotifyWorkplanChain::dispatch($workplan->id, $to->value, $actor->id, $reason);

        return $workplan;
    }

    /**
     * Records the creation of a plan in the ledger, so the timeline starts
     * where the plan did rather than at its first move. Called by
     * CreateWorkplan inside its own transaction; it writes no status.
     */
    public function recordCreation(Workplan $workplan, User $actor): WorkplanEvent
    {
        $event = new WorkplanEvent([
            'workplan_id' => $workplan->id,
            'from_status' => null,
            'to_status' => $workplan->status,
            'actor_id' => $actor->id,
            'reason' => null,
            'occurred_at' => now(),
        ]);
        $event->tenant_id = $workplan->tenant_id;
        $event->save();

        return $event;
    }

    /**
     * Who signed for this step and when — the CURRENT chain state that lists
     * and the oversight board read. The history lives in workplan_events.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(WorkplanStatus $to, User $actor, ?string $reason): array
    {
        $now = now();

        return match ($to) {
            WorkplanStatus::Submitted => [
                'submitted_by_id' => $actor->id,
                'submitted_at' => $now,
                // A resubmission clears the previous rejection: the plan is no
                // longer rejected, and leaving the reason on the record would
                // keep showing a decision that has been superseded. The
                // ledger keeps the history.
                'rejected_by_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ],
            WorkplanStatus::Approved => [
                'approved_by_id' => $actor->id,
                'approved_at' => $now,
            ],
            WorkplanStatus::Rejected => [
                'rejected_by_id' => $actor->id,
                'rejected_at' => $now,
                'rejection_reason' => $reason,
            ],
            WorkplanStatus::Active => ['activated_at' => $now],
            WorkplanStatus::Closed => [
                'closed_by_id' => $actor->id,
                'closed_at' => $now,
            ],
            WorkplanStatus::Draft => [],
        };
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: submitting is the M&E unit's act
     * (`workplans.manage`), while approving, rejecting, activating and closing
     * are the accounting officer's signature (`workplans.approve`).
     */
    private function abilityFor(WorkplanStatus $to): string
    {
        return match ($to) {
            WorkplanStatus::Submitted => 'submit',
            WorkplanStatus::Approved => 'approve',
            WorkplanStatus::Rejected => 'reject',
            WorkplanStatus::Active => 'activate',
            WorkplanStatus::Closed => 'close',
            WorkplanStatus::Draft => 'update',
        };
    }

    private function assertPreconditions(Workplan $workplan, WorkplanStatus $to, ?string $reason): void
    {
        if ($to === WorkplanStatus::Rejected && ($reason === null || $reason === '')) {
            throw WorkplanRuleViolation::reasonRequired();
        }

        if ($to === WorkplanStatus::Active && ! $workplan->hasStarted()) {
            throw WorkplanRuleViolation::planNotStartedYet();
        }

        if ($to !== WorkplanStatus::Submitted) {
            return;
        }

        $activities = $workplan->loadMissing('activities')->activities;

        if ($activities->isEmpty()) {
            throw WorkplanRuleViolation::emptyPlan();
        }

        // THE MANUAL'S RULE (ondo-manual-digest §6): every activity carries an
        // output indicator. Off by default — the platform's answer is a
        // visible, countable warning on every screen, because a hard block
        // teaches officers to attach any indicator to get past it. A
        // secretariat that wants it enforced flips the setting.
        if (app(SettingsRepository::class)->bool('workplans', 'require_output_indicator', false)) {
            $unlinked = $activities->filter(
                fn ($activity): bool => $activity->lacksOutputIndicator()
            )->count();

            if ($unlinked > 0) {
                throw WorkplanRuleViolation::outputIndicatorRequired($unlinked);
            }
        }
    }

    /**
     * Separation of duties. A plan approved by the officer who submitted it
     * has been reviewed by nobody — and unlike the reporting chain, a work
     * plan has only ONE decision point, so this guard is the entire control.
     *
     * Rejection is deliberately NOT guarded: withdrawing your own submission
     * costs nobody anything, and refusing it would leave an author who spots
     * their own error with no way to take the plan back.
     */
    private function assertSeparation(Workplan $workplan, WorkplanStatus $to, User $actor): void
    {
        if ($to !== WorkplanStatus::Approved) {
            return;
        }

        if ($workplan->submitted_by_id === $actor->id
            && app(SettingsRepository::class)->bool('workplans', 'require_separate_approver', true)) {
            throw WorkplanRuleViolation::approverIsSubmitter();
        }
    }
}
