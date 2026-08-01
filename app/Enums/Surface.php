<?php

namespace App\Enums;

/**
 * The three entry surfaces of the platform, resolved from the request host.
 */
enum Surface: string
{
    case Portal = 'portal';
    case Oversight = 'oversight';
    case Tenant = 'tenant';

    public function dashboardRoute(): string
    {
        return match ($this) {
            self::Portal => 'portal.home',
            self::Oversight => 'oversight.dashboard',
            self::Tenant => 'tenant.dashboard',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Portal => __('Public portal'),
            self::Oversight => __('State oversight'),
            self::Tenant => __('Workspace'),
        };
    }
}
