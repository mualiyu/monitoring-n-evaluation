<?php

namespace App\Enums;

/**
 * Procurement category of a contract. Mirrors FirmType: works contracts go to
 * contractors, consultancy to consultant firms, supply to suppliers.
 */
enum ContractType: string
{
    case Works = 'works';
    case Supply = 'supply';
    case Consultancy = 'consultancy';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Works => __('Works'),
            self::Supply => __('Supply'),
            self::Consultancy => __('Consultancy'),
            self::Service => __('Service'),
        };
    }
}
