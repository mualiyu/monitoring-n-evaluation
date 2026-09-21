<?php

namespace App\Exceptions\Issues;

use App\Enums\IssueStatus;
use DomainException;

/**
 * A domain precondition refused the write. One class with named constructors,
 * as in the projects and reporting modules: the rules are testable by message,
 * greppable in one place, and every Action that guards something states the
 * guard in the same vocabulary.
 *
 * These are *domain* failures, not authorization failures — an MDA admin with
 * every permission still cannot close an issue without saying why it is being
 * closed unresolved. Authorization denials surface as Laravel's
 * AuthorizationException.
 */
class IssueRuleViolation extends DomainException
{
    public static function resolutionRequired(): self
    {
        return new self(
            'Resolving an issue requires a note saying what was actually done about it — '
            .'a register of obstructions that were "resolved" with no account of how is a register nobody can learn from.'
        );
    }

    public static function closeRequiresReason(IssueStatus $from): self
    {
        return new self(
            "Closing an issue that is still [{$from->value}] requires a stated reason — raised in error, "
            .'duplicate, or overtaken by events. A close that skips resolution is exactly the one an auditor asks about.'
        );
    }

    public static function notEditable(IssueStatus $status): self
    {
        return new self(
            "A [{$status->value}] issue can no longer be edited. Reopen it if the corrective action has to change."
        );
    }

    public static function ownerNotInWorkspace(): self
    {
        return new self(
            'An issue can only be assigned to someone who works in this entity — '
            .'an owner who cannot open the workspace cannot clear the issue.'
        );
    }

    public static function escalationIsSystemOnly(IssueStatus $to): self
    {
        return new self(
            "Only the threshold engine raises the escalation rung; [{$to->value}] is not a move a person makes. "
            .'Escalation is what happens when nobody acted in time, so a human escalating their own issue would defeat it.'
        );
    }

    public static function actorRequired(IssueStatus $to): self
    {
        return new self("Moving an issue to [{$to->value}] requires an actor — every step of the ledger names who took it.");
    }

    public static function narrativeRequired(): self
    {
        return new self('An exception report needs a narrative — a deviation nobody described is a number with no meaning.');
    }

    public static function exceptionResolutionRequired(): self
    {
        return new self(
            'Resolving an exception report requires a note saying why the deviation no longer stands.'
        );
    }

    public static function duplicateCondition(string $trigger): self
    {
        return new self(
            "This project already has a live [{$trigger}] exception report — the condition is already on the record. "
            .'Raising a second one would double-count the deviation on every board that reads this register.'
        );
    }

    public static function issueBelongsToAnotherProject(): self
    {
        return new self('An exception report can only be linked to an issue raised against the same project.');
    }
}
