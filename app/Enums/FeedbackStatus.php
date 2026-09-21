<?php

namespace App\Enums;

/**
 * Moderation state of a stakeholder's feedback — the gate between "a citizen
 * typed something" and "the state published it under its own name".
 *
 * Nothing a member of the public submits is ever visible on the portal until a
 * moderator moves it here, deliberately, from `pending`. That is the same rule
 * the publishing gate applies to projects (rules/security.md §"Public portal
 * is read-only"), applied to the one thing on the portal that is written
 * rather than read.
 *
 * App\Actions\Feedback\ModerateFeedback is the ONLY writer of the column, and
 * it consults this transition table first.
 *
 * The one edge deliberately missing is `spam → published`: releasing something
 * already classified as spam has to travel back through `rejected`, so that
 * publishing junk to a government website is never one mis-click.
 */
enum FeedbackStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
    case Spam = 'spam';

    /**
     * States reachable from this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Published, self::Rejected, self::Spam],
            // Withdrawal must stay possible: publishing something that turns
            // out to be defamatory or to name a child is a mistake the state
            // has to be able to undo within the minute.
            self::Published => [self::Rejected, self::Spam],
            self::Rejected => [self::Published, self::Spam],
            self::Spam => [self::Rejected],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Whether the portal may render this record to an anonymous visitor. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** Whether a moderator has already ruled on it. */
    public function isModerated(): bool
    {
        return $this !== self::Pending;
    }

    /** Moving here is a judgement about the submitter, so it needs a reason. */
    public function requiresReason(): bool
    {
        return $this === self::Rejected || $this === self::Spam;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting moderation'),
            self::Published => __('Published'),
            self::Rejected => __('Not published'),
            self::Spam => __('Spam'),
        };
    }

    /** Icon + text, never colour alone (rules/ui-design-system.md). */
    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'clock',
            self::Published => 'globe',
            self::Rejected => 'x-circle',
            self::Spam => 'exclamation-triangle',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Published => 'positive',
            self::Rejected => 'warning',
            self::Spam => 'critical',
        };
    }

    /** @return array<string, string> value => label, for a filter select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
