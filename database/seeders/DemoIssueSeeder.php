<?php

namespace Database\Seeders;

use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * A challenges register and a deviation board that look like a real MDA's:
 * open items with owners, one escalated item nobody picked up, a resolved one,
 * and a closed one — plus exception reports from both doors (the threshold
 * sweep and a human reporting a critical incident).
 *
 * The spread is deliberate rather than random. A register that has never seen
 * an escalation has never been tested, and a deviation board on which every
 * report is `open` proves nothing about the acknowledge/resolve path. The two
 * demo ministries are therefore given visibly different records.
 *
 * Everything is created inside `CurrentTenant::runAs($tenant, …)`, so
 * BelongsToTenant fills tenant_id on issues, ledger rows and exception
 * reports. Nothing here writes tenant_id by hand and nothing queries by it.
 *
 * Fixtures, not Actions: like DemoProgressReportSeeder, this states where
 * records ARE rather than driving them there — running the real chain would
 * fire queued notifications during `migrate:fresh --seed`. The ledger is
 * written alongside, so the timeline screen has real history.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder.
 */
class DemoIssueSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        foreach (Tenant::query()->get() as $index => $tenant) {
            $staff = $this->staff($tenant);

            if ($staff === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($staff, $index): void {
                $projects = Project::query()
                    ->whereIn('status', [ProjectStatus::Mobilized, ProjectStatus::InProgress])
                    ->orderBy('id')
                    ->get();

                if ($projects->isEmpty()) {
                    return;
                }

                $this->registerFor($projects, $staff, $index);
            });
        }

        $current->forget();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array{admin: User, officer: User}  $staff
     */
    private function registerFor($projects, array $staff, int $tenantIndex): void
    {
        /** @var Project $first */
        $first = $projects->first();
        $second = $projects->count() > 1 ? $projects[1] : $first;

        // 1. Open, owned, with a plan and a live deadline — the ordinary case.
        $this->issue($first, $staff, [
            'title' => 'Cash release for the second valuation outstanding',
            'description' => 'The certified second interim valuation has been with the Ministry of Finance for '
                .'five weeks. The contractor has suspended night works pending payment.',
            'category' => IssueCategory::Funding,
            'severity' => IssueSeverity::High,
            'status' => IssueStatus::InProgress,
            'owner_id' => $staff['officer']->id,
            'corrective_action' => 'Permanent Secretary to raise it at the next Executive Council finance session; '
                .'M&E unit to circulate the certified valuation to the Accountant-General.',
            'due_date' => CarbonImmutable::now()->addDays(10)->toDateString(),
        ], daysAgo: 12);

        // 2. ESCALATED — open too long, nobody picked it up. A register that
        //    has never seen an escalation has never been tested.
        $this->issue($first, $staff, [
            'title' => 'Right-of-way dispute at chainage 3+400',
            'description' => 'A family is disputing compensation for the strip acquired at chainage 3+400 and has '
                .'blocked access with a fence. Works on the northern section have stopped.',
            'category' => IssueCategory::Access,
            'severity' => IssueSeverity::Critical,
            'status' => IssueStatus::Escalated,
            'owner_id' => null,
            'due_date' => CarbonImmutable::now()->subDays(4)->toDateString(),
            'escalated_at' => CarbonImmutable::now()->subDays(2),
        ], daysAgo: 9);

        // 3. Resolved, with an account of how — what the manual's corrective
        //    action loop is actually for.
        $this->issue($second, $staff, [
            'title' => 'Sub-base material failing grading tests',
            'description' => 'Two consecutive grading tests on the borrow pit material fell outside specification.',
            'category' => IssueCategory::Design,
            'severity' => IssueSeverity::Medium,
            'status' => IssueStatus::Resolved,
            'owner_id' => $staff['officer']->id,
            'corrective_action' => 'Alternative borrow pit approved by the materials engineer; failed material removed.',
            'resolution_note' => 'New pit approved on 2 March and three consecutive tests have since passed.',
            'resolved_by_id' => $staff['admin']->id,
            'resolved_at' => CarbonImmutable::now()->subDays(3),
        ], daysAgo: 30);

        // The second ministry gets a closed item too, so the two registers do
        // not look identical on a demo.
        if ($tenantIndex > 0) {
            $this->issue($second, $staff, [
                'title' => 'Community protest over borrow pit haulage',
                'description' => 'Residents of the adjoining settlement petitioned the LGA over dust and night haulage.',
                'category' => IssueCategory::Community,
                'severity' => IssueSeverity::Low,
                'status' => IssueStatus::Closed,
                'owner_id' => $staff['admin']->id,
                'resolution_note' => 'Haulage hours restricted and water bowser deployed; petition withdrawn.',
                'resolved_by_id' => $staff['officer']->id,
                'resolved_at' => CarbonImmutable::now()->subDays(20),
                'closed_by_id' => $staff['admin']->id,
                'closed_at' => CarbonImmutable::now()->subDays(18),
            ], daysAgo: 60);
        }

        $this->deviationsFor($first, $second, $staff);
    }

    /**
     * @param  array{admin: User, officer: User}  $staff
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, array $staff, array $attributes, int $daysAgo = 7): Issue
    {
        $raisedAt = CarbonImmutable::now()->subDays($daysAgo);

        $issue = new Issue([
            'project_id' => $project->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'],
            'category' => $attributes['category'],
            'severity' => $attributes['severity'],
            'owner_id' => $attributes['owner_id'] ?? null,
            'corrective_action' => $attributes['corrective_action'] ?? null,
            'due_date' => $attributes['due_date'] ?? null,
            'raised_by_id' => $staff['officer']->id,
        ]);

        $issue->forceFill([
            'status' => $attributes['status'],
            'resolution_note' => $attributes['resolution_note'] ?? null,
            'resolved_by_id' => $attributes['resolved_by_id'] ?? null,
            'resolved_at' => $attributes['resolved_at'] ?? null,
            'closed_by_id' => $attributes['closed_by_id'] ?? null,
            'closed_at' => $attributes['closed_at'] ?? null,
            'escalated_at' => $attributes['escalated_at'] ?? null,
            'status_changed_at' => $raisedAt,
            'created_at' => $raisedAt,
            'updated_at' => $raisedAt,
        ])->save();

        $this->ledgerFor($issue, $staff, $raisedAt);

        return $issue;
    }

    /**
     * The append-only history, so the timeline screen has something real to
     * render rather than a single "raised" row.
     *
     * @param  array{admin: User, officer: User}  $staff
     */
    private function ledgerFor(Issue $issue, array $staff, CarbonImmutable $raisedAt): void
    {
        $steps = [[null, IssueStatus::Open, $issue->raised_by_id, null, $raisedAt]];

        $path = match ($issue->status) {
            IssueStatus::InProgress => [IssueStatus::Acknowledged, IssueStatus::InProgress],
            IssueStatus::Escalated => [IssueStatus::Escalated],
            IssueStatus::Resolved => [IssueStatus::Acknowledged, IssueStatus::InProgress, IssueStatus::Resolved],
            IssueStatus::Closed => [
                IssueStatus::Acknowledged, IssueStatus::InProgress,
                IssueStatus::Resolved, IssueStatus::Closed,
            ],
            default => [],
        };

        $from = IssueStatus::Open;
        $at = $raisedAt;

        foreach ($path as $to) {
            $at = $at->addDay();

            $steps[] = [
                $from,
                $to,
                // The escalation rung has no human actor, by design.
                $to === IssueStatus::Escalated ? null : $staff['officer']->id,
                match ($to) {
                    IssueStatus::Escalated => 'Escalated automatically: open past the critical allowance.',
                    IssueStatus::Resolved => $issue->resolution_note,
                    default => null,
                },
                $at,
            ];

            $from = $to;
        }

        foreach ($steps as [$fromStatus, $toStatus, $actorId, $reason, $occurredAt]) {
            $event = new IssueEvent([
                'issue_id' => $issue->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actorId,
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]);
            $event->tenant_id = $issue->tenant_id;
            $event->save();
        }
    }

    /**
     * Both doors of the exception register: a threshold-raised slippage (no
     * actor), and a human reporting a critical incident.
     *
     * @param  array{admin: User, officer: User}  $staff
     */
    private function deviationsFor(Project $first, Project $second, array $staff): void
    {
        $slippage = new ExceptionReport([
            'project_id' => $first->id,
            'trigger' => ExceptionTrigger::ScheduleSlippage,
            'severity' => ExceptionTrigger::ScheduleSlippage->defaultSeverity(),
            'narrative' => 'Physical progress has fallen behind the elapsed contract schedule by more than the '
                .'configured tolerance. Measured at 34.00 percentage points against a tolerance of 15.00, '
                .'with physical progress at 38.00%.',
            'measured_value' => '34.00',
            'threshold_value' => '15.00',
            'physical_progress' => '38.00',
            'schedule_elapsed' => '72.00',
            'financial_progress' => '51.00',
            'measured_at' => CarbonImmutable::now()->subDays(2),
            // Null = raised by the threshold engine.
            'raised_by_id' => null,
        ]);
        $slippage->status = ExceptionStatus::Open;
        $slippage->save();

        $incident = new ExceptionReport([
            'project_id' => $second->id,
            'trigger' => ExceptionTrigger::CriticalIncident,
            'severity' => ExceptionTrigger::CriticalIncident->defaultSeverity(),
            'narrative' => 'A pier of the completed span has cracked and the crossing has been closed to traffic '
                .'pending a structural assessment.',
            'measured_at' => CarbonImmutable::now()->subDays(6),
            'raised_by_id' => $staff['officer']->id,
        ]);
        $incident->forceFill([
            'status' => ExceptionStatus::Acknowledged,
            'acknowledged_by_id' => $staff['admin']->id,
            'acknowledged_at' => CarbonImmutable::now()->subDays(5),
        ])->save();
    }

    /**
     * The workspace's admin and M&E officer. Returns null when the tenant has
     * neither — a demo seeder must not invent staff.
     *
     * @return array{admin: User, officer: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $admin = $this->firstWithRole($tenant, Role::MdaAdmin);
        $officer = $this->firstWithRole($tenant, Role::MeOfficer) ?? $admin;

        if ($admin === null || $officer === null) {
            return null;
        }

        return ['admin' => $admin, 'officer' => $officer];
    }

    private function firstWithRole(Tenant $tenant, Role $role): ?User
    {
        // spatie resolves the `role` scope against the CURRENT permission
        // team, which runAs() binds — so this can only ever find users of this
        // MDA.
        return app(CurrentTenant::class)->runAs(
            $tenant,
            fn (): ?User => User::query()->role($role->value)->orderBy('id')->first(),
        );
    }
}
