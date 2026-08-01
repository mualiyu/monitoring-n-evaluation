<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds a site to a project. "12 PHCs across 4 LGAs" is one project with twelve
 * of these rows, and Phase 2 inspections are per-site.
 *
 * The first site of a project is always primary; a later one only becomes
 * primary through SetPrimaryProjectLocation, which owns the single-primary
 * invariant.
 */
class AddProjectLocation
{
    /**
     * @param  array<string, mixed>  $attributes  site name, lga/ward, coordinates
     */
    public function __invoke(Project $project, User $actor, array $attributes): ProjectLocation
    {
        Gate::forUser($actor)->authorize('update', $project);

        return DB::transaction(function () use ($project, $actor, $attributes): ProjectLocation {
            $isFirst = ! ProjectLocation::query()->where('project_id', $project->id)->exists();

            $location = ProjectLocation::create([
                ...$attributes,
                'project_id' => $project->id,
                'is_primary' => false,
            ]);

            if ($isFirst || ($attributes['is_primary'] ?? false)) {
                (new SetPrimaryProjectLocation)($location, $actor);
            }

            return $location;
        });
    }
}
