<?php

namespace Database\Seeders;

use App\Enums\ProjectStatus;
use App\Enums\ReportEntryMode;
use App\Enums\ReportingCadence;
use App\Enums\ReportObligationStatus;
use App\Enums\Role;
use App\Models\ProgressReport;
use App\Models\ProgressReportEvent;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Three months of reporting history per demo project, spread across every
 * state the Phase 1 screens have to render (progress-reporting.md §8): an
 * approved return, one awaiting approval, one sent back with a reason, a live
 * draft, a late filing and a missed window.
 *
 * The spread is deliberate rather than random. A reporting inbox that has
 * never seen a returned report has never been tested, and a compliance league
 * table on which every MDA scores 100% proves nothing — so the two demo
 * ministries are given visibly different records.
 *
 * Everything is created inside `CurrentTenant::runAs($tenant, …)`, so
 * BelongsToTenant fills tenant_id on obligations, reports and chain events.
 * Nothing here writes tenant_id by hand and nothing queries by it.
 *
 * Fixtures, not Actions: like DemoProjectSeeder's status trail, this states
 * where records ARE rather than driving them there — running the real chain
 * would fire queued notifications during `migrate:fresh --seed`. The chain
 * ledger is written alongside, so the timeline screen has real history.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder, ReportingPeriodSeeder.
 */
class DemoProgressReportSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        $periods = $this->recentMonthlyPeriods();

        if (count($periods) < 3) {
            return;
        }

        foreach (Tenant::query()->get() as $tenant) {
            $staff = $this->staff($tenant);

            if ($staff === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($periods, $staff): void {
                $projects = Project::query()
                    ->whereIn('status', [ProjectStatus::Mobilized, ProjectStatus::InProgress, ProjectStatus::Completed])
                    ->orderBy('id')
                    ->get();

                foreach ($projects->values() as $index => $project) {
                    $this->historyFor($project, $periods, $staff, $index);
                }
            });
        }

        $current->forget();
    }

    /**
     * The three windows a demo needs: the month before last (settled), last
     * month (in flight) and the current one (live).
     *
     * @return list<ReportingPeriod>
     */
    private function recentMonthlyPeriods(): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(2);

        return ReportingPeriod::query()
            ->where('cadence', ReportingCadence::Monthly)
            ->where('period_start', '>=', $start->toDateString())
            ->orderBy('period_start')
            ->limit(3)
            ->get()
            ->all();
    }

    /**
     * @param  list<ReportingPeriod>  $periods  oldest first
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function historyFor(Project $project, array $periods, array $staff, int $index): void
    {
        [$oldest, $middle, $current] = $periods;

        // Four demo shapes, cycled across the portfolio so both ministries
        // show a different compliance record on the same board.
        match ($index % 4) {
            0 => $this->shapeSettled($project, $oldest, $middle, $current, $staff),
            1 => $this->shapeReturned($project, $oldest, $middle, $current, $staff),
            2 => $this->shapeDelinquent($project, $oldest, $middle, $current, $staff),
            default => $this->shapeQuiet($project, $oldest, $middle, $current, $staff),
        };
    }

    /**
     * On top of its reporting: approved, then reviewed, then a live draft.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function shapeSettled(Project $project, ReportingPeriod $oldest, ReportingPeriod $middle, ReportingPeriod $current, array $staff): void
    {
        $this->approvedReturn($project, $oldest, $staff, late: false);
        $this->reviewedReturn($project, $middle, $staff);
        $this->draftReturn($project, $current, $staff);
    }

    /**
     * Filed late once, and has a return sitting in its own inbox to fix.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function shapeReturned(Project $project, ReportingPeriod $oldest, ReportingPeriod $middle, ReportingPeriod $current, array $staff): void
    {
        $this->approvedReturn($project, $oldest, $staff, late: true);
        $this->returnedReturn($project, $middle, $staff);
        $this->pendingObligation($project, $current);
    }

    /**
     * The MDA the board exists to surface: a missed window and nothing filed
     * since.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function shapeDelinquent(Project $project, ReportingPeriod $oldest, ReportingPeriod $middle, ReportingPeriod $current, array $staff): void
    {
        $this->missedObligation($project, $oldest);
        $this->approvedReturn($project, $middle, $staff, late: true);
        $this->pendingObligation($project, $current);
    }

    /**
     * Reporting, quietly, with the current window still open.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function shapeQuiet(Project $project, ReportingPeriod $oldest, ReportingPeriod $middle, ReportingPeriod $current, array $staff): void
    {
        $this->approvedReturn($project, $oldest, $staff, late: false);
        $this->approvedReturn($project, $middle, $staff, late: false);
        $this->draftReturn($project, $current, $staff);
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function approvedReturn(Project $project, ReportingPeriod $period, array $staff, bool $late): void
    {
        $obligation = $this->obligationFor($project, $period);

        $report = ProgressReport::factory()
            ->forObligation($obligation)
            ->by($staff['consultant'])
            ->submitted($staff['consultant'])
            ->reviewed($staff['officer'])
            ->approved($staff['admin'])
            ->create([
                'physical_progress_claimed' => $project->physical_progress,
                'physical_progress_before' => $project->physical_progress,
                'cumulative_expenditure_snapshot' => $project->expenditure_to_date,
                'submitted_late' => $late,
                'entry_mode' => ReportEntryMode::SelfService,
            ]);

        $this->chain($report, $staff, [
            [null, 'draft', $staff['consultant'], null],
            ['draft', 'submitted', $staff['consultant'], null],
            ['submitted', 'reviewed', $staff['officer'], null],
            ['reviewed', 'approved', $staff['admin'], null],
        ]);

        $obligation->forceFill([
            'status' => ReportObligationStatus::Fulfilled,
            'progress_report_id' => $report->id,
            'fulfilled_at' => $period->due_at->subDay(),
            'submitted_late' => $late,
        ])->save();
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function reviewedReturn(Project $project, ReportingPeriod $period, array $staff): void
    {
        $obligation = $this->obligationFor($project, $period);

        $report = ProgressReport::factory()
            ->forObligation($obligation)
            ->by($staff['consultant'])
            ->submitted($staff['consultant'])
            ->reviewed($staff['officer'])
            ->create(['physical_progress_claimed' => $project->physical_progress]);

        $this->chain($report, $staff, [
            [null, 'draft', $staff['consultant'], null],
            ['draft', 'submitted', $staff['consultant'], null],
            ['submitted', 'reviewed', $staff['officer'], null],
        ]);

        $obligation->forceFill([
            'status' => ReportObligationStatus::Fulfilled,
            'progress_report_id' => $report->id,
            'fulfilled_at' => $period->due_at->subDays(2),
        ])->save();
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function returnedReturn(Project $project, ReportingPeriod $period, array $staff): void
    {
        $obligation = $this->obligationFor($project, $period);
        $reason = 'Reported expenditure does not reconcile with the attached valuation certificate — '
            .'please correct the period figure and resubmit.';

        $report = ProgressReport::factory()
            ->forObligation($obligation)
            ->by($staff['consultant'])
            ->submitted($staff['consultant'])
            ->returned($reason)
            ->create(['returned_by_id' => $staff['officer']->id]);

        $this->chain($report, $staff, [
            [null, 'draft', $staff['consultant'], null],
            ['draft', 'submitted', $staff['consultant'], null],
            ['submitted', 'returned', $staff['officer'], $reason],
        ]);

        // The obligation stays FULFILLED: the MDA did file, on time, and a
        // reviewer's turnaround must not retroactively make them late (§9.6).
        $obligation->forceFill([
            'status' => ReportObligationStatus::Fulfilled,
            'progress_report_id' => $report->id,
            'fulfilled_at' => $period->due_at->subDay(),
        ])->save();
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function draftReturn(Project $project, ReportingPeriod $period, array $staff): void
    {
        $obligation = $this->obligationFor($project, $period);

        $report = ProgressReport::factory()
            ->forObligation($obligation)
            ->by($staff['consultant'])
            ->draft()
            ->create([
                'narrative_challenges' => null,
                'narrative_next_period' => null,
                'autosaved_at' => CarbonImmutable::now()->subHours(3),
            ]);

        $this->chain($report, $staff, [[null, 'draft', $staff['consultant'], null]]);
    }

    private function pendingObligation(Project $project, ReportingPeriod $period): void
    {
        $this->obligationFor($project, $period);
    }

    /** A window that closed with nothing filed — the black mark on the board. */
    private function missedObligation(Project $project, ReportingPeriod $period): void
    {
        $this->obligationFor($project, $period)->forceFill([
            'status' => ReportObligationStatus::Missed,
            'overdue_notified_at' => $period->due_at->addDay(),
            'escalation_stage' => 2,
            'escalated_at' => $period->due_at->addDays(7),
        ])->save();
    }

    private function obligationFor(Project $project, ReportingPeriod $period): ReportObligation
    {
        return ReportObligation::factory()->create([
            'reporting_period_id' => $period->id,
            'project_id' => $project->id,
            'due_at' => $period->due_at,
        ]);
    }

    /**
     * The chain ledger the timeline screen reads. Append-only, so it is
     * written once, in order, with a real actor on every hop.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     * @param  list<array{0: string|null, 1: string, 2: User, 3: string|null}>  $steps
     */
    private function chain(ProgressReport $report, array $staff, array $steps): void
    {
        $occurredAt = $report->due_at->subDays(count($steps) + 1);

        foreach ($steps as [$from, $to, $actor, $reason]) {
            ProgressReportEvent::factory()->create([
                'progress_report_id' => $report->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ]);

            $occurredAt = $occurredAt->addDay();
        }
    }

    /**
     * The demo users DemoTenantSeeder created for this workspace.
     *
     * @return array{admin: User, officer: User, consultant: User, monitor: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $find = fn (Role $role): ?User => User::query()
            ->where('email', $role->value.'@'.$tenant->slug.'.mne.test')
            ->first();

        $admin = $find(Role::MdaAdmin);
        $officer = $find(Role::MeOfficer);
        $consultant = $find(Role::Consultant);
        $monitor = $find(Role::FieldMonitor);

        if ($admin === null || $officer === null || $consultant === null || $monitor === null) {
            return null;
        }

        return ['admin' => $admin, 'officer' => $officer, 'consultant' => $consultant, 'monitor' => $monitor];
    }
}
