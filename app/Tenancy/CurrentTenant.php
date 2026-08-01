<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Container-scoped holder for the resolved tenant of the current request,
 * job, or command. Bound as a scoped singleton — never persists across
 * Octane/queue-worker iterations.
 *
 * Setting/forgetting the tenant also synchronizes the spatie permission team
 * and clears cached role relations on the authenticated user — otherwise a
 * context switch would serve tenant A's roles inside tenant B.
 */
class CurrentTenant
{
    /**
     * Sentinel team id for oversight/global role assignments — spatie's
     * teams pivot is non-nullable, so "no tenant" is team 0 by convention.
     */
    public const GLOBAL_TEAM = 0;

    private ?Tenant $tenant = null;

    private int $bypassDepth = 0;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->syncPermissionTeam($tenant->id);
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->syncPermissionTeam(self::GLOBAL_TEAM);
    }

    public function bound(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function getOrFail(): Tenant
    {
        return $this->tenant ?? throw TenantNotResolvedException::make();
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function idOrFail(): int
    {
        return $this->getOrFail()->id;
    }

    /**
     * Whether tenant scoping is explicitly bypassed (oversight/system context).
     */
    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Run a callback with tenant scoping bypassed. Oversight-surface code and
     * seeders/backfills only — anywhere else is a bug. Nesting-safe.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function bypass(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /**
     * Run a callback with no tenant bound (global/oversight context),
     * restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->forget();

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $this->set($previous);
            }
        }
    }

    /**
     * Run a callback with the given tenant bound, restoring the previous
     * context afterwards. The canonical way for seeders, jobs and oversight
     * code to act "as" a tenant.
     *
     * NOTE: role/permission relations are cleared only on the authenticated
     * user at each switch. Code iterating OTHER User instances across
     * contexts must call $user->unsetRelation('roles') itself — spatie bakes
     * the team id into the relation at load time.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->forget() : $this->set($previous);
        }
    }

    private function syncPermissionTeam(int $teamId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);

        // Spatie caches role/permission relations per user instance; a stale
        // cache after a team switch would leak cross-tenant authority.
        auth()->user()?->unsetRelation('roles')->unsetRelation('permissions');
    }
}
