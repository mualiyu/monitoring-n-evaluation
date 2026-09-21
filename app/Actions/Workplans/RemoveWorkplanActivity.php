<?php

namespace App\Actions\Workplans;

use App\Actions\Workplans\Concerns\GuardsActivityDefinitions;
use App\Exceptions\Workplans\WorkplanRuleViolation;
use App\Models\User;
use App\Models\WorkplanActivity;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a line from a plan that is still being drafted.
 *
 * SOFT DELETE, always — government records are not erased, and the activity
 * log keeps who removed what. The freeze applies: once a plan is approved its
 * lines stay in it, and work that is no longer being done is CANCELLED
 * (ActivityStatus::Cancelled, which drops out of the roll-up) rather than
 * deleted, so the record still shows what was committed to and what became
 * of it.
 *
 * A predecessor cannot be removed while other lines wait on it: nullOnDelete
 * would silently orphan the dependency and the Gantt would draw an arrow from
 * nowhere. The officer repoints or removes the dependents first.
 */
class RemoveWorkplanActivity
{
    use GuardsActivityDefinitions;

    public function __invoke(WorkplanActivity $activity, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $activity);

        $this->assertDefinitionsAreEditable($activity->loadMissing('workplan')->workplan);

        $dependents = WorkplanActivity::query()->where('depends_on_id', $activity->id)->count();

        if ($dependents > 0) {
            throw WorkplanRuleViolation::dependentsRemain($dependents);
        }

        $activity->delete();
    }
}
