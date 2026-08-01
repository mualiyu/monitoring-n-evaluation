<?php

namespace App\Enums;

/**
 * Budget classification of a project — capital works, a programme of
 * activities, or recurrent expenditure.
 */
enum ProjectType: string
{
    case Capital = 'capital';
    case Programme = 'programme';
    case Recurrent = 'recurrent';

    public function label(): string
    {
        return match ($this) {
            self::Capital => __('Capital Project'),
            self::Programme => __('Programme'),
            self::Recurrent => __('Recurrent'),
        };
    }
}
