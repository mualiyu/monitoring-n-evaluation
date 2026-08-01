<?php

namespace App\Actions\Iam;

use App\Models\TenantMembership;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;

/**
 * Members of the CURRENT tenant only. The explicit tenant_id filter is the
 * sanctioned exception for the gate table (TenantMembership cannot use
 * BelongsToTenant — see its docblock); idOrFail() keeps it fail-closed.
 */
class ListTenantMembers
{
    /** @return Collection<int, TenantMembership> */
    public function __invoke(): Collection
    {
        return TenantMembership::query()
            ->where('tenant_id', app(CurrentTenant::class)->idOrFail()) // sanctioned: gate table
            ->with('user')
            ->get();
    }
}
