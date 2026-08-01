<?php

namespace App\Enums;

/**
 * Project lifecycle states. The transition table is the single source of truth
 * for what may follow what — App\Actions\Projects\TransitionProjectStatus is
 * the only writer of Project::$status and consults this enum first.
 *
 * `mid_term` is deliberately absent: mid-term evaluation is an *event* raised
 * at a configurable physical-progress threshold (Phase 2 inspections), not a
 * lifecycle state — a project under mid-term evaluation is still in_progress.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Awarded = 'awarded';
    case Mobilized = 'mobilized';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Certified = 'certified';
    case Closed = 'closed';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /**
     * States reachable from this one. Suspension returns to in_progress
     * (work resumes) or cancellation; closed/cancelled are absorbing.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Awarded, self::Cancelled],
            self::Awarded => [self::Mobilized, self::Suspended, self::Cancelled],
            self::Mobilized => [self::InProgress, self::Suspended, self::Cancelled],
            self::InProgress => [self::Completed, self::Suspended, self::Cancelled],
            self::Completed => [self::Certified, self::InProgress],
            self::Certified => [self::Closed],
            self::Suspended => [self::InProgress, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Terminal states accept no further transition. Note that `closed` is not
     * "locked": post-completion monitoring still attaches to a closed project.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the scope/financial record is frozen against edits (amendments
     * become a Phase 2 amendment-register concern).
     */
    public function isFrozen(): bool
    {
        return $this === self::Certified || $this === self::Closed;
    }

    /** States at or beyond contract award — i.e. the project has a contract. */
    public function isAwardedOrBeyond(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Awarded => __('Awarded'),
            self::Mobilized => __('Mobilized'),
            self::InProgress => __('In Progress'),
            self::Completed => __('Completed'),
            self::Certified => __('Certified'),
            self::Closed => __('Closed'),
            self::Suspended => __('Suspended'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
