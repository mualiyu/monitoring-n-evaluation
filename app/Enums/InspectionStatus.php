<?php

namespace App\Enums;

/**
 * The lifecycle of one site visit: it is put in the diary, the inspector
 * arrives and opens the form, the Field Trip Report is filed, and an M&E
 * officer signs it off. `cancelled` is the exit from anything not yet filed —
 * a flooded road, a postponed visit, a project suspended overnight.
 *
 * App\Actions\Inspections\TransitionInspectionStatus is the ONLY writer of
 * SiteInspection::$status and consults this table first, before any permission
 * is considered: an impossible move is impossible for everyone.
 *
 * Note what is absent. There is no `returned` rung. A progress report is the
 * MDA's claim and can be handed back for correction; an inspection is the
 * state's own observation of a site on a date, and asking the inspector who
 * stood there to "correct" it is how observations become negotiable. A review
 * that disagrees with the findings records its own notes against them, and a
 * second visit is a second inspection.
 */
enum InspectionStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Cancelled = 'cancelled';

    /**
     * States reachable from this one.
     *
     * `scheduled → submitted` is deliberately absent: the conduct form is
     * where GPS, checklist and photographs are captured, and a report filed
     * without ever opening it is a desk exercise wearing a site visit's name.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Submitted, self::Cancelled],
            // A filed report is already a government record: it is signed off,
            // never withdrawn.
            self::Submitted => [self::Reviewed],
            self::Reviewed, self::Cancelled => [],
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
     * Whether the inspector may still edit the findings, the checklist and the
     * evidence. Everything freezes at submission — evidence that can change
     * after review is not evidence.
     */
    public function isEditable(): bool
    {
        return $this === self::InProgress;
    }

    /** Whether the visit is still expected to happen. */
    public function isOpen(): bool
    {
        return $this === self::Scheduled || $this === self::InProgress;
    }

    /** Whether the Field Trip Report has been filed. */
    public function isFiled(): bool
    {
        return $this === self::Submitted || $this === self::Reviewed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::InProgress => __('Visit under way'),
            self::Submitted => __('Report filed'),
            self::Reviewed => __('Signed off'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * The badge key for <x-ui.badge>. `in_progress` and `submitted` already
     * exist in the shared status map; the two that do not are mapped onto the
     * closest shared vocabulary rather than by adding module-specific entries
     * to a component every module shares.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Scheduled => 'pending',
            self::InProgress => 'in_progress',
            self::Submitted => 'submitted',
            self::Reviewed => 'approved',
            self::Cancelled => 'cancelled',
        };
    }
}
