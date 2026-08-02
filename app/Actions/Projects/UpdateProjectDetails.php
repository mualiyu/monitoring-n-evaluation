<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\ChecksProjectManager;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Edits the project record and enforces the certification freeze (design
 * §2.3) — the half of that finding people get wrong.
 *
 * The freeze is on FIELDS, not on the record: from `certified` onward the
 * scope, money and dates a completion certificate attests to stop moving, so
 * they cannot be quietly rewritten after the fact. Everything else still
 * changes (a new manager, a different reporting frequency), and attaching
 * monitoring artifacts — post-completion inspections, documents, readings — is
 * never blocked by this or any other Action. Corrections to frozen figures go
 * through the Phase 2 amendment register, not an unlocked edit form.
 */
class UpdateProjectDetails
{
    use ChecksProjectManager;

    /**
     * @param  array<string, mixed>  $attributes  validated project fields
     */
    public function __invoke(Project $project, User $actor, array $attributes): Project
    {
        Gate::forUser($actor)->authorize('update', $project);

        // Manager stays editable after certification (§2.3 freezes scope, money
        // and dates — not the officer accountable), so this guard has to run on
        // the edit path too. Asked against the PROJECT's tenant rather than
        // whatever happens to be bound, so it answers the same on any surface.
        $this->assertManagerIsAMember($attributes, $project->tenant);

        $project->fill($attributes);

        if ($project->isFrozen()) {
            $frozenChanges = array_intersect(
                array_keys($project->getDirty()),
                Project::FROZEN_FIELDS,
            );

            if ($frozenChanges !== []) {
                throw ProjectRuleViolation::frozenRecord($project->status);
            }
        }

        $project->save();

        return $project;
    }
}
