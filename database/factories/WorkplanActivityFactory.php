<?php

namespace Database\Factories;

use App\Enums\ActivityScheduleGranularity;
use App\Enums\ActivityStatus;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Activities are tenant-owned: this factory NEVER sets tenant_id.
 *
 * Titles come from the manual's own implementation plan (ondo-manual-digest
 * §6, Appendix A) rather than from faker sentences — a demo plan that reads
 * like a real Annual Work Plan is the one that surfaces layout problems.
 *
 * Money attributes are decimal strings ("2500000.00"), never floats.
 *
 * @extends Factory<WorkplanActivity>
 */
class WorkplanActivityFactory extends Factory
{
    protected $model = WorkplanActivity::class;

    /** The manual's Appendix A programme, in order. */
    public const MANUAL_ACTIVITIES = [
        'Design the programme logic and theory of change',
        'Identify and map stakeholders',
        'Develop indicators, baselines and targets',
        'Conduct implementation monitoring field visits',
        'Collect and validate routine monitoring data',
        'Prepare and review periodic progress reports',
        'Commission the mid-term evaluation',
        'Hold quarterly M&E review meetings',
        'Disseminate findings and publish the annual report',
        'Run the Q4 indicator review retreat',
    ];

    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfYear()->addMonths(fake()->numberBetween(0, 8));

        return [
            'workplan_id' => Workplan::factory(),
            'project_id' => null,
            'indicator_id' => null,
            'position' => 0,
            'title' => fake()->randomElement(self::MANUAL_ACTIVITIES),
            'description' => 'Delivered by the M&E unit with the responsible directorate, '
                .'against the output indicator named on this line.',
            'owner_id' => null,
            'responsible_unit' => fake()->randomElement([
                'Planning, Research & Statistics', 'Monitoring & Evaluation Unit',
                'Works & Infrastructure', 'Finance & Accounts',
            ]),
            'schedule_granularity' => ActivityScheduleGranularity::Month,
            'planned_start' => $start,
            'planned_end' => $start->addMonths(2)->endOfMonth(),
            'actual_start' => null,
            'actual_end' => null,
            'budget_line' => fake()->numerify('22020###'),
            'budget_amount' => fake()->numberBetween(1, 25).'500000.00',
            'expenditure_to_date' => '0.00',
            'weight' => 1,
            'progress_percent' => 0,
            'status' => ActivityStatus::NotStarted,
            'depends_on_id' => null,
            'progress_recorded_at' => null,
            'overdue_notified_at' => null,
            'created_by_id' => User::factory(),
        ];
    }

    /**
     * Pins the activity inside its plan's period — a schedule outside it is
     * exactly what AddWorkplanActivity refuses, so a fixture must not create
     * one by accident.
     */
    public function forWorkplan(Workplan $workplan, ?int $position = null): static
    {
        return $this->state(function (array $attributes) use ($workplan, $position): array {
            $start = $workplan->period_start;
            $end = $start->addMonths(2);

            return [
                'workplan_id' => $workplan->id,
                'position' => $position ?? 0,
                'planned_start' => $start,
                'planned_end' => $end->isAfter($workplan->period_end) ? $workplan->period_end : $end,
            ];
        });
    }

    public function scheduled(CarbonImmutable $start, CarbonImmutable $end): static
    {
        return $this->state(['planned_start' => $start, 'planned_end' => $end]);
    }

    public function weekly(): static
    {
        return $this->state(['schedule_granularity' => ActivityScheduleGranularity::Week]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(['owner_id' => $owner->id]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    /** The manual's rule satisfied: the activity delivers an output indicator. */
    public function withIndicator(?Indicator $indicator = null): static
    {
        return $this->state([
            'indicator_id' => $indicator->id ?? Indicator::factory(),
        ]);
    }

    /** The manual's rule BROKEN — the state every screen has to warn about. */
    public function withoutIndicator(): static
    {
        return $this->state(['indicator_id' => null]);
    }

    public function weighted(int $weight): static
    {
        return $this->state(['weight' => $weight]);
    }

    public function dependsOn(WorkplanActivity $predecessor): static
    {
        return $this->state(['depends_on_id' => $predecessor->id]);
    }

    public function notStarted(): static
    {
        return $this->state([
            'status' => ActivityStatus::NotStarted,
            'progress_percent' => 0,
            'actual_start' => null,
            'actual_end' => null,
        ]);
    }

    public function inProgress(int $percent = 45): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActivityStatus::InProgress,
            'progress_percent' => $percent,
            'actual_start' => $attributes['planned_start'],
            'actual_end' => null,
            'expenditure_to_date' => '1200000.00',
            'progress_recorded_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActivityStatus::Completed,
            'progress_percent' => 100,
            'actual_start' => $attributes['planned_start'],
            'actual_end' => $attributes['planned_end'],
            'expenditure_to_date' => $attributes['budget_amount'],
            'progress_recorded_at' => CarbonImmutable::now()->subDays(2),
        ]);
    }

    /** Past its planned end and unfinished — what the overdue sweep picks up. */
    public function delayed(int $percent = 20): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActivityStatus::Delayed,
            'progress_percent' => $percent,
            'planned_start' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
            'planned_end' => CarbonImmutable::now()->subDays(10)->startOfDay(),
            'actual_start' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
            'actual_end' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => ActivityStatus::Cancelled,
            'progress_percent' => 0,
            'actual_end' => null,
        ]);
    }
}
