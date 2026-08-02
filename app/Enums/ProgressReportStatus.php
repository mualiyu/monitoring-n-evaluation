<?php

namespace App\Enums;

/**
 * The submission chain of a progress report (progress-reporting.md §2):
 * the author drafts and submits, the M&E focal officer reviews, the director
 * approves — and either of the two can return it, with a reason, for rework.
 *
 * `consolidated` is deliberately absent: it arrives with the Phase 2
 * consolidation workspace, and because the column is a string, adding it then
 * is a code change rather than a migration.
 *
 * App\Actions\Reporting\TransitionProgressReportStatus is the only writer of
 * ProgressReport::$status and consults this table first — an impossible move
 * is impossible for everyone, before any permission is considered.
 */
enum ProgressReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Returned = 'returned';

    /**
     * States reachable from this one. Note what is NOT here:
     * `submitted → approved` skips review, and `approved` is terminal in
     * Phase 1 — an approved figure has already moved the project's numbers.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Reviewed, self::Returned],
            self::Reviewed => [self::Approved, self::Returned],
            // A returned report goes back to its author, who resubmits it.
            self::Returned => [self::Submitted],
            self::Approved => [],
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
     * Whether the author may still edit the narrative, the figures and the
     * evidence. Attachments freeze at submission — evidence that can change
     * after review is not evidence (§5).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    /** Whether the report has been filed, i.e. it counts for compliance. */
    public function isFiled(): bool
    {
        return $this !== self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Submitted => __('Submitted'),
            self::Reviewed => __('Reviewed'),
            self::Approved => __('Approved'),
            self::Returned => __('Returned'),
        };
    }
}
