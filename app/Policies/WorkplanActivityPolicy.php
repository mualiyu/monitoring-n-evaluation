<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkplanActivity;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * Permission AND tenant match, plus the one rule specific to activities:
 *
 * THE OFFICER ACCOUNTABLE FOR A LINE MAY REPORT ON IT. `workplans.manage` is
 * an M&E-unit permission, but the person who actually knows whether the
 * borehole was drilled is the activity's owner — often a consultant or field
 * monitor who holds only `workplans.view`. Requiring the M&E officer to
 * re-type every owner's figure is how a plan stops being updated by March.
 *
 * The narrowing is tight on purpose: the owner may record PROGRESS, never
 * edit the definition, never add or remove a line, and never on somebody
 * else's activity.
 */
class WorkplanActivityPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'workplans.view');
    }

    public function view(User $user, WorkplanActivity $activity): bool
    {
        return $this->permits($user, 'workplans.view', $activity);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'workplans.manage');
    }

    public function update(User $user, WorkplanActivity $activity): bool
    {
        return $this->permits($user, 'workplans.manage', $activity);
    }

    public function delete(User $user, WorkplanActivity $activity): bool
    {
        return $this->permits($user, 'workplans.manage', $activity);
    }

    public function recordProgress(User $user, WorkplanActivity $activity): bool
    {
        if ($this->permits($user, 'workplans.manage', $activity)) {
            return true;
        }

        return $activity->owner_id === $user->id
            && $this->permits($user, 'workplans.view', $activity);
    }
}
