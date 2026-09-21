<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Jobs\Inspections\NotifyInspectionScheduled;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Puts a site visit in the diary (plan §4, Nasarawa BPP steps 2–6).
 *
 * Two callers, one path: an M&E officer scheduling by hand, and
 * ProposeRoutineInspections proposing on the configured interval. The second
 * passes a `$scheduleKey`, which is the engine's idempotency handle — a unique
 * index on (tenant, project, schedule_key) makes a double cron run a no-op at
 * the database rather than at the sweep's good behaviour. Manual visits pass
 * null and never collide, because SQL treats NULLs as distinct.
 *
 * The creation event is written here rather than in the chokepoint: a
 * scheduled inspection has not transitioned anywhere yet, and the ledger must
 * still start with a row saying who put it in the diary — the same shape as
 * ProjectStatusEvent's creation row, with a null `from_status`.
 */
class ScheduleInspection
{
    public function __invoke(
        Project $project,
        InspectionType $type,
        CarbonImmutable $scheduledDate,
        User $leadInspector,
        User $actor,
        ?InspectionChecklistTemplate $template = null,
        ?ProjectLocation $location = null,
        ?string $team = null,
        ?string $objectives = null,
        ?string $scheduleKey = null,
        bool $system = false,
    ): SiteInspection {
        // Authorization is asked of the HUMAN path only. The scheduling engine
        // has no user: ProposeRoutineInspections attributes its proposal to
        // the inspector it assigns it to — so the ledger names somebody — and
        // an inspector holds `inspections.conduct`, never `inspections.schedule`.
        // Asking the permission question of that attribution would throw on
        // every field-monitor project and take the whole nightly sweep with
        // it. The engine acts on the state's own configured interval, over a
        // project list it queried itself inside the tenant scope, exactly as
        // GenerateReportObligations does; there is no caller to authorize.
        if (! $system) {
            Gate::forUser($actor)->authorize('create', SiteInspection::class);

            // …and on THIS project. `inspections.schedule` says an officer may
            // put visits in the diary; it does not say which projects are
            // theirs. ProjectPolicy@view asks Project::scopeVisibleTo — the
            // single definition of project visibility — so a project belonging
            // to a workspace the actor merely has a URL for is refused HERE
            // rather than only by the form's option list. A screen validating
            // against what it offers is a good screen; it is not a control,
            // because a Livewire endpoint takes any payload.
            Gate::forUser($actor)->authorize('view', $project);
        }

        $this->assertInspectable($project);
        $this->assertNotInThePast($scheduledDate, $system);
        $this->assertInspectorMayConduct($leadInspector);

        $inspection = DB::transaction(function () use (
            $project, $type, $scheduledDate, $leadInspector, $actor,
            $template, $location, $team, $objectives, $scheduleKey, $system
        ): SiteInspection {
            // The engine's re-entry point: a proposal that already exists is
            // RETURNED, not duplicated and not an error. A cron that ran twice
            // and a worker replayed after a timeout both land here.
            if ($scheduleKey !== null) {
                $existing = SiteInspection::query()
                    ->where('project_id', $project->id)
                    ->where('schedule_key', $scheduleKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $inspection = new SiteInspection([
                'project_id' => $project->id,
                'project_location_id' => $location?->id,
                'inspection_checklist_template_id' => $template?->id,
                'type' => $type,
                'scheduled_date' => $scheduledDate,
                'lead_inspector_id' => $leadInspector->id,
                'team' => $team,
                // The manual's section 1. Pre-filled from the visit type when
                // the scheduler said nothing, because "objectives: (blank)" is
                // the first thing that makes a Field Trip Report worthless.
                'objectives' => $objectives !== null && trim($objectives) !== ''
                    ? trim($objectives)
                    : $type->purpose(),
                'scheduled_by_id' => $actor->id,
            ]);

            // Not fillable, so assigned explicitly: `status` is the
            // chokepoint's column, and stating the opening value in code
            // rather than leaning on a DB default means the in-memory model is
            // never a null status waiting for a re-query to become real.
            $inspection->forceFill([
                'status' => InspectionStatus::Scheduled,
                'generated_by' => $system ? 'system' : 'manual',
                'schedule_key' => $scheduleKey,
            ])->save();

            $event = new SiteInspectionEvent([
                'site_inspection_id' => $inspection->id,
                'from_status' => null,          // creation
                'to_status' => InspectionStatus::Scheduled,
                'actor_id' => $actor->id,
                'reason' => null,
                'occurred_at' => now(),
            ]);
            $event->tenant_id = $inspection->tenant_id;
            $event->save();

            return $inspection;
        });

        // Dispatched after the transaction closes, and only for a genuinely
        // NEW row: the idempotent re-entry above returns an existing proposal,
        // and a nightly cron that re-mailed every open proposal is how an
        // inspector learns to ignore this sender.
        if ($inspection->wasRecentlyCreated) {
            NotifyInspectionScheduled::dispatch($inspection->id);
        }

        return $inspection;
    }

    /**
     * A project that has not been awarded has no site to inspect, and a
     * cancelled one has no work to verify. Everything from mobilisation to
     * closure is inspectable — deliberately including `certified` and
     * `closed`, because post-completion monitoring six to twelve months after
     * handover is step 6 of the lifecycle and happens precisely there.
     */
    private function assertInspectable(Project $project): void
    {
        if (in_array($project->status->value, ['draft', 'cancelled'], true)) {
            throw InspectionRuleViolation::projectNotInspectable($project->status->value);
        }
    }

    /**
     * A visit cannot be planned for a date already gone. The engine is exempt:
     * it proposes from the last visit's anniversary, which is by construction
     * in the past for a project that has been un-inspected for months, and
     * moving that proposal to "today" would hide how overdue it is.
     */
    private function assertNotInThePast(CarbonImmutable $scheduledDate, bool $system): void
    {
        if (! $system && $scheduledDate->startOfDay()->isBefore(CarbonImmutable::now()->startOfDay())) {
            throw InspectionRuleViolation::scheduledDateInPast();
        }
    }

    /**
     * The named lead must actually be able to file the report. Scheduling a
     * visit for a consultant — who holds no `inspections.conduct` — produces a
     * diary entry nobody can action and an overdue report a fortnight later.
     */
    private function assertInspectorMayConduct(User $inspector): void
    {
        if (! $inspector->can('inspections.conduct') && ! $inspector->holdsGlobalPermission('inspections.conduct')) {
            throw InspectionRuleViolation::inspectorNotAuthorised();
        }
    }
}
