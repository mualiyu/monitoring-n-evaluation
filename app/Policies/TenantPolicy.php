<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * Authority over the workspace register.
 *
 * This is the one domain model where ChecksTenantAuthority does not apply, and
 * saying so explicitly matters: a Tenant is not a record INSIDE a workspace,
 * it IS the workspace, so "does this record belong to the bound tenant" is the
 * wrong question. Authority is therefore read only from the GLOBAL permission
 * team — an MDA admin holds `tenants.manage` nowhere, and would not become
 * able to provision or suspend workspaces even if a future route put this
 * policy in reach from a tenant subdomain.
 *
 * There is no delete(). Workspaces are suspended (SetTenantActive), never
 * removed: the projects, returns and evidence under a merged or investigated
 * agency are government records, and a screen that can delete forty ministries
 * of history is a screen that should not exist.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsGlobalPermission('tenants.view');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        unset($tenant);

        return $user->holdsGlobalPermission('tenants.view');
    }

    public function create(User $user): bool
    {
        return $user->holdsGlobalPermission('tenants.manage');
    }

    public function update(User $user, Tenant $tenant): bool
    {
        unset($tenant);

        return $user->holdsGlobalPermission('tenants.manage');
    }

    /** Opening or closing a subdomain — reversible, and it deletes nothing. */
    public function setActive(User $user, Tenant $tenant): bool
    {
        unset($tenant);

        return $user->holdsGlobalPermission('tenants.manage');
    }
}
