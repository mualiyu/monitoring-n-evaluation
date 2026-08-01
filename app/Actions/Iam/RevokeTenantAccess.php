<?php

namespace App\Actions\Iam;

use App\Enums\MembershipStatus;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Suspends a user's workspace access and removes their tenant roles in one
 * transaction. The membership row is never deleted — it is the audit record
 * that access once existed.
 */
class RevokeTenantAccess
{
    public function __invoke(User $user, Tenant $tenant): void
    {
        DB::transaction(function () use ($user, $tenant): void {
            TenantMembership::query()
                ->where('tenant_id', $tenant->id) // sanctioned: gate table, unscoped by design
                ->where('user_id', $user->id)
                ->update([
                    'status' => MembershipStatus::Suspended,
                    'suspended_at' => now(),
                ]);

            app(CurrentTenant::class)->runAs($tenant, function () use ($user): void {
                $user->unsetRelation('roles'); // drop roles cached under another team context

                foreach ($user->roles as $role) {
                    $user->removeRole($role);
                }
                $user->unsetRelation('roles');
            });
        });
    }
}
