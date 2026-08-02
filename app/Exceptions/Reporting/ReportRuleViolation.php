<?php

namespace App\Exceptions\Reporting;

use App\Enums\ProgressReportStatus;
use DomainException;

/**
 * A domain precondition refused the write (progress-reporting.md §2, §3).
 * One class with named constructors, as in the projects module: the rules are
 * testable by message, greppable in one place, and every Action that guards
 * something states the guard in the same vocabulary.
 *
 * These are *domain* failures, not authorization failures — a director with
 * every permission still cannot approve a report they submitted themselves.
 * Authorization denials surface as Laravel's AuthorizationException.
 */
class ReportRuleViolation extends DomainException
{
    public static function narrativeRequired(): self
    {
        return new self(
            'A progress report cannot be submitted without an account of the work done in the period — '
            .'an empty return is not a return.'
        );
    }

    public static function decreaseRequiresReason(string $claimed, string $current): self
    {
        return new self(
            "The claim of {$claimed}% is below the project's recorded {$current}% — a downward revision "
            .'requires a stated reason (re-measurement, defective work removed), because the figure everyone '
            .'trusts must stay correctable AND explained.'
        );
    }

    public static function progressOutOfRange(string $progress): self
    {
        return new self("Physical progress must be between 0 and 100 — [{$progress}] is not.");
    }

    public static function periodNotOpen(string $code): self
    {
        return new self("Reporting window [{$code}] is not open for submissions yet.");
    }

    public static function periodClosed(string $code): self
    {
        return new self(
            "Reporting window [{$code}] is closed and this instance does not accept late returns "
            .'(`reporting.allow_late_submission`).'
        );
    }

    public static function reasonRequired(): self
    {
        return new self('Returning a progress report requires a stated reason — the author has to know what to fix.');
    }

    public static function reviewerIsSubmitter(): self
    {
        return new self(
            'The officer who submitted a report cannot review it. On the on-behalf path the same person '
            .'would otherwise file and clear their own return.'
        );
    }

    public static function approverIsSubmitter(): self
    {
        return new self('The person who submitted a report cannot approve it.');
    }

    public static function approverIsReviewer(): self
    {
        return new self(
            'The reviewer of a report cannot also approve it while `reporting.require_separate_approver` '
            .'is on — approval is a second pair of eyes or it is nothing.'
        );
    }

    public static function notEditable(ProgressReportStatus $status): self
    {
        return new self(
            "A [{$status->value}] progress report is no longer editable — figures and evidence freeze at submission."
        );
    }

    public static function discardRequiresDraft(ProgressReportStatus $status): self
    {
        return new self(
            "Only a draft may be discarded; this report is [{$status->value}]. A filed return is a government record."
        );
    }

    public static function liveReportExists(): self
    {
        return new self('This project already has a live report for that window — open it instead of starting another.');
    }

    public static function projectNotReportable(string $status): self
    {
        return new self("A [{$status}] project does not report progress.");
    }

    public static function obligationNotOutstanding(string $status): self
    {
        return new self("Only a pending obligation can be waived; this one is [{$status}].");
    }

    public static function waiverRequiresReason(): self
    {
        return new self('Waiving a reporting obligation requires a stated reason — it goes on the compliance record.');
    }

    public static function contractorRequiredOnBehalf(): self
    {
        return new self(
            'An on-behalf return must name the firm whose figures these are — provenance is recorded, never blurred.'
        );
    }
}
