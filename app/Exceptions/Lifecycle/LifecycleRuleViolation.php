<?php

namespace App\Exceptions\Lifecycle;

use App\Enums\CertificateType;
use App\Enums\CommencementNoticeStatus;
use App\Enums\ProjectStatus;
use DomainException;

/**
 * A domain precondition refused a commencement-notice or certification write.
 *
 * Same shape as App\Exceptions\Projects\ProjectRuleViolation — one class with
 * named constructors rather than a class per rule, so the rules are testable
 * by message, greppable in one place, and every Action states its guard in the
 * same vocabulary.
 *
 * These are *domain* failures, not authorization failures: an actor holding
 * `certificates.issue` still cannot certify a project whose works are not
 * finished. Authorization denials surface as Laravel's AuthorizationException
 * through the policies.
 */
class LifecycleRuleViolation extends DomainException
{
    /* ------------------------------------------------------------------ */
    /* Commencement notices */
    /* ------------------------------------------------------------------ */

    public static function noticeOnUnawardedProject(ProjectStatus $status): self
    {
        return new self(
            "A commencement notice cannot be served against a [{$status->value}] project — "
            .'there is nothing to commence until the contract is awarded.'
        );
    }

    public static function noticeAlreadyIssued(): self
    {
        return new self(
            'This contract already has a commencement notice. A served notice is withdrawn on the '
            .'record, never re-served as if the first had not happened.'
        );
    }

    public static function noticeNotIssued(): self
    {
        return new self('A notice that has not been served cannot be acknowledged.');
    }

    public static function noticeAlreadyAcknowledged(): self
    {
        return new self('This notice has already been acknowledged — receipt is confirmed once.');
    }

    public static function invalidNoticeTransition(CommencementNoticeStatus $from, CommencementNoticeStatus $to): self
    {
        $allowed = array_map(
            fn (CommencementNoticeStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        return new self(sprintf(
            'A commencement notice cannot move from [%s] to [%s]. Allowed from [%s]: %s.',
            $from->value,
            $to->value,
            $from->value,
            $allowed === [] ? 'nothing — it is a terminal state' : implode(', ', $allowed),
        ));
    }

    public static function commencementDateBeforeAward(): self
    {
        return new self('A contractor cannot be instructed to have commenced before the contract was awarded.');
    }

    /* ------------------------------------------------------------------ */
    /* Certification */
    /* ------------------------------------------------------------------ */

    /**
     * The Phase 2 rule the brief turns on: with
     * `monitoring.require_final_inspection_for_certification` set, a
     * certificate must rest on a final inspection that actually happened.
     */
    public static function certificationRequiresFinalInspection(): self
    {
        return new self(
            'This instance requires a completed final inspection before a project may be certified, '
            .'and none is recorded against it. Conduct and file the final inspection first.'
        );
    }

    public static function certificationBeforeCompletion(ProjectStatus $status): self
    {
        return new self(
            "A [{$status->value}] project cannot be certified — certification attests that the works "
            .'are finished, which is what the completed state records.'
        );
    }

    public static function certificateAlreadyIssued(CertificateType $type): self
    {
        return new self(sprintf(
            'A %s certificate is already in force for this project. Revoke it, on the record, before issuing another.',
            $type->shortLabel(),
        ));
    }

    public static function finalCompletionBeforePracticalCompletion(): self
    {
        return new self(
            'Final completion closes the defects-liability period that practical completion opens — '
            .'issue the practical completion certificate first.'
        );
    }

    public static function defectsLiabilityEndsBeforeIssue(): self
    {
        return new self('A defects-liability period cannot end before the certificate that opens it is issued.');
    }

    public static function certificateAlreadyRevoked(): self
    {
        return new self('That certificate has already been revoked.');
    }

    public static function revocationRequiresReason(): self
    {
        return new self(
            'Withdrawing a completion certificate requires a stated reason — it goes on the record and '
            .'an auditor will read it.'
        );
    }
}
