<?php

namespace App\Exceptions\Inspections;

use App\Enums\InspectionStatus;
use DomainException;

/**
 * A domain precondition refused the write. One class with named constructors,
 * as in the projects and reporting modules: the rules are testable by message,
 * greppable in one place, and every Action that guards something states the
 * guard in the same vocabulary.
 *
 * These are *domain* failures, not authorization failures — an M&E officer
 * holding every permission still cannot sign off an inspection they conducted
 * themselves. Authorization denials surface as AuthorizationException.
 */
class InspectionRuleViolation extends DomainException
{
    public static function reviewerIsInspector(): self
    {
        return new self(
            'The inspector who conducted a visit cannot sign off their own report. An inspection is the '
            .'state\'s assurance over delivery; assurance a person performs on their own field work is not assurance.'
        );
    }

    public static function reasonRequired(): self
    {
        return new self('Cancelling a site visit requires a stated reason — a visit that silently disappears from the diary is indistinguishable from one nobody bothered to make.');
    }

    public static function outcomeRequired(): self
    {
        return new self('A Field Trip Report cannot be filed without a verdict on the site — an inspection with no outcome is a visit, not an inspection.');
    }

    public static function findingsRequired(): self
    {
        return new self(
            'A Field Trip Report cannot be filed without findings. The manual asks every field visit to record '
            .'what was observed; an empty findings section is a trip, not a report.'
        );
    }

    public static function photoEvidenceRequired(): self
    {
        return new self(
            'At least one photograph is required before this report may be filed '
            .'(`inspections.require_photo_evidence`). A site visit with no image of the site is an assertion.'
        );
    }

    public static function requiredResponsesMissing(int $count): self
    {
        return new self(
            "The checklist has {$count} required item(s) still unanswered. A partially answered instrument "
            .'cannot be compared with the one answered at the last visit, which is what the checklist is for.'
        );
    }

    public static function findingNeedsNote(string $prompt): self
    {
        return new self(
            "The answer to \"{$prompt}\" is a finding and needs a note saying what is actually wrong — "
            .'a flag with no explanation cannot be acted on by anyone.'
        );
    }

    public static function notEditable(InspectionStatus $status): self
    {
        return new self(
            "A [{$status->value}] inspection is no longer editable — findings, checklist and evidence freeze at submission."
        );
    }

    public static function notUnderWay(InspectionStatus $status): self
    {
        return new self(
            "Checklist answers are recorded while the visit is under way; this inspection is [{$status->value}]. "
            .'Open the conduct form first.'
        );
    }

    public static function projectNotInspectable(string $status): self
    {
        return new self("A [{$status}] project cannot be scheduled for a site inspection.");
    }

    public static function inspectorNotAuthorised(): self
    {
        return new self(
            'The named lead inspector does not hold `inspections.conduct` in this workspace — scheduling a visit '
            .'for someone who cannot file its report guarantees an overdue report.'
        );
    }

    public static function scheduledDateInPast(): self
    {
        return new self('A site visit cannot be scheduled for a date that has already passed. Record it as conducted instead.');
    }

    public static function itemNotOnInstrument(): self
    {
        return new self(
            'That checklist item does not belong to the instrument this inspection is being conducted against. '
            .'Answers are recorded against the questions that were actually asked.'
        );
    }

    public static function progressOutOfRange(string $progress): self
    {
        return new self("Observed physical progress must be between 0 and 100 — [{$progress}] is not.");
    }

    public static function coordinatesOutOfRange(): self
    {
        return new self('That position is not a point on Earth — latitude must be within ±90 and longitude within ±180.');
    }
}
