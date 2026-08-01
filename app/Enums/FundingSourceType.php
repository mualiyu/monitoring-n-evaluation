<?php

namespace App\Enums;

/**
 * Where the money comes from. Seeded funding sources are instance-specific;
 * this enum is the stable classification reports aggregate along.
 */
enum FundingSourceType: string
{
    case InternalRevenue = 'internal_revenue';
    case FederalAllocation = 'federal_allocation';
    case Loan = 'loan';
    case Grant = 'grant';
    case Donor = 'donor';
    case Ppp = 'ppp';
    /** The state's own contribution alongside a donor/loan source. */
    case Counterpart = 'counterpart';

    public function label(): string
    {
        return match ($this) {
            self::InternalRevenue => __('Internally Generated Revenue'),
            self::FederalAllocation => __('Federal Allocation'),
            self::Loan => __('Loan'),
            self::Grant => __('Grant'),
            self::Donor => __('Donor Funded'),
            self::Ppp => __('Public-Private Partnership'),
            self::Counterpart => __('Counterpart Funding'),
        };
    }
}
