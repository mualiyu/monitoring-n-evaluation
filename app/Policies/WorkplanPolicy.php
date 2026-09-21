<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workplan;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (see the trait), over the three seeded
 * permissions: `workplans.view`, `workplans.manage`, `workplans.approve`.
 *
 * The split is the separation of duties the module exists to enforce. The M&E
 * unit holds `manage` — it drafts the plan, maintains its activities and
 * submits it. Only the accounting officer holds `approve`, and the approval
 * chokepoint additionally refuses an approver who is the submitter, so holding
 * both permissions is still not enough to sign your own plan.
 *
 * As with ProjectPolicy, the visibility narrowing for field roles is not
 * written here — it lives in Workplan::scopeVisibleTo(), and view() asks the
 * same query the list screens ask, so a policy and a list can never disagree.
 */
class WorkplanPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'workplans.view');
    }

    public function view(User $user, Workplan $workplan): bool
    {
        if (! $this->permits($user, 'workplans.view', $workplan)) {
            return false;
        }

        // The narrowing is a WORKSPACE rule: it applies to users holding only
        // Consultant/FieldMonitor roles in the bound MDA. On the oversight
        // surface no tenant is bound, the visibility query has nothing to
        // scope itself to, and the roles it narrows on cannot be held globally.
        return ! app(CurrentTenant::class)->bound() || $workplan->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'workplans.manage');
    }

    /**
     * Authority to edit the plan and its activities. The approval freeze is a
     * DOMAIN rule enforced by the Actions, not an authorization one — an
     * approved plan still accepts progress from the same people.
     */
    public function update(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.manage', $workplan);
    }

    public function delete(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.manage', $workplan);
    }

    /** Sending the plan up the chain is the M&E unit's act. */
    public function submit(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.manage', $workplan);
    }

    public function approve(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.approve', $workplan);
    }

    public function reject(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.approve', $workplan);
    }

    /** Declaring the signed plan live is the same signature as approving it. */
    public function activate(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.approve', $workplan);
    }

    public function close(User $user, Workplan $workplan): bool
    {
        return $this->permits($user, 'workplans.approve', $workplan);
    }
}
