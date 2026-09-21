<?php

namespace App\Actions\Indicators;

use App\Enums\FrameworkLevel;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Project;
use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Adds one result statement to a logframe (manual digest §2).
 *
 * The nesting rules are enforced here rather than by the screen, because the
 * screen is one caller among several (the builder, the demo seeder, a future
 * import) and a logframe whose levels do not nest is not a logframe — it is a
 * list of sentences at three indentations.
 *
 * `project_id` null means the MDA's PROGRAMME framework: the manual's results
 * chain exists above the level of any single contract, and an MDA that can
 * only express results per project can never report a sector outcome.
 */
class CreateResultFramework
{
    public function __invoke(
        FrameworkLevel $level,
        string $statement,
        User $actor,
        ?Project $project = null,
        ?ResultFramework $parent = null,
        ?string $code = null,
        ?string $description = null,
        ?string $assumptions = null,
    ): ResultFramework {
        Gate::forUser($actor)->authorize('create', ResultFramework::class);

        $this->assertNesting($level, $parent, $project);

        return ResultFramework::query()->create([
            'project_id' => $parent->project_id ?? $project?->id,
            'parent_id' => $parent?->id,
            'level' => $level,
            'code' => $code,
            'statement' => $statement,
            'description' => $description,
            'assumptions' => $assumptions,
            // Appended to the end of its branch; the builder reorders by
            // writing sort_order directly through UpdateResultFramework.
            'sort_order' => $this->nextSortOrder($parent, $project),
            'created_by_id' => $actor->id,
        ]);
    }

    private function assertNesting(FrameworkLevel $level, ?ResultFramework $parent, ?Project $project): void
    {
        if ($parent === null) {
            if ($level !== FrameworkLevel::Impact) {
                throw IndicatorRuleViolation::rootMustBeImpact($level);
            }

            return;
        }

        if (! $parent->level->accepts($level)) {
            throw IndicatorRuleViolation::levelCannotNest($parent->level, $level);
        }

        // A statement grafted onto a parent in another framework would appear
        // in one project's tree and count towards another's roll-up.
        if ($project !== null && $parent->project_id !== $project->id) {
            throw IndicatorRuleViolation::parentInAnotherFramework();
        }
    }

    private function nextSortOrder(?ResultFramework $parent, ?Project $project): int
    {
        $query = $parent !== null
            ? ResultFramework::query()->where('parent_id', $parent->id)
            : ResultFramework::query()->whereNull('parent_id')->forProject($project);

        return (int) $query->max('sort_order') + 10;
    }
}
