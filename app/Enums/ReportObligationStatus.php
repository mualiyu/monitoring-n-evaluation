<?php

namespace App\Enums;

/**
 * The lifecycle of a single "this project owes a return for this window" row
 * (progress-reporting.md §1.2). This is the column the compliance league table
 * counts, so its vocabulary is the vocabulary of the rewards/sanctions board.
 *
 * `fulfilled` is reached at SUBMISSION, not approval (§3.1): the manual's
 * sanctions are about whether the MDA filed on time, and counting only
 * approved returns would punish an MDA for its director's inaction.
 */
enum ReportObligationStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Waived = 'waived';
    case Missed = 'missed';

    /**
     * Whether the deadline engine still chases this row. Waived and missed
     * obligations are silent — a waiver that keeps sending reminders is a
     * waiver nobody believes.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Fulfilled => __('Fulfilled'),
            self::Waived => __('Waived'),
            self::Missed => __('Missed'),
        };
    }
}
