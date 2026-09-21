<?php

namespace App\Enums;

/**
 * How much the issue matters. Four bands, because the escalation ladder is
 * keyed on them: `platform.exceptions.issue_escalation_days` maps a severity
 * to the number of days it may sit open before the engine escalates it, so a
 * fifth band would be a policy change, not a cosmetic one.
 *
 * Severity is a judgement the raiser makes and an M&E officer may revise; it
 * is deliberately NOT derived from the category. "No cash released" is
 * critical on a road that is 90% built and routine on one that has not
 * mobilised.
 */
enum IssueSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Sort weight — highest first on every board, because the reason an
     * oversight officer opens one is to find the worst thing on it.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    /**
     * The badge tone this severity renders with. `critical` and `high` are
     * deliberately different tones rather than two shades of red: a board on
     * which everything is red tells a director nothing.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Critical => 'critical',
            self::High => 'warning',
            self::Medium => 'info',
            self::Low => 'neutral',
        };
    }

    /**
     * Which <x-ui.badge> tone this severity borrows — see the note on
     * IssueStatus::badgeStatus(). Critical and High are deliberately
     * different tones rather than two shades of red: a board on which
     * everything is red tells a director nothing.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Critical => 'overdue',   // critical
            self::High => 'behind',        // warning
            self::Medium => 'submitted',   // info
            self::Low => 'pending',        // neutral
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Critical => 'exclamation-triangle',
            self::High => 'arrow-trending-up',
            self::Medium => 'information-circle',
            self::Low => 'minus',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
            self::Critical => __('Critical'),
        };
    }

    /** Severities ordered worst-first — the order every board sorts by. */
    /** @return list<self> */
    public static function descending(): array
    {
        return [self::Critical, self::High, self::Medium, self::Low];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
