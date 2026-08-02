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
    public function __invoke(User $user, Tenant $tenant, ?User $actor = null, ?string $reason = null): void
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

                // roles()->get(), not $user->roles: the relation must be read
                // HERE, under this tenant's team — and an explicit query is
                // exempt from preventLazyLoading, which would otherwise trip
                // on any user instance that came out of the database rather
                // than a factory.
                foreach ($user->roles()->get() as $role) {
                    $user->removeRole($role);
                }
                $user->unsetRelation('roles');
            });
        });

        // The membership row records THAT access ended; this line records who
        // ended it and why — the half of the story a hearing asks for. Actor
        // and reason are optional so system/console revocations still log.
        activity()
            ->causedBy($actor)
            ->performedOn($user)
            ->withProperties(array_filter([
                'tenant_id' => $tenant->id,
                'tenant' => $tenant->slug,
                'reason' => $reason,
            ]))
            ->log('tenant_access.revoked');
    }
}
