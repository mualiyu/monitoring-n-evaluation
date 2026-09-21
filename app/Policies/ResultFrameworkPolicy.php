<?php

namespace App\Policies;

use App\Models\ResultFramework;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * The logframe is the MDA's own statement of what it is trying to achieve.
 * Consultants read it — they report against it and must be able to see what
 * their figures are for — but shaping it is M&E work, never a contractor's.
 */
class ResultFrameworkPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'frameworks.view');
    }

    public function view(User $user, ResultFramework $framework): bool
    {
        return $this->permits($user, 'frameworks.view', $framework);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'frameworks.manage');
    }

    public function update(User $user, ResultFramework $framework): bool
    {
        return $this->permits($user, 'frameworks.manage', $framework);
    }

    public function delete(User $user, ResultFramework $framework): bool
    {
        return $this->permits($user, 'frameworks.manage', $framework);
    }
}
