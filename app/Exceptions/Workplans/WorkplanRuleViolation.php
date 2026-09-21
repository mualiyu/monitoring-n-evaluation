<?php

namespace App\Exceptions\Workplans;

use App\Enums\WorkplanStatus;
use DomainException;

/**
 * A domain precondition refused the write. One class with named constructors,
 * as in the projects and reporting modules: the rules are testable by message,
 * greppable in one place, and every Action states its guard in the same
 * vocabulary.
 *
 * These are *domain* failures, not authorization failures — an MDA admin with
 * every permission still cannot approve a work plan they submitted.
 * Authorization denials surface as Laravel's AuthorizationException.
 */
class WorkplanRuleViolation extends DomainException
{
    public static function frozenPlan(WorkplanStatus $status): self
    {
        return new self(
            "The activities of a [{$status->value}] work plan are frozen — scope, schedule and budget stop "
            .'moving once an approving officer has signed for them. Progress, expenditure and actual dates '
            .'are still recordable; a change of plan is a new plan, not a quiet edit of the approved one.'
        );
    }

    public static function planNotStartedYet(): self
    {
        return new self('A work plan cannot be activated before its period begins.');
    }

    public static function emptyPlan(): self
    {
        return new self('A work plan cannot be submitted with no activities — an empty plan is not a plan.');
    }

    public static function reasonRequired(): self
    {
        return new self('Rejecting a work plan requires a stated reason — the owner has to know what to fix.');
    }

    public static function approverIsSubmitter(): self
    {
        return new self(
            'The officer who submitted a work plan cannot approve it. Separation of duties is the whole '
            .'value of the approval step: a plan signed off by its own author has been reviewed by nobody.'
        );
    }

    public static function outputIndicatorRequired(int $count): self
    {
        return new self(
            "{$count} activity(ies) carry no output indicator. The M&E manual requires one per annual "
            .'work-plan activity, and this instance enforces it at submission '
            .'(`workplans.require_output_indicator`).'
        );
    }

    public static function scheduleOutsidePeriod(string $title): self
    {
        return new self(
            "Activity [{$title}] is scheduled outside the plan's own period — an annual work plan that "
            .'contains work from another year cannot be measured against its year.'
        );
    }

    public static function scheduleReversed(string $title): self
    {
        return new self("Activity [{$title}] ends before it starts.");
    }

    public static function dependencyOutsidePlan(): self
    {
        return new self('An activity may only depend on another activity of the same work plan.');
    }

    public static function dependencyCycle(): self
    {
        return new self(
            'That dependency would create a cycle — a chain of activities each waiting on the next can '
            .'never start, and the Gantt could not draw it.'
        );
    }

    public static function dependentsRemain(int $count): self
    {
        return new self(
            "{$count} other activity(ies) depend on this one. Repoint or remove them first, so no line of "
            .'the plan is left waiting on work that no longer exists.'
        );
    }

    public static function progressNotAccepted(WorkplanStatus $status): self
    {
        return new self(
            "Progress cannot be recorded against a [{$status->value}] work plan — only an approved or "
            .'active plan is the programme being delivered.'
        );
    }

    public static function progressOutOfRange(int $percent): self
    {
        return new self("Activity progress must be between 0 and 100 — [{$percent}] is not.");
    }

    public static function cancelledActivity(): self
    {
        return new self('A cancelled activity records no further progress — reinstate it first.');
    }

    public static function duplicateYear(int $year, string $basis): self
    {
        return new self(
            "This entity already has a work plan for {$year} ({$basis} year). Revise that plan rather than "
            .'opening a second one — two plans for one year is two answers to "what are we delivering".'
        );
    }

    public static function periodReversed(): self
    {
        return new self('A work plan period must end after it begins.');
    }
}
