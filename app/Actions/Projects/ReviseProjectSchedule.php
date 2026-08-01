<?php

namespace App\Actions\Projects;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Moves the completion date and says why. `expected_end_date` is the planned
 * date and never changes — schedule performance is measured against it, and an
 * overwritten baseline is how a two-year overrun becomes "on schedule".
 *
 * Design open question 3 (whose answer this Action assumes): an approved
 * extension without a contract variation is a real case — a delayed
 * right-of-way, a rainy-season stoppage — so the project date is set here
 * directly rather than derived from the latest variation. When a variation
 * carries its own completion date, this is the Action that reflects it on the
 * project.
 */
class ReviseProjectSchedule
{
    public function __invoke(Project $project, User $actor, CarbonInterface $revisedEndDate, string $reason): Project
    {
        Gate::forUser($actor)->authorize('update', $project);

        if ($project->isFrozen()) {
            throw ProjectRuleViolation::frozenRecord($project->status);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ProjectRuleViolation::reasonRequired($project->status);
        }

        $previous = $project->revised_end_date ?? $project->expected_end_date;

        $project->fill(['revised_end_date' => $revisedEndDate])->save();

        // The date lives on the row; WHY it moved lives in the audit trail,
        // which is the question an auditor actually asks about a slipped date.
        activity('projects')
            ->performedOn($project)
            ->causedBy($actor)
            ->withProperties([
                'from' => $previous?->toDateString(),
                'to' => $revisedEndDate->toDateString(),
                'reason' => $reason,
            ])
            ->log('schedule_revised');

        return $project;
    }
}
