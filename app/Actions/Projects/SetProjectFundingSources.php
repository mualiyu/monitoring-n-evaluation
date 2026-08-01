<?php

namespace App\Actions\Projects;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\ProjectFundingSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Replaces a project's funding split. Donor + counterpart is the normal case,
 * not the exception, which is why funding is a pivot and not a scalar column
 * (design §1.6).
 *
 * Percentages may total less than 100 (a partially-identified funding plan is
 * honest) but never more — over-100% funding is a data-entry error that would
 * misattribute donor money in every report downstream.
 *
 * Rows are hard-deleted (migration review §10): the unique
 * (project_id, funding_source_id) key plus a tombstone would block re-adding a
 * source removed last month. The removed split survives in the activity log.
 */
class SetProjectFundingSources
{
    /**
     * @param  list<array<string, mixed>>  $sources  funding_source_id + amount/percentage/is_primary
     * @return Collection<int, ProjectFundingSource>
     */
    public function __invoke(Project $project, User $actor, array $sources): Collection
    {
        Gate::forUser($actor)->authorize('update', $project);

        $totalPercent = 0;

        foreach ($sources as $source) {
            // decimal(5,2) — basis points keep the total off floating point.
            $totalPercent += (int) round(((float) ($source['percentage'] ?? 0)) * 100);
        }

        if ($totalPercent > 10_000) {
            throw ProjectRuleViolation::fundingSplitExceedsWhole(number_format($totalPercent / 100, 2));
        }

        return DB::transaction(function () use ($project, $sources): Collection {
            ProjectFundingSource::query()
                ->where('project_id', $project->id)
                ->get()
                ->each(fn (ProjectFundingSource $row) => $row->delete());

            /** @var Collection<int, ProjectFundingSource> $rows */
            $rows = new Collection;

            foreach ($sources as $source) {
                $rows->push(ProjectFundingSource::create([
                    ...$source,
                    'project_id' => $project->id,
                ]));
            }

            return $rows;
        });
    }
}
