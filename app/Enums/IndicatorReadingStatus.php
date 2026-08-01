<?php

namespace App\Enums;

/**
 * Lifecycle of a single indicator reading. Validation is a distinct hop from
 * submission (the data-quality reviewer's job), and publication is a further
 * explicit act — nothing reaches a public surface by default.
 */
enum IndicatorReadingStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Validated = 'validated';
    case Published = 'published';

    /** Whether the figure has cleared data-quality review. */
    public function isValidated(): bool
    {
        return $this === self::Validated || $this === self::Published;
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
}
