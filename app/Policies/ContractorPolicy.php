<?php

namespace App\Policies;

use App\Models\Contractor;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * The vendor registry is GLOBAL — there is no tenant to match, and that is
 * deliberate (see Contractor's docblock). Authority splits in two:
 *
 *  - **adding** a firm is a workspace act: any tenant user with
 *    `contractors.create` may register one, deduped on rc_number;
 *  - **editing or blacklisting** is a state act, so the permission is read
 *    from the GLOBAL team. One MDA must not rewrite a vendor record another
 *    MDA's contracts depend on, and a blacklisting decides who may win public
 *    work state-wide.
 */
class ContractorPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        // No record argument anywhere in this policy: the registry is global,
        // so there is no tenant to match — only authority to weigh.
        return $this->permits($user, 'contractors.view');
    }

    public function view(User $user, Contractor $contractor): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'contractors.create');
    }

    public function update(User $user, Contractor $contractor): bool
    {
        // Explicitly the GLOBAL team, not permits(): editing or barring a firm
        // is state authority even when the request arrives on an MDA
        // subdomain, and `contractors.manage` is seeded to oversight roles
        // only — reading it from the workspace team would answer "no" for the
        // very people who hold it.
        return $user->holdsGlobalPermission('contractors.manage');
    }

    public function blacklist(User $user, Contractor $contractor): bool
    {
        // Explicitly the GLOBAL team, not permits(): editing or barring a firm
        // is state authority even when the request arrives on an MDA
        // subdomain, and `contractors.manage` is seeded to oversight roles
        // only — reading it from the workspace team would answer "no" for the
        // very people who hold it.
        return $user->holdsGlobalPermission('contractors.manage');
    }

    public function delete(User $user, Contractor $contractor): bool
    {
        // Explicitly the GLOBAL team, not permits(): editing or barring a firm
        // is state authority even when the request arrives on an MDA
        // subdomain, and `contractors.manage` is seeded to oversight roles
        // only — reading it from the workspace team would answer "no" for the
        // very people who hold it.
        return $user->holdsGlobalPermission('contractors.manage');
    }
}
