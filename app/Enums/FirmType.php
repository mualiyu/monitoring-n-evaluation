<?php

namespace App\Enums;

/**
 * What kind of firm a registry entry is. Evaluation/supervision consultants
 * are procured the same way contractors are, so they share one registry.
 */
enum FirmType: string
{
    case Contractor = 'contractor';
    case ConsultantFirm = 'consultant_firm';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Contractor => __('Contractor'),
            self::ConsultantFirm => __('Consultancy Firm'),
            self::Supplier => __('Supplier'),
        };
    }
}
