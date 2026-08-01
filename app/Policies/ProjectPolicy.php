<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (see the trait), plus the one rule that is
 * specific to projects: a user whose tenant roles are project-level ONLY
 * (Consultant / FieldMonitor) sees only the projects they are actively
 * assigned to. That rule is not written here — it lives in
 * Project::scopeVisibleTo(), and `view()` asks the same query the list screens
 * ask, so a policy and a list can never disagree (design §3).
 */
class ProjectPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        if (! $this->permits($user, 'projects.view', $project)) {
            return false;
        }

        // The assignment narrowing is a WORKSPACE rule: it applies to users
        // holding only Consultant/FieldMonitor roles in the bound MDA. On the
        // oversight surface no tenant is bound, the visibility query has
        // nothing to scope itself to, and the roles it narrows on cannot be
        // held globally in the first place.
        return ! app(CurrentTenant::class)->bound() || $project->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'projects.create');
    }

    /**
     * Authority to edit — the certification freeze is a *domain* rule enforced
     * by UpdateProjectDetails, not an authorization one: a certified project
     * still accepts a new manager, and it still accepts monitoring artifacts.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.update', $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.delete', $project);
    }

    public function award(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.award', $project);
    }

    /** The generic lifecycle move: mobilized, in_progress, completed. */
    public function updateStatus(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.status.update', $project);
    }

    public function certify(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.certify', $project);
    }

    public function close(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.close', $project);
    }

    public function suspend(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.suspend', $project);
    }

    public function cancel(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.cancel', $project);
    }

    public function updateProgress(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.progress.update', $project);
    }

    public function assign(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.assign', $project);
    }

    public function publish(User $user, Project $project): bool
    {
        return $this->permits($user, 'projects.publish', $project);
    }
}
