<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform's whole authorization rule in one place: **permission AND
 * tenant match**. A permission says what a role may do; the tenant check says
 * whose records it may do it to. Either alone is a leak — spatie grants the
 * permission through a role held in *some* team, and a policy that only asks
 * "can you?" would let an MDA admin act on a project loaded by id from another
 * MDA (the global scope makes that hard, never impossible: relations, oversight
 * bypasses and route-model bindings all hand policies foreign models).
 */
trait ChecksTenantAuthority
{
    /**
     * Two ways to hold authority, and only two:
     *
     *  1. **Workspace authority** — the permission in THIS workspace, over a
     *     record of THIS workspace. A tenant role grants nothing anywhere else.
     *  2. **Oversight authority** — the permission in the GLOBAL team. State
     *     roles are cross-MDA by construction (the design gives StateAdmin
     *     `projects.close|suspend|cancel` and every oversight role
     *     `projects.view`), and the oversight surface binds no tenant at all,
     *     so rule 1 can never express them.
     *
     * A tenant user can never reach branch 2: AssignRole refuses to put a
     * tenant role in the global team, so their global permission set is empty.
     * This is emphatically NOT a Gate::before bypass — every ability still
     * asks for its own seeded permission, and a SuperAdmin who was never
     * granted one is refused like anyone else.
     *
     * @param  Model|null  $record  a tenant-owned record; null for class-level abilities
     */
    protected function permits(User $user, string $permission, ?Model $record = null): bool
    {
        if ($user->can($permission) && ($record === null || $this->belongsToCurrentTenant($record))) {
            return true;
        }

        return $user->holdsGlobalPermission($permission);
    }

    protected function belongsToCurrentTenant(Model $record): bool
    {
        $tenantId = app(CurrentTenant::class)->id();

        // No tenant bound means an oversight/console context: tenant-owned
        // records are reached there through app/Actions/Oversight, which
        // authorizes on the oversight permission instead. Failing closed here
        // is what keeps "no context" from meaning "any context".
        return $tenantId !== null && (int) $record->getAttribute('tenant_id') === $tenantId;
    }
}
