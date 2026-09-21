<?php

namespace App\Enums;

/**
 * Lifecycle of a single indicator reading. Validation is a distinct hop from
 * submission (the data-quality reviewer's job), and publication is a further
 * explicit act — nothing reaches a public surface by default.
 *
 * App\Actions\Indicators\TransitionIndicatorReadingStatus is the ONLY writer
 * of IndicatorReading::$status and consults this table first: an impossible
 * move is impossible for everyone, before any permission is considered.
 */
enum IndicatorReadingStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Validated = 'validated';
    case Published = 'published';

    /**
     * States reachable from this one. Note what is NOT here:
     * `draft → validated` skips the reviewer, `submitted → published` skips
     * data-quality review entirely, and `published` is terminal — a figure
     * that has been quoted outside the platform is corrected by recording
     * another reading, never by editing the one people already have.
     *
     * Both rejections land on `draft`, because rejection means "this goes back
     * to the person who measured it", and that person's workspace is where a
     * draft lives.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Validated, self::Draft],
            // A validated figure may still be pulled back before publication:
            // the reviewer who finds the error at 16:00 must not have to
            // publish it first in order to correct it.
            self::Validated => [self::Published, self::Draft],
            self::Published => [],
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

    /** Whether the recorder may still change the figure, its source and its notes. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether the figure has cleared data-quality review. */
    public function isValidated(): bool
    {
        return $this === self::Validated || $this === self::Published;
    }

    /** Whether the reading is sitting in the Data Quality Reviewer's queue. */
    public function awaitsValidation(): bool
    {
        return $this === self::Submitted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Submitted => __('Submitted'),
            self::Validated => __('Validated'),
            self::Published => __('Published'),
        };
    }

    /** Status is icon + text, never colour alone (rules/ui-design-system.md). */
    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'pencil-square',
            self::Submitted => 'paper-airplane',
            self::Validated => 'shield-check',
            self::Published => 'globe',
        };
    }
}
