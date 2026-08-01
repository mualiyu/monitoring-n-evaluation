<?php

namespace App\Enums;

enum TenantType: string
{
    case Ministry = 'ministry';
    case Department = 'department';
    case Agency = 'agency';

    public function label(): string
    {
        return match ($this) {
            self::Ministry => __('Ministry'),
            self::Department => __('Department'),
            self::Agency => __('Agency'),
        };
    }
}
