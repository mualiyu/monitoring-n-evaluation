<?php

namespace App\Enums;

/**
 * The life of a challenge, from "somebody noticed" to "it is dealt with"
 * (plan §4 — issues/corrective-action register; the manual's corrective-action
 * loop behind every monthly "challenges & mitigation" narrative).
 *
 * App\Actions\Issues\TransitionIssueStatus is the ONLY writer of
 * Issue::$status and consults this table first — an impossible move is
 * impossible for everyone, before any permission is considered.
 *
 * Two edges are not decoration:
 *
 *  - `escalated` is reachable from every live state and is raised by the
 *    threshold engine, not by a person: an issue nobody acknowledged inside
 *    `platform.exceptions.issue_escalation_days[severity]` becomes a director's
 *    problem without anyone having to run a report. From there it rejoins the
 *    normal chain, because escalation changes who is watching, not what has
 *    to happen.
 *  - `resolved → in_progress` reopens an issue whose fix did not hold. The
 *    alternative — raising a second issue for the same obstruction — loses the
 *    history that makes "this access dispute has been 'fixed' three times"
 *    visible at all.
 *
 * `closed` is absorbing. Closing is the MDA admin's act (`issues.close`) and
 * it is the point at which the register stops chasing the item.
 */
enum IssueStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * States reachable from this one.
     *
     * Closing is available from every live state, not only from `resolved`:
     * an issue raised in error, a duplicate, or one overtaken by the project
     * being cancelled has to be closable, and TransitionIssueStatus requires a
     * stated reason whenever a close skips resolution — so the shortcut always
     * leaves an explanation on the ledger.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::InProgress, self::Escalated, self::Closed],
            self::Acknowledged => [self::InProgress, self::Escalated, self::Resolved, self::Closed],
            self::InProgress => [self::Escalated, self::Resolved, self::Closed],
            self::Escalated => [self::Acknowledged, self::InProgress, self::Resolved, self::Closed],
            // A fix that did not hold reopens HERE rather than as a new issue,
            // so the history stays on one record.
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the issue is still the register's problem — what the overdue
     * clock, the escalation ladder and the "open issues" stat all count.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /** Whether the corrective action and due date may still be edited. */
    public function isEditable(): bool
    {
        return $this !== self::Closed;
    }

    /** Whether the escalation ladder should still consider this issue. */
    public function isEscalatable(): bool
    {
        return in_array($this, [self::Open, self::Acknowledged, self::InProgress], true);
    }

    /**
     * Which <x-ui.badge> tone this status borrows.
     *
     * The badge component owns a fixed vocabulary of status keys and their
     * tones; rather than fork it or add a case to a component three other
     * modules share, each status names the existing key whose TONE matches its
     * meaning and overrides the label and icon. The enum owning its own
     * presentation mapping is also what keeps a list, a panel and a detail
     * header from drifting into three different colours for `escalated`.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Open => 'pending',            // neutral — recorded, not yet answered
            self::Acknowledged => 'submitted',  // info
            self::InProgress => 'in_progress',  // brand
            self::Escalated => 'overdue',       // critical
            self::Resolved => 'approved',       // positive
            self::Closed => 'closed',           // neutral
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'exclamation-circle',
            self::Acknowledged => 'check',
            self::InProgress => 'arrow-path',
            self::Escalated => 'arrow-trending-up',
            self::Resolved => 'check-circle',
            self::Closed => 'shield-check',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Acknowledged => __('Acknowledged'),
            self::InProgress => __('Being worked on'),
            self::Escalated => __('Escalated'),
            self::Resolved => __('Resolved'),
            self::Closed => __('Closed'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
