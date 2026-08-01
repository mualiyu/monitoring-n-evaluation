<?php

namespace App\Actions\Projects;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The publishing gate (security rules: the public portal serves published data
 * only). Phase 1 has no portal route reading `published_at` — the column and
 * the authority to flip it exist now so Phase 3 does not backfill a
 * transparency decision onto historical records.
 *
 * `published_at` / `published_by_id` are not fillable: publishing is an
 * explicit act by an MdaAdmin or state administrator, never something a form
 * payload can turn on.
 */
class PublishProject
{
    public function __invoke(Project $project, User $actor): Project
    {
        Gate::forUser($actor)->authorize('publish', $project);

        if ($project->published_at !== null) {
            throw ProjectRuleViolation::alreadyPublished();
        }

        $project->forceFill([
            'published_at' => now(),
            'published_by_id' => $actor->id,
        ])->save();

        return $project;
    }
}
