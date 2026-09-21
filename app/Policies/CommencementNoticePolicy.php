<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\CommencementNotice;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (see the trait), plus the project-visibility
 * narrowing every other list in this platform applies: a consultant or field
 * monitor sees the notices of the projects they are actually assigned to.
 *
 * That narrowing is asked as a QUERY, never as `$notice->project` — lazy
 * loading is prevented outside production and a policy is handed models from
 * route bindings, relations and oversight reads alike, with no guarantee about
 * what is eager-loaded.
 */
class CommencementNoticePolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'commencement.view');
    }

    public function view(User $user, CommencementNotice $notice): bool
    {
        if (! $this->permits($user, 'commencement.view', $notice)) {
            return false;
        }

        // On the oversight surface no tenant is bound, the visibility query
        // has nothing to scope itself to, and the project-level roles it
        // narrows on cannot be held globally in the first place.
        return ! app(CurrentTenant::class)->bound() || $this->projectIsVisible($user, $notice->project_id);
    }

    /**
     * Serving a notice is a supervising-agency act (`commencement.issue`:
     * SuperAdmin + MDA staff). The project is passed rather than a notice
     * because the row often does not exist yet — the award creates the duty,
     * the Action creates the record.
     */
    public function issue(User $user, ?Project $project = null): bool
    {
        return $this->permits($user, 'commencement.issue', $project);
    }

    /**
     * Confirming receipt. Two parties may legitimately record it and no third:
     * the MDA staff who serve notices (recording a signed hard copy that came
     * back), and the contractor's own people on the project — the consultants
     * actively assigned to it, who are the ones the notice was addressed to.
     */
    public function acknowledge(User $user, CommencementNotice $notice): bool
    {
        if (! $this->permits($user, 'commencement.view', $notice)) {
            return false;
        }

        if ($this->permits($user, 'commencement.issue', $notice)) {
            return true;
        }

        return ProjectAssignment::query()
            ->where('project_id', $notice->project_id)
            ->where('user_id', $user->id)
            ->where('role', ProjectRole::Consultant)
            ->active()
            ->exists();
    }

    private function projectIsVisible(User $user, int $projectId): bool
    {
        return Project::query()->visibleTo($user)->whereKey($projectId)->exists();
    }
}
