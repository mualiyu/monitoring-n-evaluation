<?php

namespace App\Enums;

/**
 * The life of an evaluation, from the commission to the published report
 * (plan §4 "Evaluation"; digest §3 report-preparation workflow).
 *
 * The manual's eight-step workflow — stakeholders → inception → data
 * collection → validation meetings → draft → stakeholder validation →
 * publication → dissemination — collapses into six states plus `cancelled`,
 * because only six of those steps are moments where the record's AUTHORITY
 * changes hands. Inception meetings and validation workshops are events that
 * happen inside `in_progress`; they are recorded as documents and findings,
 * not as lifecycle states nobody can transition out of.
 *
 * App\Actions\Evaluation\TransitionEvaluationStatus is the only writer of
 * Evaluation::$status and consults this table FIRST — an impossible move is
 * impossible for everyone, before any permission is considered.
 */
enum EvaluationStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case DraftReport = 'draft_report';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Published = 'published';
    case Cancelled = 'cancelled';

    /**
     * States reachable from this one. Note what is NOT here:
     *  - nothing skips `under_review`, so no report reaches `approved` without
     *    a named approver who is neither its lead nor the person who filed it;
     *  - `published` is terminal — a published evaluation is a public artifact,
     *    and a state that could walk back out of it would let an MDA unpublish
     *    findings it has since decided it dislikes. Publication is corrected by
     *    a superseding evaluation, which is what the recommendations register's
     *    `superseded` status exists to record;
     *  - `cancelled` is reachable from every live state and is terminal: an
     *    abandoned commission is closed on the record, never deleted.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::InProgress, self::Cancelled],
            self::InProgress => [self::DraftReport, self::Cancelled],
            self::DraftReport => [self::UnderReview, self::Cancelled],
            // Review sends the draft back with a reason, exactly as the
            // progress-report chain does — rework is not rejection.
            self::UnderReview => [self::Approved, self::DraftReport, self::Cancelled],
            self::Approved => [self::Published],
            self::Published, self::Cancelled => [],
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
     * Whether the team may still write the report sections, the criterion
     * scores and the team roster. Everything freezes when the report goes up
     * for review — findings that can change while they are being approved are
     * not findings.
     */
    public function isEditable(): bool
    {
        return $this === self::Planned
            || $this === self::InProgress
            || $this === self::DraftReport;
    }

    /** Whether the findings are settled enough to quote outside the team. */
    public function isSettled(): bool
    {
        return $this === self::Approved || $this === self::Published;
    }

    /** Whether work is still expected against this commission. */
    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::InProgress => __('In progress'),
            self::DraftReport => __('Draft report'),
            self::UnderReview => __('Under review'),
            self::Approved => __('Approved'),
            self::Published => __('Published'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * The <x-ui.badge> key this status borrows its TONE from. The badge
     * component belongs to the shared design system, which this module does
     * not own, so every call site passes :status (tone) together with an
     * explicit :label and :icon rather than adding keys to someone else's map.
     * Status is icon + text there, never colour alone.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Planned => 'draft',
            self::InProgress => 'in_progress',
            self::DraftReport => 'draft',
            self::UnderReview => 'under_review',
            self::Approved => 'approved',
            self::Published => 'certified',
            self::Cancelled => 'cancelled',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Planned => 'calendar-days',
            self::InProgress => 'arrow-path',
            self::DraftReport => 'document-text',
            self::UnderReview => 'eye',
            self::Approved => 'check-circle',
            self::Published => 'globe',
            self::Cancelled => 'x-mark',
        };
    }

    /**
     * Options for a <x-ui.form.select> — value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
