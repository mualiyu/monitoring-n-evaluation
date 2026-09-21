<?php

namespace Database\Seeders;

use App\Enums\ChecklistResponseType;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\InspectionChecklistTemplate;
use App\Models\InspectionChecklistTemplateItem;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\SiteInspectionResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A monitoring history per demo project, spread across every state the screens
 * have to render: a signed-off visit, a filed report waiting for sign-off, a
 * failing site that escalated, a visit under way with a half-answered
 * checklist, a machine proposal, and one cancelled for rain.
 *
 * The spread is deliberate rather than random. An inspection board that has
 * never seen a `work_stopped` verdict has never been tested, and a demo where
 * every visit is satisfactory proves nothing about the escalation path — so
 * the two demo ministries are given visibly different field records.
 *
 * Everything is created inside `CurrentTenant::runAs($tenant, …)`, so
 * BelongsToTenant fills tenant_id on inspections, responses and events.
 * Nothing here writes tenant_id by hand and nothing queries by it.
 *
 * Fixtures, not Actions: like DemoProgressReportSeeder, this states where
 * records ARE rather than driving them there — running the real chain would
 * fire queued notifications during `migrate:fresh --seed`. The ledger is
 * written alongside, so the timeline screen has real history.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder, InspectionChecklistSeeder.
 */
class DemoInspectionSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        $template = InspectionChecklistTemplate::query()
            ->whereNull('inspection_type')
            ->with('items')
            ->first();

        if ($template === null) {
            return; // InspectionChecklistSeeder has not run
        }

        foreach (Tenant::query()->get() as $tenant) {
            $staff = $this->staff($tenant);

            if ($staff === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($template, $staff): void {
                $projects = Project::query()
                    // Eager-loaded, not lazy: every visit below stamps the
                    // inspection with the project's primary site, and
                    // Model::preventLazyLoading() is on outside production —
                    // so a lazy read here aborts the whole seed run.
                    ->with('primaryLocation:id,project_id')
                    ->whereIn('status', [ProjectStatus::Mobilized, ProjectStatus::InProgress, ProjectStatus::Completed])
                    ->orderBy('id')
                    ->get();

                foreach ($projects->values() as $index => $project) {
                    $this->historyFor($project, $template, $staff, $index);
                }
            });
        }

        $current->forget();
    }

    /**
     * Five demo shapes, cycled across the portfolio so both ministries show a
     * different field record on the same board.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function historyFor(Project $project, InspectionChecklistTemplate $template, array $staff, int $index): void
    {
        match ($index % 5) {
            0 => $this->shapeHealthy($project, $template, $staff),
            1 => $this->shapeAwaitingSignOff($project, $template, $staff),
            2 => $this->shapeEscalated($project, $template, $staff),
            3 => $this->shapeUnderWay($project, $template, $staff),
            default => $this->shapeProposed($project, $template, $staff),
        };
    }

    /**
     * Inspected, signed off, and a routine visit already in the diary.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function shapeHealthy(Project $project, InspectionChecklistTemplate $template, array $staff): void
    {
        $past = $this->visit($project, $template, $staff, InspectionType::Routine, CarbonImmutable::now()->subDays(38), [
            'status' => InspectionStatus::Reviewed,
            'outcome' => InspectionOutcome::Satisfactory,
        ]);
        $this->answer($past, $template, findings: 0);

        $this->visit($project, $template, $staff, InspectionType::Routine, CarbonImmutable::now()->addDays(6), [
            'status' => InspectionStatus::Scheduled,
        ]);
    }

    /**
     * A report filed and sitting in the M&E officer's queue — the state the
     * separation guard creates and the one the notification exists to unblock.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function shapeAwaitingSignOff(Project $project, InspectionChecklistTemplate $template, array $staff): void
    {
        $visit = $this->visit($project, $template, $staff, InspectionType::Routine, CarbonImmutable::now()->subDays(2), [
            'status' => InspectionStatus::Submitted,
            'outcome' => InspectionOutcome::MinorIssues,
        ]);
        $this->answer($visit, $template, findings: 1);
    }

    /**
     * A failing site. `major_issues` is what the MDA admin is mailed about the
     * moment it is filed.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function shapeEscalated(Project $project, InspectionChecklistTemplate $template, array $staff): void
    {
        $visit = $this->visit($project, $template, $staff, InspectionType::MidTerm, CarbonImmutable::now()->subDays(9), [
            'status' => InspectionStatus::Submitted,
            'outcome' => InspectionOutcome::MajorIssues,
            'findings' => 'Concrete cube tests failed at 21 days on two of four pours. Formwork struck early on the north abutment. '
                .'Reinforcement cover measured at 18 mm against a specified 40 mm.',
            'recommendations' => 'Halt further pours pending re-test. Instruct the contractor to open up the north abutment at their own cost.',
            'risk_flags' => ['structural_defect', 'specification_deviation'],
        ]);
        $this->answer($visit, $template, findings: 3);
    }

    /**
     * An inspector on site right now, with the checklist half answered and the
     * report clock running.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function shapeUnderWay(Project $project, InspectionChecklistTemplate $template, array $staff): void
    {
        $visit = $this->visit($project, $template, $staff, InspectionType::Routine, CarbonImmutable::now()->subDay(), [
            'status' => InspectionStatus::InProgress,
        ]);
        $this->answer($visit, $template, findings: 0, limit: 3);

        // …and one cancelled for weather, so the board shows what a reasoned
        // cancellation looks like next to a live visit.
        $this->visit($project, $template, $staff, InspectionType::Routine, CarbonImmutable::now()->subDays(20), [
            'status' => InspectionStatus::Cancelled,
        ]);
    }

    /**
     * A machine proposal nobody has confirmed yet — what the engine produces
     * for a project that has gone past its monitoring interval.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function shapeProposed(Project $project, InspectionChecklistTemplate $template, array $staff): void
    {
        $this->visit($project, $template, $staff, InspectionType::Routine, CarbonImmutable::now(), [
            'status' => InspectionStatus::Scheduled,
            'generated_by' => 'system',
            'schedule_key' => 'routine:'.CarbonImmutable::now()->format('Y-m'),
        ]);
    }

    /**
     * One visit plus its ledger. `$overrides` states where the record IS;
     * every chain stamp consistent with that state is derived here, so a
     * fixture can never produce a `reviewed` inspection with no reviewer.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     * @param  array<string, mixed>  $overrides
     */
    private function visit(
        Project $project,
        InspectionChecklistTemplate $template,
        array $staff,
        InspectionType $type,
        CarbonImmutable $on,
        array $overrides,
    ): SiteInspection {
        /** @var InspectionStatus $status */
        $status = $overrides['status'];
        $monitor = $staff['monitor'];

        $conducted = $status === InspectionStatus::Scheduled ? null : $on->setTime(10, 30);

        $inspection = new SiteInspection([
            'project_id' => $project->id,
            'project_location_id' => $project->primaryLocation?->id,
            'inspection_checklist_template_id' => $template->id,
            'type' => $type,
            'scheduled_date' => $on->startOfDay(),
            'lead_inspector_id' => $monitor->id,
            'team' => 'Engr. A. Bello (works), '.$staff['officer']->name.' (M&E), site foreman',
            'objectives' => $type->purpose(),
            'scheduled_by_id' => $staff['officer']->id,
        ]);

        $inspection->forceFill([
            'status' => $status,
            'conducted_at' => $conducted,
            'started_at' => $conducted,
            'report_due_at' => $conducted?->addDays(3)->endOfDay(),
            'generated_by' => $overrides['generated_by'] ?? 'manual',
            'schedule_key' => $overrides['schedule_key'] ?? null,
            ...$this->reportFields($status, $overrides),
            ...$this->chainStamps($status, $staff, $on),
        ])->save();

        $this->ledger($inspection, $status, $staff, $on);

        return $inspection;
    }

    /**
     * The Field Trip Report narrative, present only once there is one.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reportFields(InspectionStatus $status, array $overrides): array
    {
        if (! $status->isFiled()) {
            return $status === InspectionStatus::InProgress
                ? ['physical_progress_observed' => '38.00', 'findings' => null]
                : [];
        }

        return [
            'outcome' => $overrides['outcome'] ?? InspectionOutcome::Satisfactory,
            'physical_progress_observed' => '52.00',
            'people_met' => 'Site engineer, contractor’s project manager, two community representatives.',
            'methods' => 'Physical measurement of completed sections, photographic record, interview with the site engineer.',
            'findings' => $overrides['findings'] ?? 'Sub-base laid across 1.2 km of the northern section. Drainage cast at '
                .'three of five crossings. Reinforcement stacked uncovered at the site store.',
            'comparison_with_previous' => 'Progress since the last visit is broadly in line with the programme; drainage has caught up.',
            'conclusions' => 'Work is proceeding substantially to specification.',
            'recommendations' => $overrides['recommendations'] ?? 'Cover stored reinforcement. Re-inspect the two outstanding crossings next visit.',
            'risk_flags' => $overrides['risk_flags'] ?? null,
            'latitude' => '7.2570000',
            'longitude' => '5.2050000',
            'gps_accuracy_metres' => 14,
            'gps_captured_at' => $overrides['gps_captured_at'] ?? null,
            'geofence_distance_metres' => 85,
            'geofence_breached' => false,
        ];
    }

    /**
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     * @return array<string, mixed>
     */
    private function chainStamps(InspectionStatus $status, array $staff, CarbonImmutable $on): array
    {
        return match ($status) {
            InspectionStatus::Submitted => [
                'submitted_by_id' => $staff['monitor']->id,
                'submitted_at' => $on->addDay(),
            ],
            InspectionStatus::Reviewed => [
                'submitted_by_id' => $staff['monitor']->id,
                'submitted_at' => $on->addDay(),
                // NOT the monitor: separation of duties is visible in the
                // demo data, not only in the guard that enforces it.
                'reviewed_by_id' => $staff['officer']->id,
                'reviewed_at' => $on->addDays(2),
                'review_notes' => 'Findings accepted. Housekeeping item carried to the issues register.',
            ],
            InspectionStatus::Cancelled => [
                'cancelled_by_id' => $staff['officer']->id,
                'cancelled_at' => $on,
                'cancellation_reason' => 'Access road impassable after three days of rain; visit deferred to the next cycle.',
            ],
            default => [],
        };
    }

    /**
     * The append-only ledger for this visit, in order.
     *
     * @param  array{admin: User, officer: User, monitor: User}  $staff
     */
    private function ledger(SiteInspection $inspection, InspectionStatus $status, array $staff, CarbonImmutable $on): void
    {
        $steps = [[null, InspectionStatus::Scheduled, $staff['officer'], $on->subDays(4), null]];

        if ($status === InspectionStatus::Cancelled) {
            $steps[] = [InspectionStatus::Scheduled, InspectionStatus::Cancelled, $staff['officer'], $on, $inspection->cancellation_reason];
        }

        if (in_array($status, [InspectionStatus::InProgress, InspectionStatus::Submitted, InspectionStatus::Reviewed], true)) {
            $steps[] = [InspectionStatus::Scheduled, InspectionStatus::InProgress, $staff['monitor'], $on->setTime(10, 30), null];
        }

        if (in_array($status, [InspectionStatus::Submitted, InspectionStatus::Reviewed], true)) {
            $steps[] = [InspectionStatus::InProgress, InspectionStatus::Submitted, $staff['monitor'], $on->addDay(), null];
        }

        if ($status === InspectionStatus::Reviewed) {
            $steps[] = [InspectionStatus::Submitted, InspectionStatus::Reviewed, $staff['officer'], $on->addDays(2), $inspection->review_notes];
        }

        foreach ($steps as [$from, $to, $actor, $at, $reason]) {
            $event = new SiteInspectionEvent([
                'site_inspection_id' => $inspection->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => $at,
            ]);
            $event->tenant_id = $inspection->tenant_id;
            $event->save();
        }
    }

    /**
     * Checklist answers, with `$findings` of them unfavourable — each carrying
     * the note the domain rule demands, because a demo that shows a flag with
     * no explanation teaches the wrong habit.
     */
    private function answer(SiteInspection $inspection, InspectionChecklistTemplate $template, int $findings, ?int $limit = null): void
    {
        $items = $template->items->take($limit ?? $template->items->count());
        $remaining = $findings;

        foreach ($items as $item) {
            $isFinding = $remaining > 0 && $item->finding_on_no;

            if ($isFinding) {
                $remaining--;
            }

            SiteInspectionResponse::query()->create([
                'site_inspection_id' => $inspection->id,
                'inspection_checklist_template_item_id' => $item->id,
                'prompt' => $item->prompt,
                'response_type' => $item->response_type,
                ...$this->valueFor($item, $isFinding),
                'is_finding' => $isFinding,
                'note' => $isFinding ? 'Observed on the eastern bay; photographed and shown to the site engineer.' : null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function valueFor(InspectionChecklistTemplateItem $item, bool $isFinding): array
    {
        return match ($item->response_type) {
            ChecklistResponseType::YesNo => ['value_boolean' => ! $isFinding],
            ChecklistResponseType::Rating => ['value_number' => $isFinding ? '2.00' : '4.00'],
            ChecklistResponseType::Numeric => ['value_number' => '14.00'],
            ChecklistResponseType::Text => ['value_text' => 'Laterite surface, passable to light vehicles in dry weather only.'],
        };
    }

    /**
     * @return array{admin: User, officer: User, monitor: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $find = fn (Role $role): ?User => User::query()
            ->where('email', $role->value.'@'.$tenant->slug.'.mne.test')
            ->first();

        $admin = $find(Role::MdaAdmin);
        $officer = $find(Role::MeOfficer);
        $monitor = $find(Role::FieldMonitor);

        if ($admin === null || $officer === null || $monitor === null) {
            return null;
        }

        return ['admin' => $admin, 'officer' => $officer, 'monitor' => $monitor];
    }
}
