<?php

namespace App\Actions\Iam;

use App\Models\Invitation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pending (live) invitations — the reader behind both invitation panels.
 * Invitation is deliberately NOT BelongsToTenant (nullable tenant_id,
 * pre-tenancy — see the model docblock), so its reads are confined to the Iam
 * actions; this is the sanctioned home for the explicit tenant filter.
 *
 * With a tenant: that workspace's pending invitations (the /team panel).
 * Without one: every pending invitation platform-wide — oversight is the
 * surface that provisions across the whole instance, and its panel must show
 * the MdaAdmin invitations it issued INTO workspaces alongside the
 * oversight-role ones (tenant_id null).
 */
class ListPendingInvitations
{
    /** @return Collection<int, Invitation> */
    public function __invoke(?Tenant $tenant = null): Collection
    {
        return Invitation::query()
            ->when(
                $tenant !== null,
                fn (Builder $query) => $query->where('tenant_id', $tenant->id), // sanctioned: pre-tenancy table
            )
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with(['invitedBy:id,name', 'tenant:id,name,slug'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
