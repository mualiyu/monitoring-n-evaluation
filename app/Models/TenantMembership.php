<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace membership — the HARD gate deciding who may enter a tenant
 * workspace at all (roles are the soft gate for what they may do inside).
 *
 * Deliberately NOT BelongsToTenant: membership is the gate that decides
 * tenancy, and a gate scoped by its own outcome is circular — the workspace
 * switcher and login responses must read it from an unbound context. The
 * compensating control is that all reads/writes go through the Iam actions
 * (GrantTenantAccess, RevokeTenantAccess, ListUserWorkspaces,
 * ListTenantMembers) and the EnsureTenantMembership middleware — enforced by
 * the tenancy discipline test. Never expose via route-model binding.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property MembershipStatus $status
 */
#[Fillable(['tenant_id', 'user_id', 'status', 'invited_by_id', 'joined_at', 'suspended_at'])]
class TenantMembership extends Model
{
    protected $table = 'tenant_user';

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
