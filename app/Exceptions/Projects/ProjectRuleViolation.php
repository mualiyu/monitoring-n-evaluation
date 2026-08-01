<?php

namespace App\Exceptions\Projects;

use App\Enums\ProjectStatus;
use Carbon\CarbonInterface;
use DomainException;

/**
 * A domain precondition refused the write (projects-module.md §2.1, §2.3, §2.4,
 * §1.7). One class with named constructors rather than a class per rule: the
 * rules are testable by message, greppable in one place, and every Action that
 * guards something states the guard in the same vocabulary.
 *
 * These are *domain* failures, not authorization failures — an actor with every
 * permission still cannot complete a project that is 99% built. Authorization
 * denials surface as Laravel's AuthorizationException through the policies.
 */
class ProjectRuleViolation extends DomainException
{
    public static function awardWithoutContract(): self
    {
        return new self('A project cannot be awarded before at least one contract exists against it.');
    }

    public static function completionBeforeFullProgress(string $progress): self
    {
        return new self(
            "A project cannot be completed at {$progress}% physical progress — completion means 100%, attested."
        );
    }

    public static function reasonRequired(ProjectStatus $to): self
    {
        return new self("Moving a project to [{$to->value}] requires a stated reason — it goes on the record.");
    }

    public static function closureBeforeReviewWindow(?CarbonInterface $dueAt): self
    {
        return new self(sprintf(
            'A certified project cannot be closed before its post-completion review window ends%s. A state-level administrator may override with a reason.',
            $dueAt === null ? '' : ' on '.$dueAt->toDateString(),
        ));
    }

    public static function closureOverrideRequiresStateAuthority(): self
    {
        return new self('Only a state-level administrator may close a project before its post-completion review window ends.');
    }

    public static function certificationRequiresFinalInspection(): self
    {
        return new self(
            'Certification requires a final inspection, but site inspections arrive in Phase 2 — turn off '
            .'`monitoring.require_final_inspection_for_certification` until then.'
        );
    }

    public static function frozenRecord(ProjectStatus $status): self
    {
        return new self(
            "Scope and financial fields are frozen from certification onward (project is [{$status->value}]). "
            .'Monitoring artifacts may still be attached; corrections go through the amendment register.'
        );
    }

    public static function progressOnInactiveProject(ProjectStatus $status): self
    {
        return new self("Physical progress cannot be recorded against a [{$status->value}] project.");
    }

    public static function progressOutOfRange(string $progress): self
    {
        return new self("Physical progress must be between 0 and 100 — [{$progress}] is not.");
    }

    public static function archiveRequiresDraftOrCancelled(ProjectStatus $status): self
    {
        return new self(
            "Only draft or cancelled projects may be archived; this one is [{$status->value}]. "
            .'Delivered projects are government records — they are closed, never deleted.'
        );
    }

    public static function fundingSplitExceedsWhole(string $total): self
    {
        return new self("Funding percentages total {$total}% — a project cannot be more than 100% funded.");
    }

    public static function locationBelongsToAnotherProject(): self
    {
        return new self('That site belongs to a different project.');
    }

    public static function lastLocationRemoval(): self
    {
        return new self('A project must keep at least one site — remove it only by replacing it.');
    }

    public static function contractOnUnawardableProject(ProjectStatus $status): self
    {
        return new self("A contract cannot be awarded against a [{$status->value}] project.");
    }

    public static function contractorBlacklisted(string $name): self
    {
        return new self("[{$name}] is blacklisted in the state vendor registry and cannot be awarded work.");
    }

    public static function variationRequiresReason(): self
    {
        return new self('A contract variation requires a stated reason — an unexplained cost change is an audit finding.');
    }

    public static function variationOfVariation(): self
    {
        return new self('Variations are raised against the original award, never against another variation.');
    }

    public static function variationCrossesProjects(): self
    {
        return new self('A variation must belong to the same project as the contract it varies.');
    }

    public static function immutableContractField(string $field): self
    {
        return new self(
            "[{$field}] is immutable once a contract is awarded — raise a variation instead, so the superseded figure stays readable."
        );
    }

    public static function assigneeNotAMember(): self
    {
        return new self('Only a user with an active membership of this workspace can be assigned to its projects.');
    }

    public static function assigneeLacksProjectRole(): self
    {
        return new self('Project assignments are for consultants, field monitors and M&E officers.');
    }

    public static function assignmentAlreadyActive(): self
    {
        return new self('That user already holds this role on this project.');
    }

    public static function indicatorBaselineIncomplete(): self
    {
        return new self(
            'An indicator cannot be activated without a baseline value, the date it was measured and its source — '
            .'a fabricated zero baseline is worse data than an explicit null.'
        );
    }

    public static function indicatorAlreadyActive(): self
    {
        return new self('That indicator is already active.');
    }

    public static function alreadyPublished(): self
    {
        return new self('That project is already published.');
    }
}
