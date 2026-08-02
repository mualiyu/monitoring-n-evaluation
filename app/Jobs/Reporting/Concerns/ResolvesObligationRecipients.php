<?php

namespace App\Jobs\Reporting\Concerns;

use App\Models\ProjectAssignment;
use App\Models\ReportObligation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who hears about a deadline: the people actually accountable for the project —
 * its active assignees (consultant, focal officer, field monitor) plus its
 * manager. Recipients resolve from `project_assignments` + the project record,
 * exactly as the project-status notification does (design §3).
 *
 * Deliberately NOT every user in the MDA: a reminder that reaches people who
 * cannot act on it is the fastest way to teach a ministry to filter this
 * sender into a folder nobody opens.
 */
trait ResolvesObligationRecipients
{
    /**
     * @return Collection<int, User>
     */
    protected function accountableFor(ReportObligation $obligation): Collection
    {
        $project = $obligation->project;

        if ($project === null) {
            // MDA-level obligations (Phase 2 consolidation) have no assignment
            // list yet; escalation still reaches the admins.
            return new Collection;
        }

        $recipientIds = ProjectAssignment::query()
            ->where('project_id', $project->id)
            ->active()
            ->pluck('user_id')
            ->push($project->manager_id)
            ->filter()
            ->unique()
            ->all();

        if ($recipientIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $recipientIds)
            ->where('is_active', true)
            ->get();
    }
}
