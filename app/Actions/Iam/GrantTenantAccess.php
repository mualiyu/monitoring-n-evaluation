<?php

namespace App\Actions\Iam;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY writer that grants workspace access: membership (hard gate) and
 * role (soft gate) in one transaction, so they can never drift apart on the
 * grant path. Mirror: RevokeTenantAccess.
 */
class GrantTenantAccess
{
    public function __invoke(User $user, Tenant $tenant, Role $role, ?User $invitedBy = null): TenantMembership
    {
        if (! in_array($role, Role::tenantRoles(), true)) {
            throw new InvalidArgumentException(
                "Only tenant roles can be granted with workspace access; [{$role->value}] is an oversight role."
            );
        }

        return DB::transaction(function () use ($user, $tenant, $role, $invitedBy) {
            $membership = TenantMembership::query()
                ->where('tenant_id', $tenant->id) // sanctioned: gate table, unscoped by design
                ->where('user_id', $user->id)
                ->first();

            if ($membership === null) {
                $membership = TenantMembership::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'status' => MembershipStatus::Active,
                    'invited_by_id' => $invitedBy?->id,
                    'joined_at' => now(),
                ]);
            } else {
                $membership->update([
                    'status' => MembershipStatus::Active,
                    'suspended_at' => null,
                ]);
            }

            (new AssignRole)($user, $role, $tenant);

            return $membership;
        });
    }
}
