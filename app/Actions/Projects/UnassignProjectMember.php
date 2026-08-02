<?php

namespace App\Actions\Projects;

use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Takes someone off a project by stamping `unassigned_at` — the row stays.
 * Deleting it would erase who was accountable for the site visits in March,
 * which is exactly the question an audit asks; it would also make the
 * (project, user, role) unique key re-usable and the history unreconstructable.
 *
 * The consultant's visibility ends immediately: Project::scopeVisibleTo only
 * counts assignments with a null `unassigned_at`.
 */
class UnassignProjectMember
{
    public function __invoke(ProjectAssignment $assignment, User $actor): ProjectAssignment
    {
        Gate::forUser($actor)->authorize('delete', $assignment);

        if (! $assignment->isActive()) {
            return $assignment;
        }

        $assignment->update(['unassigned_at' => now()]);

        // The row records who put the member ON the project (`assigned_by_id`)
        // but has no column for who took them OFF, and the model's own log
        // infers its causer from the authenticated user — which is nobody in a
        // queue worker or a console run. $actor is the authority this Action
        // was handed, so it is stated explicitly rather than inferred.
        activity('project_assignments')
            ->performedOn($assignment)
            ->causedBy($actor)
            ->withProperties([
                'project_id' => $assignment->project_id,
                'user_id' => $assignment->user_id,
                'role' => $assignment->role->value,
            ])
            ->log('unassigned');

        return $assignment;
    }
}
