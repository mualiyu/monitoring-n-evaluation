<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * A tenant-owned model was queried or created with no tenant bound and no
 * explicit bypass. This is always a bug: tenant routes bind via middleware,
 * jobs via TenantAware, oversight code via withoutTenancy()/bypass().
 */
class TenantNotResolvedException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No tenant is bound to the current context. Tenant-owned models '
            .'require a resolved tenant, or an explicit withoutTenancy()/bypass() '
            .'in oversight-surface code.'
        );
    }
}
