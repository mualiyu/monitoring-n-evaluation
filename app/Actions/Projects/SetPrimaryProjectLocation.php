<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Owns the single-primary invariant: exactly one location per project carries
 * `is_primary`. It is enforced HERE, in a transaction, and not by a unique
 * index — MySQL has no partial unique index, and unique(project_id, is_primary)
 * would wrongly cap non-primary sites at one (design §1.5).
 *
 * The headline site is what the list screen, the map pin and every export
 * show, so "which one is it" must never have two answers.
 */
class SetPrimaryProjectLocation
{
    public function __invoke(ProjectLocation $location, User $actor): ProjectLocation
    {
        $project = Project::query()->findOrFail($location->project_id);

        Gate::forUser($actor)->authorize('update', $project);

        DB::transaction(function () use ($location): void {
            ProjectLocation::query()
                ->where('project_id', $location->project_id)
                ->where('is_primary', true)
                ->whereKeyNot($location->getKey())
                ->get()
                ->each(fn (ProjectLocation $other) => $other->update(['is_primary' => false]));

            $location->update(['is_primary' => true]);
        });

        return $location;
    }
}
