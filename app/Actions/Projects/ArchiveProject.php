<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Soft-deletes a project that never became one — a duplicate entry, a
 * mis-keyed draft, a cancelled proposal. Nothing that reached award can be
 * archived: delivered public works are government records, and the lifecycle
 * already has the right ending for them (`closed`).
 *
 * Soft delete, never a hard one: every child table restricts on delete
 * (migration review §5), so a purge is a deliberate, ordered act — not
 * something an archive button does by accident.
 */
class ArchiveProject
{
    public function __invoke(Project $project, User $actor): Project
    {
        Gate::forUser($actor)->authorize('delete', $project);

        if (! in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Cancelled], true)) {
            throw ProjectRuleViolation::archiveRequiresDraftOrCancelled($project->status);
        }

        $project->delete();

        return $project;
    }
}
