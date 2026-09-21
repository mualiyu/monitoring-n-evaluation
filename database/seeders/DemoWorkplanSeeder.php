<?php

namespace Database\Seeders;

use App\Enums\ActivityStatus;
use App\Enums\Role;
use App\Enums\WorkplanStatus;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Models\WorkplanEvent;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Database\Factories\WorkplanActivityFactory;
use Illuminate\Database\Seeder;

/**
 * One Annual Work Plan & Budget per demo entity, drawn from the manual's own
 * implementation plan (ondo-manual-digest §6, Appendix A), plus last year's
 * closed plan so the register has history and the year filter has something
 * to filter.
 *
 * The spread is deliberate rather than random. The two entities are given
 * visibly different records: one plan is APPROVED and running with activities
 * at every status including a delayed one, the other is still awaiting a
 * decision. And — the point of the module — a couple of activities carry NO
 * output indicator, because a demo in which the manual's rule is never broken
 * has never tested the warning that exists to catch it.
 *
 * Fixtures, not Actions: like DemoProjectSeeder's status trail this states
 * where records ARE rather than driving them there — running the real chain
 * would fire queued notifications during `migrate:fresh --seed`. The chain
 * ledger is written alongside, so the timeline screen has real history.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder.
 */
class DemoWorkplanSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        $thisYear = (int) CarbonImmutable::now()->year;

        foreach (Tenant::query()->orderBy('id')->get()->values() as $index => $tenant) {
            $staff = $this->staff($tenant);

            if ($staff === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($staff, $thisYear, $index): void {
                // Last year, closed — the register needs history for the year
                // filter to mean anything.
                $this->plan($thisYear - 1, WorkplanStatus::Closed, $staff, $index, closed: true);

                // This year: the first entity is running its plan, the second
                // is still waiting on its accounting officer.
                $this->plan(
                    $thisYear,
                    $index === 0 ? WorkplanStatus::Active : WorkplanStatus::Submitted,
                    $staff,
                    $index,
                );
            });
        }

        $current->forget();
    }

    /**
     * @param  array{owner: User, approver: User}  $staff
     */
    private function plan(int $year, WorkplanStatus $status, array $staff, int $entityIndex, bool $closed = false): void
    {
        if (Workplan::query()->where('year', $year)->exists()) {
            return; // idempotent: a re-seed converges rather than duplicating
        }

        $start = CarbonImmutable::create($year, 1, 1);
        $end = CarbonImmutable::create($year, 12, 31);

        $workplan = new Workplan([
            'title' => 'Annual Work Plan & Budget '.$year,
            'year' => $year,
            'year_basis' => 'calendar',
            'period_start' => $start,
            'period_end' => $end,
            'owner_id' => $staff['owner']->id,
            'narrative' => 'Delivery of the entity\'s capital and programme commitments for '.$year
                .', monitored quarterly against the results framework and reported through the M&E chain.',
            'created_by_id' => $staff['owner']->id,
        ]);

        $workplan->forceFill($this->chainStampsFor($status, $staff, $closed))->save();

        $this->ledgerFor($workplan, $status, $staff);
        $this->activitiesFor($workplan, $status, $staff, $entityIndex);
    }

    /**
     * @param  array{owner: User, approver: User}  $staff
     * @return array<string, mixed>
     */
    private function chainStampsFor(WorkplanStatus $status, array $staff, bool $closed): array
    {
        $stamps = ['status' => $status, 'status_changed_at' => CarbonImmutable::now()->subDays(5)];

        if ($status === WorkplanStatus::Draft) {
            return $stamps;
        }

        $stamps['submitted_by_id'] = $staff['owner']->id;
        $stamps['submitted_at'] = CarbonImmutable::now()->subDays(20);

        if ($status === WorkplanStatus::Submitted) {
            return $stamps;
        }

        $stamps['approved_by_id'] = $staff['approver']->id;
        $stamps['approved_at'] = CarbonImmutable::now()->subDays(15);
        $stamps['activated_at'] = CarbonImmutable::now()->subDays(14);

        if ($closed) {
            $stamps['closed_by_id'] = $staff['approver']->id;
            $stamps['closed_at'] = CarbonImmutable::now()->subDays(5);
        }

        return $stamps;
    }

    /**
     * The append-only chain ledger, so the plan's timeline has real history.
     *
     * @param  array{owner: User, approver: User}  $staff
     */
    private function ledgerFor(Workplan $workplan, WorkplanStatus $status, array $staff): void
    {
        $steps = [[null, WorkplanStatus::Draft, $staff['owner'], CarbonImmutable::now()->subDays(25)]];

        if ($status !== WorkplanStatus::Draft) {
            $steps[] = [WorkplanStatus::Draft, WorkplanStatus::Submitted, $staff['owner'], CarbonImmutable::now()->subDays(20)];
        }

        if (in_array($status, [WorkplanStatus::Approved, WorkplanStatus::Active, WorkplanStatus::Closed], true)) {
            $steps[] = [WorkplanStatus::Submitted, WorkplanStatus::Approved, $staff['approver'], CarbonImmutable::now()->subDays(15)];
            $steps[] = [WorkplanStatus::Approved, WorkplanStatus::Active, $staff['approver'], CarbonImmutable::now()->subDays(14)];
        }

        if ($status === WorkplanStatus::Closed) {
            $steps[] = [WorkplanStatus::Active, WorkplanStatus::Closed, $staff['approver'], CarbonImmutable::now()->subDays(5)];
        }

        foreach ($steps as [$from, $to, $actor, $at]) {
            $event = new WorkplanEvent([
                'workplan_id' => $workplan->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => null,
                'occurred_at' => $at,
            ]);
            $event->tenant_id = $workplan->tenant_id;
            $event->save();
        }
    }

    /**
     * The manual's Appendix A programme, with two lines deliberately left
     * without an output indicator so the warning has something to warn about.
     *
     * @param  array{owner: User, approver: User}  $staff
     */
    private function activitiesFor(Workplan $workplan, WorkplanStatus $status, array $staff, int $entityIndex): void
    {
        $indicators = Indicator::query()->where('is_active', true)->orderBy('id')->get();
        $project = Project::query()->orderBy('id')->first();
        $running = $status !== WorkplanStatus::Draft && $status !== WorkplanStatus::Submitted;

        $previous = null;

        foreach (WorkplanActivityFactory::MANUAL_ACTIVITIES as $index => $title) {
            $start = $workplan->period_start->addMonths($index);
            $end = $start->addMonths(1)->endOfMonth();

            if ($end->isAfter($workplan->period_end)) {
                $end = $workplan->period_end;
            }

            $activity = new WorkplanActivity([
                'workplan_id' => $workplan->id,
                // Two lines with no indicator, on purpose — the rule the
                // module exists to surface, visibly broken in the demo.
                'indicator_id' => in_array($index, [3, 7], true)
                    ? null
                    : $indicators->get($index % max(1, $indicators->count()))?->id,
                'project_id' => $index % 4 === 0 ? $project?->id : null,
                'position' => $index + 1,
                'title' => $title,
                'description' => 'Delivered by the M&E unit with the responsible directorate.',
                'owner_id' => $index % 2 === 0 ? $staff['owner']->id : $staff['approver']->id,
                'responsible_unit' => $index % 2 === 0 ? 'Monitoring & Evaluation Unit' : 'Planning, Research & Statistics',
                'planned_start' => $start,
                'planned_end' => $end,
                'budget_line' => '2202'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'budget_amount' => (string) (($index + 2) * 1500000).'.00',
                'weight' => $index === 3 ? 3 : 1,
                'depends_on_id' => $previous?->id,
                'created_by_id' => $staff['owner']->id,
            ]);

            $activity->forceFill($this->activityProgressFor($index, $start, $end, $running, $entityIndex))->save();

            $previous = $activity;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProgressFor(
        int $index,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $running,
        int $entityIndex,
    ): array {
        if (! $running) {
            return ['status' => ActivityStatus::NotStarted, 'progress_percent' => 0, 'expenditure_to_date' => '0.00'];
        }

        // A spread across every status the screens have to render — including
        // one genuinely delayed line per plan, because a Gantt where nothing
        // has slipped has never been looked at properly.
        return match (true) {
            $index < 3 => [
                'status' => ActivityStatus::Completed,
                'progress_percent' => 100,
                'actual_start' => $start,
                'actual_end' => $end,
                'expenditure_to_date' => (string) (($index + 2) * 1400000).'.00',
                'progress_recorded_at' => CarbonImmutable::now()->subDays(6),
            ],
            $index === 3 => [
                'status' => ActivityStatus::Delayed,
                'progress_percent' => 35 + ($entityIndex * 10),
                'actual_start' => $start,
                'planned_end' => CarbonImmutable::now()->subDays(12)->startOfDay(),
                'expenditure_to_date' => '2100000.00',
                'progress_recorded_at' => CarbonImmutable::now()->subDays(10),
            ],
            $index < 6 => [
                'status' => ActivityStatus::InProgress,
                'progress_percent' => 40 + ($index * 5),
                'actual_start' => $start,
                'expenditure_to_date' => '1200000.00',
                'progress_recorded_at' => CarbonImmutable::now()->subDays(3),
            ],
            $index === 9 => [
                'status' => ActivityStatus::Cancelled,
                'progress_percent' => 0,
                'expenditure_to_date' => '0.00',
            ],
            default => [
                'status' => ActivityStatus::NotStarted,
                'progress_percent' => 0,
                'expenditure_to_date' => '0.00',
            ],
        };
    }

    /**
     * The two people a plan needs: the M&E officer who owns it and the
     * administrator who signs it. Separate accounts on purpose — the approval
     * chokepoint refuses an approver who is the submitter, and a demo that
     * cannot demonstrate its own separation of duties is a poor demo.
     *
     * @return array{owner: User, approver: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $owner = $tenant->users()->whereHas('roles', fn ($q) => $q->where('name', Role::MeOfficer->value))->first()
            ?? $tenant->users()->first();

        $approver = $tenant->users()->whereHas('roles', fn ($q) => $q->where('name', Role::MdaAdmin->value))->first()
            ?? $owner;

        if ($owner === null || $approver === null) {
            return null;
        }

        return ['owner' => $owner, 'approver' => $approver];
    }
}
