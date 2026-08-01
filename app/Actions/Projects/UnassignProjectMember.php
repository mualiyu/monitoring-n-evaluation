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

        if ($assignment->isActive()) {
            $assignment->update(['unassigned_at' => now()]);
        }

        return $assignment;
    }
}
