<?php

namespace App\Actions\Projects;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a site (soft delete — Phase 2 inspections will point at these rows,
 * and an inspection whose site vanished is an orphaned record).
 *
 * A project always keeps at least one site: "where is this being built" is not
 * an optional question about public works. If the removed site was the primary
 * one, the invariant is restored by promoting another — never left with none,
 * which would break every list screen that reads the headline site.
 */
class RemoveProjectLocation
{
    public function __invoke(ProjectLocation $location, User $actor): void
    {
        $project = Project::query()->findOrFail($location->project_id);

        Gate::forUser($actor)->authorize('update', $project);

        $siblings = ProjectLocation::query()
            ->where('project_id', $location->project_id)
            ->whereKeyNot($location->getKey())
            ->get();

        if ($siblings->isEmpty()) {
            throw ProjectRuleViolation::lastLocationRemoval();
        }

        DB::transaction(function () use ($location, $siblings, $actor): void {
            $wasPrimary = $location->is_primary;

            $location->delete();

            if ($wasPrimary) {
                (new SetPrimaryProjectLocation)($siblings->first(), $actor);
            }
        });
    }
}
