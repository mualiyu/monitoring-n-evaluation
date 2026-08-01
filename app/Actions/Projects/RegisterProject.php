<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Registers a project in the CURRENT workspace — the wizard's three steps in
 * one transaction: identity & scope, funding, and the primary site.
 *
 * `tenant_id` is never passed: BelongsToTenant fills it from the bound tenant,
 * and passing a foreign one throws. Status is never passed either — a project
 * is born `draft` and only TransitionProjectStatus moves it, so the ledger
 * starts with a creation row (from_status null) and stays complete from the
 * first day of the project's life.
 */
class RegisterProject
{
    /**
     * @param  array<string, mixed>  $attributes  validated project fields
     * @param  array<string, mixed>  $primaryLocation  the single site every project starts with
     * @param  list<array<string, mixed>>  $fundingSources  funding_source_id + amount/percentage rows
     */
    public function __invoke(
        User $actor,
        array $attributes,
        array $primaryLocation = [],
        array $fundingSources = [],
    ): Project {
        Gate::forUser($actor)->authorize('create', Project::class);

        return DB::transaction(function () use ($actor, $attributes, $primaryLocation, $fundingSources): Project {
            $project = Project::create([
                ...$attributes,
                'created_by_id' => $actor->id,
            ]);

            if ($primaryLocation !== []) {
                ProjectLocation::create([
                    ...$primaryLocation,
                    'project_id' => $project->id,
                    'is_primary' => true,
                ]);
            }

            if ($fundingSources !== []) {
                // One writer for funding splits, so the ≤ 100% rule cannot be
                // enforced in one path and skipped in the other.
                (new SetProjectFundingSources)($project, $actor, $fundingSources);
            }

            ProjectStatusEvent::create([
                'project_id' => $project->id,
                'from_status' => null, // creation has no origin
                'to_status' => ProjectStatus::Draft,
                'actor_id' => $actor->id,
                'occurred_at' => now(),
            ]);

            // The lifecycle columns are database defaults (draft, zero
            // progress, zero expenditure) because their Actions own them —
            // so the freshly created instance has to read them back rather
            // than hand the caller a model whose status is null.
            return $project->refresh();
        });
    }
}
