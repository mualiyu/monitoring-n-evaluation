<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * An attempt to create a record for a different tenant than the bound one,
 * or to reassign an existing record's tenant_id. Both are forbidden outside
 * an explicit bypass (seeders, oversight backfills).
 */
class CrossTenantWriteException extends RuntimeException
{
    public static function forCreate(string $model): self
    {
        return new self(
            "Refusing to create [{$model}] with a tenant_id that does not match the bound tenant."
        );
    }

    public static function forReassignment(string $model): self
    {
        return new self("tenant_id is immutable on [{$model}] — records never change tenants.");
    }
}
