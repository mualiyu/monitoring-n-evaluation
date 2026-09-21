<?php

namespace App\Actions\Workplans;

use App\Actions\Workplans\Concerns\GuardsActivityDefinitions;
use App\Jobs\Workplans\NotifyActivityAssigned;
use App\Models\User;
use App\Models\WorkplanActivity;
use Illuminate\Support\Facades\Gate;

/**
 * Edits an activity's DEFINITION — what the work is, who does it, when it is
 * scheduled, what it costs and which output indicator it delivers.
 *
 * The approval freeze is the point of this class, and it is the same shape as
 * the project certification freeze in
 * App\Actions\Projects\UpdateProjectDetails: from `approved` onward the
 * commitments an officer signed for stop moving, while the REPORTED side of
 * the row — progress, expenditure, actual dates — keeps moving through
 * RecordActivityProgress, which never consults the freeze. A plan you cannot
 * report against is worse than no plan.
 *
 * The freeze is checked against the ATTRIBUTES THAT ACTUALLY CHANGED, not the
 * request: re-submitting an unchanged form against an approved plan is a
 * no-op, not an error.
 */
class UpdateWorkplanActivity
{
    use GuardsActivityDefinitions;

    /**
     * @param  array<string, mixed>  $attributes  validated activity fields
     */
    public function __invoke(WorkplanActivity $activity, User $actor, array $attributes): WorkplanActivity
    {
        Gate::forUser($actor)->authorize('update', $activity);

        $workplan = $activity->loadMissing('workplan')->workplan;
        $previousOwner = $activity->owner_id;

        $activity->fill($attributes);

        $definitionChanges = array_intersect(
            array_keys($activity->getDirty()),
            WorkplanActivity::DEFINITION_FIELDS,
        );

        if ($definitionChanges !== []) {
            $this->assertDefinitionsAreEditable($workplan);
            $this->assertScheduleFitsPlan($workplan, [
                'planned_start' => $activity->planned_start,
                'planned_end' => $activity->planned_end,
            ], $activity->title);
            $this->assertDependencyIsSane($workplan, $activity->depends_on_id, $activity);
        }

        $activity->save();

        // A line handed to somebody else is news for the new owner — and only
        // for them; the officer who reassigned it already knows.
        if ($activity->owner_id !== null
            && $activity->owner_id !== $previousOwner
            && $activity->owner_id !== $actor->id) {
            NotifyActivityAssigned::dispatch($activity->id, $actor->id);
        }

        return $activity;
    }
}
