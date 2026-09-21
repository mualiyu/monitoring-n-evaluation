<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\ProjectStatus;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * The scheduling engine: proposes a routine monitoring visit for every project
 * under execution that has gone longer than `inspections.routine_interval_months`
 * without one (plan §4; digest §8 step 3).
 *
 * IDEMPOTENCY IS STRUCTURAL, NOT DEFENSIVE. Every proposal carries a
 * deterministic `schedule_key` — "routine:2026-10", the window it is proposed
 * FOR — and the table holds a unique index on (tenant, project, schedule_key).
 * A double cron run, an overlapping worker or a replayed job cannot produce a
 * second proposal, because the second attempt computes the identical key and
 * ScheduleInspection returns the existing row under a lock. There is no "have
 * I proposed this?" lookup table and no reliance on the queue being
 * exactly-once — it is not.
 *
 * The key is derived from the DUE MONTH rather than from the run date, which
 * is what makes it stable: a sweep that fails on the 1st and is re-run on the
 * 3rd proposes the same visit, not a second one.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: it never proposes the other four
 * inspection types. Those are triggered by an award, a progress figure, a
 * completion claim or a handover date — events, not clocks — and inventing
 * them on a timer would fill a ministry's diary with visits nobody asked for.
 *
 * TENANCY: iterates tenants through CurrentTenant::runAs(), so BelongsToTenant
 * fills tenant_id on create and every read is scoped. There is no
 * withoutTenancy() here — a console command is not an oversight surface, and
 * the sweep visiting each workspace in turn is what keeps the scope honest.
 */
class ProposeRoutineInspections
{
    /**
     * @return int the number of proposals created
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $proposed = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $proposed += $current->runAs($tenant, fn (): int => $this->forTenant($asOf));
        }

        return $proposed;
    }

    private function forTenant(CarbonImmutable $asOf): int
    {
        $interval = $this->intervalMonths();

        if ($interval < 1) {
            // 0 turns routine proposals off for this instance — a state that
            // schedules its own field work by hand.
            return 0;
        }

        $cutoff = $asOf->subMonths($interval);
        $proposed = 0;

        foreach ($this->projectsUnderExecution() as $project) {
            $proposed += $this->propose($project, $asOf, $cutoff) ? 1 : 0;
        }

        return $proposed;
    }

    /**
     * Projects that owe routine monitoring: work is actually happening on
     * site. A mobilized project has a contractor on the ground; a completed
     * one awaits its final inspection, which is event-triggered rather than
     * periodic.
     *
     * @return iterable<int, Project>
     */
    private function projectsUnderExecution(): iterable
    {
        return Project::query()
            ->whereIn('status', [ProjectStatus::Mobilized, ProjectStatus::InProgress])
            ->with('primaryLocation')
            ->cursor();
    }

    /**
     * One project's proposal, or none.
     *
     * Three reasons to skip, all of them cheap and all of them checked before
     * anything is written: a visit is already open (proposing a second while
     * the first sits in the diary is how an inspection board becomes noise),
     * a visit happened inside the interval, or the project has nobody who
     * could conduct one.
     */
    private function propose(Project $project, CarbonImmutable $asOf, CarbonImmutable $cutoff): bool
    {
        $hasOpenVisit = SiteInspection::query()
            ->where('project_id', $project->id)
            ->open()
            ->exists();

        if ($hasOpenVisit) {
            return false;
        }

        $lastVisit = SiteInspection::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Reviewed])
            ->max('conducted_at');

        if ($lastVisit !== null && CarbonImmutable::parse($lastVisit)->isAfter($cutoff)) {
            return false;
        }

        $inspector = $this->inspectorFor($project);

        if ($inspector === null) {
            return false;
        }

        // Proposed for today: the interval has already elapsed, so this visit
        // is owed now. Dating it forward would understate how overdue the
        // project's monitoring is, which is the one thing the board exists to
        // show.
        $dueDate = $asOf->startOfDay();

        try {
            app(ScheduleInspection::class)(
                project: $project,
                type: InspectionType::Routine,
                scheduledDate: $dueDate,
                leadInspector: $inspector,
                actor: $inspector,
                template: $this->templateFor($project),
                location: $project->primaryLocation,
                objectives: null,
                scheduleKey: 'routine:'.$dueDate->format('Y-m'),
                system: true,
            );
        } catch (InspectionRuleViolation) {
            // A project that turned out not to be inspectable between the
            // query and the write. The sweep never fails a whole tenant over
            // one row.
            return false;
        }

        return true;
    }

    /**
     * Who the proposal is assigned to: the project's manager when they can
     * conduct inspections, otherwise the first active assignee who can.
     *
     * A proposal with no inspector is not created at all. The alternative —
     * assigning it to whoever happens to hold the permission in the MDA —
     * produces a diary entry addressed to nobody in particular, which is a
     * report that is overdue a fortnight later.
     */
    private function inspectorFor(Project $project): ?User
    {
        $candidateIds = $project->assignments()
            ->whereNull('unassigned_at')
            ->pluck('user_id')
            ->push($project->manager_id)
            ->filter()
            ->unique()
            ->all();

        if ($candidateIds === []) {
            return null;
        }

        $candidates = User::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->get();

        foreach ($candidates as $candidate) {
            // Spatie bakes the team id into the roles relation at load time,
            // and this sweep switches tenants between iterations — so the
            // cached relation is dropped before the permission is asked.
            $candidate->unsetRelation('roles')->unsetRelation('permissions');

            if ($candidate->can('inspections.conduct')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The state's instrument for a routine visit on this project's sector.
     * Null is fine: an inspection without a checklist is still a Field Trip
     * Report, and a state that has not written its instruments yet must not be
     * blocked from monitoring.
     */
    private function templateFor(Project $project): ?InspectionChecklistTemplate
    {
        return InspectionChecklistTemplate::query()
            ->active()
            ->for(InspectionType::Routine, $project->sector_id)
            // A sector-specific instrument beats the general sweep.
            ->orderByRaw('CASE WHEN sector_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN inspection_type IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->first();
    }

    private function intervalMonths(): int
    {
        return app(SettingsRepository::class)->int('inspections', 'routine_interval_months', 1);
    }
}
