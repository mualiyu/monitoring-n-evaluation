<?php

namespace App\Actions\Workplans;

use App\Actions\Workplans\Concerns\GuardsActivityDefinitions;
use App\Enums\ActivityStatus;
use App\Jobs\Workplans\NotifyActivityAssigned;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds one line to a work plan — the manual's `no | activity | owner |
 * month × week` row, plus its budget line and its output indicator.
 *
 * The indicator link is optional here ON PURPOSE (see the migration): the
 * manual's rule that every activity carries an output indicator is surfaced as
 * a countable warning on every screen, not as a save-time block that would be
 * satisfied by attaching any indicator at all. An instance that wants it
 * enforced flips `workplans.require_output_indicator`, and the block then
 * lands at submission, where a human is looking at the whole plan.
 *
 * `position` is assigned here rather than sent by the form: a client-supplied
 * ordinal in a table two people are editing is a collision waiting to happen.
 */
class AddWorkplanActivity
{
    use GuardsActivityDefinitions;

    /**
     * @param  array<string, mixed>  $attributes  validated activity fields
     */
    public function __invoke(Workplan $workplan, User $actor, array $attributes): WorkplanActivity
    {
        Gate::forUser($actor)->authorize('update', $workplan);

        $this->assertDefinitionsAreEditable($workplan);
        $this->assertScheduleFitsPlan($workplan, $attributes, (string) $attributes['title']);
        $this->assertDependencyIsSane($workplan, $this->intOrNull($attributes['depends_on_id'] ?? null));

        $activity = DB::transaction(function () use ($workplan, $actor, $attributes): WorkplanActivity {
            // Under a lock, so two officers adding a row at once get two
            // ordinals rather than one. max()+1 over the plan's own rows —
            // the TenantScope has already confined the query to this MDA.
            $next = (int) WorkplanActivity::query()
                ->lockForUpdate()
                ->where('workplan_id', $workplan->id)
                ->max('position') + 1;

            $activity = new WorkplanActivity([
                ...$attributes,
                'workplan_id' => $workplan->id,
                'position' => $next,
                'created_by_id' => $actor->id,
            ]);

            // Not fillable — the reported side of the row starts empty and
            // only RecordActivityProgress ever moves it.
            $activity->forceFill([
                'status' => ActivityStatus::NotStarted,
                'progress_percent' => 0,
                'expenditure_to_date' => 0,
            ])->save();

            return $activity;
        });

        // After the commit: the owner must not be told about a row that was
        // rolled back. Silent when nobody was named, and silent when the
        // author gave the line to themselves.
        if ($activity->owner_id !== null && $activity->owner_id !== $actor->id) {
            NotifyActivityAssigned::dispatch($activity->id, $actor->id);
        }

        return $activity;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
