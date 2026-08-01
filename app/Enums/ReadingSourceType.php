<?php

namespace App\Enums;

/**
 * Where an indicator reading came from: collected first-hand for this
 * indicator (primary) or taken from an existing record/administrative dataset
 * (secondary). The manual requires the distinction on every reading — it is
 * what a data-quality reviewer checks first.
 */
enum ReadingSourceType: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Primary => __('Primary Collection'),
            self::Secondary => __('Secondary Source'),
        };
    }
}
