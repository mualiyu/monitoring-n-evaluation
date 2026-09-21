<?php

namespace App\Actions\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Jobs\Issues\NotifyCriticalIssueRaised;
use App\Jobs\Issues\NotifyIssueAssigned;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Putting an obstruction on the record (plan §4 — the challenges register).
 *
 * RAISING IS DELIBERATELY WIDE: `issues.create` is held by consultants and
 * field monitors as well as MDA staff, because the manual's whole premise is
 * that the person who SEES the problem records it. A register only the office
 * can write to is a register that learns about a flooded access road three
 * weeks late.
 *
 * What is narrow is everything after: updating, resolving and closing are
 * separate permissions, so the contractor who reports a problem cannot also
 * declare it solved.
 *
 * The issue always hangs off a project and OPTIONALLY off the record that
 * surfaced it — a progress report, a site inspection, an evaluation. The
 * source is polymorphic rather than three nullable foreign keys because two
 * of those three tables do not exist yet and the register must not need a
 * migration to accept a new kind of origin.
 */
class RaiseIssue
{
    /**
     * @param  array{
     *     title: string,
     *     description: string,
     *     category: IssueCategory,
     *     severity: IssueSeverity,
     *     owner_id?: int|null,
     *     corrective_action?: string|null,
     *     due_date?: CarbonImmutable|string|null,
     * }  $attributes
     * @param  Model|null  $source  the record that surfaced it, if any
     */
    public function __invoke(
        Project $project,
        User $actor,
        array $attributes,
        ?Model $source = null,
    ): Issue {
        Gate::forUser($actor)->authorize('create', Issue::class);
        // Raising against a project you may not see would let a consultant
        // address a record outside their assignments — the TenantScope stops
        // another MDA's project loading at all, and this stops the narrowing
        // inside one from being sidestepped.
        Gate::forUser($actor)->authorize('view', $project);

        $issue = new Issue([
            'project_id' => $project->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'],
            'category' => $attributes['category'],
            'severity' => $attributes['severity'],
            'owner_id' => $attributes['owner_id'] ?? null,
            'corrective_action' => $attributes['corrective_action'] ?? null,
            'due_date' => $attributes['due_date'] ?? null,
            'raised_by_id' => $actor->id,
        ]);

        // `status` is not fillable — every issue starts open, and only
        // TransitionIssueStatus moves it from there.
        $issue->status = IssueStatus::Open;
        $issue->status_changed_at = now();

        if ($source !== null) {
            $this->assertSourceIsThisProjects($source, $project);
            $issue->source()->associate($source);
        }

        DB::transaction(function () use ($issue): void {
            $issue->save();

            // The ledger opens with the raise itself: a timeline whose first
            // entry is "acknowledged" cannot say when the problem was first
            // known, which is the one date the escalation ladder runs on.
            $event = new IssueEvent([
                'issue_id' => $issue->id,
                'from_status' => null,
                'to_status' => IssueStatus::Open,
                'actor_id' => $issue->raised_by_id,
                'reason' => null,
                'occurred_at' => now(),
            ]);
            $event->tenant_id = $issue->tenant_id;
            $event->save();
        });

        // Dispatched after the write closes, never inside it.
        if ($issue->severity === IssueSeverity::Critical) {
            NotifyCriticalIssueRaised::dispatch($issue->id);
        }

        if ($issue->owner_id !== null && $issue->owner_id !== $actor->id) {
            NotifyIssueAssigned::dispatch($issue->id, $issue->owner_id, $actor->id);
        }

        return $issue;
    }

    /**
     * A source from another workspace cannot reach here — every candidate
     * model is tenant-owned and loads under its own global scope — but a
     * source belonging to a DIFFERENT PROJECT in the same MDA can, and would
     * make the register claim an inspection of road A surfaced a problem on
     * road B. Cheap to check, impossible to notice later.
     */
    private function assertSourceIsThisProjects(Model $source, Project $project): void
    {
        $sourceProjectId = $source->getAttribute('project_id');

        if ($sourceProjectId !== null && (int) $sourceProjectId !== $project->id) {
            throw new \InvalidArgumentException(
                'An issue\'s source record must belong to the same project the issue is raised against.'
            );
        }
    }
}
