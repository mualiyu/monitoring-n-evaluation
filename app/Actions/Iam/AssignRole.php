<?php

namespace App\Actions\Iam;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use InvalidArgumentException;

/**
 * The single sanctioned way to grant a role. Enforces the role/team contract
 * that spatie cannot: oversight roles live in the global team (sentinel 0)
 * and may never be tenant-scoped; tenant roles require a tenant and may never
 * land in the global team. Direct $user->assignRole() calls outside this
 * action are a bug (enforced by the tenancy discipline test).
 */
class AssignRole
{
    public function __invoke(User $user, Role $role, ?Tenant $tenant = null): void
    {
        $isTenantRole = in_array($role, Role::tenantRoles(), true);

        if ($isTenantRole && $tenant === null) {
            throw new InvalidArgumentException(
                "Tenant role [{$role->value}] requires a tenant — a global assignment would be invisible in every MDA."
            );
        }

        if (! $isTenantRole && $tenant !== null) {
            throw new InvalidArgumentException(
                "Oversight role [{$role->value}] cannot be scoped to a tenant — it belongs to the global team."
            );
        }

        $current = app(CurrentTenant::class);

        $tenant === null
            ? $current->runWithoutTenant(fn () => $user->assignRole($role->value))
            : $current->runAs($tenant, fn () => $user->assignRole($role->value));

        $user->unsetRelation('roles');

        // Anchor the 2FA enrolment grace window at the moment a 2FA-required
        // role is first granted (promotion-mid-life then works correctly).
        if ($role->requiresTwoFactor() && $user->two_factor_required_at === null) {
            $user->forceFill(['two_factor_required_at' => now()])->save();
        }
    }
}
