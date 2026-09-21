<?php

namespace App\Enums;

/**
 * How hard a recommendation presses. The manual asks evaluators to present
 * recommendations "per user type, prioritized, costed, timetabled" (digest §4),
 * so priority is part of the record rather than a reader's impression of it.
 *
 * Four levels, matching the issues register's escalation vocabulary
 * (`exceptions.issue_escalation_days` in config/platform.php) so a state that
 * has already decided what "high" means does not have to decide twice.
 */
enum RecommendationPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Ranking for ordering a register — lower sorts first. Returned as an int
     * rather than relying on the enum's declaration order, because the sort is
     * done in SQL (a CASE expression) where declaration order means nothing.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => __('Critical'),
            self::High => __('High'),
            self::Medium => __('Medium'),
            self::Low => __('Low'),
        };
    }

    /**
     * The <x-ui.badge> key this priority borrows its TONE from. The shared
     * badge component is a platform component this module does not own, so
     * rather than inventing keys it would render as neutral pills reading
     * "Priority Critical", every call site passes :status (tone) together with
     * an explicit :label and :icon. Status is icon + text, never colour alone.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Critical => 'overdue',
            self::High => 'behind',
            self::Medium => 'pending',
            self::Low => 'draft',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Critical => 'exclamation-triangle',
            self::High => 'arrow-trending-up',
            self::Medium => 'minus',
            self::Low => 'chevron-down',
        };
    }

    /**
     * Options for a <x-ui.form.select> — value => label, most pressing first.
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
