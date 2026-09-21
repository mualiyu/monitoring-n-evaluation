<?php

namespace App\Actions\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Turning a deviation notice into work somebody owns.
 *
 * An exception report says a project has drifted; it does not say what anyone
 * will do about it. That is an Issue, and this is the one path between them —
 * which is why `exception_reports.issue_id` is not fillable. Linking them
 * anywhere else would let two notices claim the same corrective action, or a
 * notice claim an issue from a different project.
 *
 * The new issue inherits the report's severity and carries the report as its
 * polymorphic source, so the issue's timeline starts at the moment the
 * deviation was measured rather than at the moment somebody got around to it.
 */
class RaiseIssueFromException
{
    /**
     * @param  array{
     *     title: string,
     *     description: string,
     *     category: IssueCategory,
     *     severity?: IssueSeverity|null,
     *     owner_id?: int|null,
     *     corrective_action?: string|null,
     *     due_date?: CarbonImmutable|string|null,
     * }  $attributes
     */
    public function __invoke(ExceptionReport $report, User $actor, array $attributes): Issue
    {
        Gate::forUser($actor)->authorize('create', Issue::class);
        Gate::forUser($actor)->authorize('view', $report);

        $project = $report->loadMissing('project')->project;

        return DB::transaction(function () use ($report, $project, $actor, $attributes): Issue {
            $issue = app(RaiseIssue::class)(
                $project,
                $actor,
                [
                    ...$attributes,
                    'severity' => $attributes['severity'] ?? $report->severity,
                ],
                $report,
            );

            // Not fillable: the link is this Action's to make and nobody
            // else's.
            $report->forceFill(['issue_id' => $issue->id])->save();

            return $issue;
        });
    }
}
