<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * Contracts are the tenant-owned side of the global contractor registry: every
 * MDA sees every firm, no MDA sees another's engagements. The tenant check in
 * `permits()` is what draws that line.
 */
class ContractPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'contracts.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $this->permits($user, 'contracts.view', $contract);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'contracts.create');
    }

    /**
     * Authority to edit the mutable fields (status, dates, retention). The
     * award terms — sum, award date, contractor, scope — are immutable at the
     * model, whatever this returns.
     */
    public function update(User $user, Contract $contract): bool
    {
        return $this->permits($user, 'contracts.update', $contract);
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $this->permits($user, 'contracts.delete', $contract);
    }
}
