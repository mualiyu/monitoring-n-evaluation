<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Where one work-plan activity stands (ondo-manual-digest §6, the
 * implementation-plan appendix: `no | activity | owner | month × week`).
 *
 * Unlike WorkplanStatus this is NOT an approval chain — it is a derived
 * position that follows from two facts the officer actually reports: how much
 * of the activity is done, and whether its planned end date has passed. The
 * derivation lives in derive() and nowhere else, so the Gantt bar, the roll-up
 * and the overdue sweep can never disagree about whether an activity is late.
 *
 * `cancelled` is the one state that is never derived: dropping an activity is
 * a decision, not a consequence, and once taken it sticks.
 */
enum ActivityStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';

    /**
     * The single definition of an activity's standing.
     *
     * Order matters and is deliberate:
     *  1. 100% is complete, late or not — an activity delivered after its
     *     planned date is still delivered, and colouring it red forever
     *     teaches officers that the flag means nothing.
     *  2. Past its planned end and not complete is DELAYED, whether or not
     *     work has started; a activity at 0% on 31 December is the worst
     *     case, not an exempt one.
     *  3. Otherwise it is simply started or not.
     *
     * @param  int  $progressPercent  0–100
     */
    public static function derive(
        int $progressPercent,
        CarbonImmutable $plannedEnd,
        CarbonImmutable $asOf,
    ): self {
        if ($progressPercent >= 100) {
            return self::Completed;
        }

        if ($plannedEnd->endOfDay()->isBefore($asOf)) {
            return self::Delayed;
        }

        return $progressPercent > 0 ? self::InProgress : self::NotStarted;
    }

    /** Whether the activity has stopped consuming schedule — done or dropped. */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * Whether the activity still counts towards the plan's roll-up. A
     * cancelled activity is out of the plan: leaving it in at 0% would drag a
     * well-run programme's progress down for work nobody is doing any more
     * (see App\Support\WorkplanProgress).
     */
    public function countsTowardProgress(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Whether the overdue sweep should consider this activity at all. */
    public function canFallOverdue(): bool
    {
        return ! $this->isFinished();
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => __('Not started'),
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
            self::Delayed => __('Delayed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * Badge key for <x-ui.badge>. `delayed` borrows the `behind` entry so a
     * late activity gets the warning tone AND the trending-down icon the rest
     * of the platform already uses for slippage; the wording comes from
     * label(). `not_started` has no entry and degrades to a neutral pill,
     * which is exactly right for work that has not begun.
     */
    public function badge(): string
    {
        return $this === self::Delayed ? 'behind' : $this->value;
    }

    /** Icon for the plain-text/Gantt contexts that do not render a badge. */
    public function icon(): string
    {
        return match ($this) {
            self::NotStarted => 'clock',
            self::InProgress => 'arrow-path',
            self::Completed => 'check-circle',
            self::Delayed => 'exclamation-triangle',
            self::Cancelled => 'x-mark',
        };
    }
}
