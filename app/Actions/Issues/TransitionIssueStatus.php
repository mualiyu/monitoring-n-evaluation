<?php

namespace App\Actions\Issues;

use App\Enums\IssueStatus;
use App\Exceptions\Issues\InvalidIssueTransition;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Jobs\Issues\NotifyIssueEscalated;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of Issue::$status and of the whole chain
 * (`acknowledged_*`, `resolved_*`, `closed_*`, `resolution_note`,
 * `escalated_at`, `status_changed_at`). Nothing else in the codebase assigns
 * those columns — which is why none of them is fillable, why this class is
 * greppable as the one chokepoint, and why the guards below cannot be routed
 * around by a form payload or by a second entry path.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the lifecycle table (an impossible move is impossible for everyone),
 *   2. WHO may even attempt this rung — escalation is the engine's, everything
 *      else needs a named human,
 *   3. authorization, per TARGET status (see abilityFor()),
 *   4. domain preconditions (a resolution says what was done; an unresolved
 *      close says why),
 *   5. the write + the typed ledger row, in one transaction under a row lock,
 *   6. the queued, tenant-aware notification, once the transaction has closed.
 *
 * The row lock is what makes two officers clicking "Resolve" at the same
 * moment produce one resolution and one refusal rather than two ledger entries
 * claiming different resolvers.
 */
class TransitionIssueStatus
{
    /**
     * @param  User|null  $actor  null ONLY for the threshold engine's escalation
     *                            rung — see assertActor(). A machine judgement
     *                            is recorded as one rather than attributed to
     *                            whoever happened to trigger the sweep.
     */
    public function __invoke(
        Issue $issue,
        IssueStatus $to,
        ?User $actor,
        ?string $reason = null,
    ): Issue {
        $from = $issue->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidIssueTransition::between($from, $to);
        }

        $this->assertActor($to, $actor);

        if ($actor !== null) {
            Gate::forUser($actor)->authorize($this->abilityFor($to), $issue);
        }

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($from, $to, $reason);

        DB::transaction(function () use ($issue, $from, $to, $actor, $reason): void {
            // Re-read under a lock: the status this transition was authorized
            // against must still be the status it is applied to. Without it,
            // two concurrent decisions both pass the chain check above and
            // both write.
            $locked = Issue::query()->lockForUpdate()->find($issue->getKey());

            if ($locked === null || $locked->status !== $from) {
                throw InvalidIssueTransition::between($locked->status ?? $from, $to);
            }

            $changes = [
                ...$this->chainStamps($locked, $to, $actor, $reason),
                'status' => $to,
                'status_changed_at' => now(),
            ];

            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $locked->forceFill($changes)->save();

            $event = new IssueEvent([
                'issue_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            // Explicit property write (tenant_id is not fillable): the issue
            // itself is always the authority on which MDA this belongs to, and
            // a console/engine caller may hold no bound tenant.
            $event->tenant_id = $locked->tenant_id;
            $event->save();

            // The caller's instance follows the row, so a screen that
            // re-renders straight after a transition shows the new state
            // without a second query.
            $issue->forceFill($changes)->syncOriginal();
        });

        if ($to === IssueStatus::Escalated) {
            // Dispatched after the write closes, never inside it. The job
            // re-reads the row and is idempotent, exactly as the reporting
            // chain's notification is.
            NotifyIssueEscalated::dispatch($issue->id);
        }

        return $issue;
    }

    /**
     * Escalation is what happens when NOBODY acted in time. A person
     * escalating their own issue would defeat the point of the ladder — and
     * the ladder's once-ever gate (`escalated_at`) would then be spendable by
     * hand, so a genuinely neglected issue could be made un-escalatable.
     * It is therefore the engine's rung and no one else's.
     *
     * Every other rung needs a named actor, because every row of the ledger
     * names who took the step.
     */
    private function assertActor(IssueStatus $to, ?User $actor): void
    {
        if ($to === IssueStatus::Escalated) {
            if ($actor !== null) {
                throw IssueRuleViolation::escalationIsSystemOnly($to);
            }

            return;
        }

        if ($actor === null) {
            throw IssueRuleViolation::actorRequired($to);
        }
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: accepting an issue and picking it up are
     * ordinary register upkeep (`issues.update`), declaring it fixed is an
     * M&E judgement (`issues.resolve`), and closing it — the point at which
     * the register stops chasing the item — is the MDA admin's
     * (`issues.close`).
     */
    private function abilityFor(IssueStatus $to): string
    {
        return match ($to) {
            IssueStatus::Resolved => 'resolve',
            IssueStatus::Closed => 'close',
            // Escalated never reaches here — assertActor() has already
            // refused a human attempt at it.
            IssueStatus::Open, IssueStatus::Acknowledged,
            IssueStatus::InProgress, IssueStatus::Escalated => 'update',
        };
    }

    private function assertPreconditions(IssueStatus $from, IssueStatus $to, ?string $reason): void
    {
        if ($to === IssueStatus::Resolved && ($reason === null || $reason === '')) {
            throw IssueRuleViolation::resolutionRequired();
        }

        // Closing a RESOLVED issue is the ordinary end of the line and needs
        // no further words — the resolution note already says what was done.
        // Closing one that is still live is the shortcut an auditor asks
        // about: raised in error, duplicate, overtaken by a cancellation.
        if ($to === IssueStatus::Closed
            && $from !== IssueStatus::Resolved
            && ($reason === null || $reason === '')) {
            throw IssueRuleViolation::closeRequiresReason($from);
        }
    }

    /**
     * Who took this step and when — the CURRENT chain state that lists and
     * boards read. The history lives in issue_events.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(Issue $issue, IssueStatus $to, ?User $actor, ?string $reason): array
    {
        $now = now();

        return match ($to) {
            // FIRST acknowledgement wins: "how long did this entity take to
            // accept the problem" is the question this column answers, and
            // re-stamping it on a later re-acknowledgement after an escalation
            // would quietly erase the delay that caused the escalation. Every
            // acknowledgement is on the ledger regardless.
            IssueStatus::Acknowledged => [
                'acknowledged_by_id' => $issue->acknowledged_by_id ?? $actor?->id,
                'acknowledged_at' => $issue->acknowledged_at ?? $now,
            ],
            IssueStatus::Resolved => [
                'resolved_by_id' => $actor?->id,
                'resolved_at' => $now,
                'resolution_note' => $reason,
            ],
            IssueStatus::Closed => [
                'closed_by_id' => $actor?->id,
                'closed_at' => $now,
            ],
            IssueStatus::Escalated => [
                // The ladder's once-ever gate. EscalateStaleIssues re-checks it
                // under the same row lock before calling in, so a double sweep
                // cannot double-escalate.
                'escalated_at' => $issue->escalated_at ?? $now,
            ],
            // Reopening: the resolution stamps describe a state the issue is
            // no longer in, and leaving them would make the record claim to be
            // resolved while its status says otherwise. The ledger keeps the
            // resolution that was.
            IssueStatus::InProgress => $issue->status === IssueStatus::Resolved
                ? ['resolved_by_id' => null, 'resolved_at' => null]
                : [],
            IssueStatus::Open => [],
        };
    }
}
