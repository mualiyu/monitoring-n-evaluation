<?php

namespace App\Actions\Projects;

use App\Actions\Iam\CheckTenantMembership;
use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Puts someone on a project. Two gates on the *target*, both required (design
 * §3):
 *
 *  1. an ACTIVE membership of this workspace — assignment must never become a
 *     side door into an MDA the person was never admitted to (and it is the
 *     reason a supervising agency's staff still cannot reach the project it
 *     supervises: §9.1);
 *  2. a Consultant, FieldMonitor or MeOfficer role HERE — project assignments
 *     are the accountability list for delivery, not a viewer list.
 *
 * Re-assignment reactivates the existing row rather than inserting a second
 * one: the (project, user, role) unique key stays meaningful and the history
 * is never rewritten.
 */
class AssignProjectMember
{
    /** Platform roles that may hold a project assignment. */
    private const ELIGIBLE_ROLES = [Role::Consultant, Role::FieldMonitor, Role::MeOfficer];

    public function __invoke(Project $project, User $member, ProjectRole $role, User $actor): ProjectAssignment
    {
        Gate::forUser($actor)->authorize('assign', $project);

        if (! (new CheckTenantMembership)($member)) {
            throw ProjectRuleViolation::assigneeNotAMember();
        }

        if (! $this->holdsAnEligibleRole($member)) {
            throw ProjectRuleViolation::assigneeLacksProjectRole();
        }

        $assignment = ProjectAssignment::query()
            ->where('project_id', $project->id)
            ->where('user_id', $member->id)
            ->where('role', $role)
            ->first();

        if ($assignment === null) {
            return ProjectAssignment::create([
                'project_id' => $project->id,
                'user_id' => $member->id,
                'role' => $role,
                'assigned_by_id' => $actor->id,
                'assigned_at' => now(),
            ]);
        }

        if ($assignment->isActive()) {
            throw ProjectRuleViolation::assignmentAlreadyActive();
        }

        $assignment->update([
            'assigned_by_id' => $actor->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        return $assignment;
    }

    /**
     * Roles are team-scoped, so this asks about the CURRENT workspace only —
     * a consultant role in another MDA grants nothing here. The cached
     * relation is dropped first because spatie bakes the team id into it at
     * load time, and the caller's $member may have been read elsewhere.
     */
    private function holdsAnEligibleRole(User $member): bool
    {
        $member->unsetRelation('roles')->unsetRelation('permissions');

        return $member->hasAnyRole(
            array_map(fn (Role $role): string => $role->value, self::ELIGIBLE_ROLES),
        );
    }
}
