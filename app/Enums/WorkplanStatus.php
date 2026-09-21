<?php

namespace App\Enums;

/**
 * The approval chain of an annual work plan (PROJECT_PLAN §4 "Results
 * framework → workplans"; ondo-manual-digest §6 "Annual Work Plan (AWPB)").
 *
 * A plan is drafted by the MDA's M&E unit, submitted for the accounting
 * officer's approval, and only then becomes the year's committed programme.
 * `active` is separated from `approved` deliberately: a plan approved in
 * November for the following January is signed but not yet running, and the
 * two questions "has this been authorised?" and "is this the plan we are
 * working to today?" have different answers for two months of every year.
 *
 * App\Actions\Workplans\TransitionWorkplanStatus is the only writer of
 * Workplan::$status and consults this table first — an impossible move is
 * impossible for everyone, before any permission is considered.
 */
enum WorkplanStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Active = 'active';
    case Closed = 'closed';
    case Rejected = 'rejected';

    /**
     * States reachable from this one. Note what is NOT here:
     * `draft → approved` skips the submission that names a submitter (and
     * therefore the separation-of-duties guard), `active → draft` would let a
     * running year's committed programme be rewritten in place, and `closed`
     * is terminal — a closed year is history, and history is amended through
     * a new plan, never by reopening the old one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected],
            // A plan may be closed straight from `approved`: a programme
            // cancelled before its year began still has to be closed off.
            self::Approved => [self::Active, self::Closed],
            self::Active => [self::Closed],
            // Back to its author, who revises and resubmits.
            self::Rejected => [self::Submitted],
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
     * Whether ACTIVITY DEFINITIONS may still be added, edited or removed.
     *
     * The freeze mirrors the project certification freeze
     * (App\Actions\Projects\UpdateProjectDetails): from `approved` onward the
     * scope, schedule and budget an approving officer signed for stop moving.
     * It is emphatically NOT a freeze on the record — recording progress,
     * expenditure and actual dates against an approved plan is the entire
     * point of having one, and no progress Action consults this method.
     */
    public function allowsDefinitionEdits(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Whether the plan has been signed off, i.e. it commits the MDA. */
    public function isApproved(): bool
    {
        return $this === self::Approved || $this === self::Active || $this === self::Closed;
    }

    /** Whether progress may still be recorded against its activities. */
    public function acceptsProgress(): bool
    {
        return $this === self::Approved || $this === self::Active;
    }

    /** Whether the plan is awaiting somebody's decision. */
    public function isAwaitingDecision(): bool
    {
        return $this === self::Submitted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Submitted => __('Submitted'),
            self::Approved => __('Approved'),
            self::Active => __('Active'),
            self::Closed => __('Closed'),
            self::Rejected => __('Rejected'),
        };
    }

    /**
     * Badge key for <x-ui.badge>. `active` has no entry in the component's
     * map, so it borrows the vocabulary the map already carries for the same
     * meaning — work is happening. Everything else maps by name. Pass
     * :label="$status->label()" alongside it: the wording is this enum's,
     * because MDA terminology is configurable per instance.
     */
    public function badge(): string
    {
        return $this === self::Active ? 'in_progress' : $this->value;
    }
}
