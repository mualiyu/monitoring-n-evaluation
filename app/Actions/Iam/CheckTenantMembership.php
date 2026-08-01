<?php

namespace App\Actions\Iam;

use App\Enums\MembershipStatus;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Does this user actively belong to this workspace? The membership gate table
 * cannot use BelongsToTenant (it decides tenancy, so scoping it by its own
 * outcome would be circular), which is why every read of it lives in
 * app/Actions/Iam — the sanctioned home for the explicit tenant_id filter.
 *
 * Callers outside Iam (AssignProjectMember, for one) ask through this action
 * rather than querying the gate themselves.
 */
class CheckTenantMembership
{
    public function __invoke(User $user, ?Tenant $tenant = null): bool
    {
        $tenantId = $tenant->id ?? app(CurrentTenant::class)->idOrFail();

        return TenantMembership::query()
            ->where('tenant_id', $tenantId) // sanctioned: gate table, unscoped by design
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }
}
