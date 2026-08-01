<?php

namespace App\Policies;

use App\Models\ProjectAssignment;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * Who may put someone on a project. `projects.assign` is an MDA-staffing
 * authority (MdaAdmin, MeOfficer) — a consultant cannot add themselves to a
 * project, which is the whole point of the assignment rule that gates their
 * visibility.
 */
class ProjectAssignmentPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'projects.view');
    }

    public function view(User $user, ProjectAssignment $assignment): bool
    {
        return $this->permits($user, 'projects.view', $assignment);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'projects.assign');
    }

    public function update(User $user, ProjectAssignment $assignment): bool
    {
        return $this->permits($user, 'projects.assign', $assignment);
    }

    /** Unassignment stamps `unassigned_at`; the row itself is never deleted. */
    public function delete(User $user, ProjectAssignment $assignment): bool
    {
        return $this->permits($user, 'projects.assign', $assignment);
    }
}
