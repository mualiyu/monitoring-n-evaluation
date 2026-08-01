<?php

namespace App\Actions\Iam;

use App\Enums\MembershipStatus;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * All workspaces a user may enter — unscoped BY DESIGN (this powers the
 * workspace switcher and login responses, which run before/outside any
 * tenant context). Returns active memberships with their tenants.
 */
class ListUserWorkspaces
{
    /** @return Collection<int, TenantMembership> */
    public function __invoke(User $user): Collection
    {
        return TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->with('tenant')
            ->get()
            ->filter(fn (TenantMembership $m) => $m->tenant?->is_active === true)
            ->values();
    }
}
