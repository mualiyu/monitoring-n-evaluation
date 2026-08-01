<?php

namespace Database\Factories;

use App\Enums\FundingSourceType;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\FundingSource;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\Sector;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Projects are tenant-owned: this factory NEVER sets tenant_id. Run it inside
 * a bound tenant context (`app(CurrentTenant::class)->runAs($tenant, ...)` or
 * the `actingOnTenant()` test helper) and BelongsToTenant fills it — an
 * unbound factory throws, which is the intended teaching moment.
 *
 * Money attributes are decimal strings ("45000000.00"). Every derived figure
 * below is computed with integer arithmetic: no float touches a naira.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $budgetNaira = fake()->numberBetween(40, 850) * 1_000_000;

        return [
            'reference' => 'PRJ-'.fake()->unique()->numerify('#####'),
            'title' => fake()->randomElement(['Construction of', 'Rehabilitation of', 'Upgrade of', 'Expansion of'])
                .' '.fake()->randomElement([
                    'Township Roads', 'Primary Health Centre', 'Rural Water Scheme',
                    'Model Secondary School', 'Storm Drainage Network', 'Cold Chain Facility',
                    'Bridge Approach Works', 'Maternity Wing',
                ]).' — Phase '.fake()->numberBetween(1, 3),
            'description' => fake()->paragraph(),
            'goal' => 'Improve service delivery for the benefiting communities.',
            'objectives' => "Deliver the works to specification.\nComplete within the approved schedule and budget.",
            'sector_id' => Sector::factory(),
            'type' => ProjectType::Capital,
            'status' => ProjectStatus::Draft,
            'supervising_agency_id' => null,
            'supervising_agency_name' => null,
            'budget_allocation' => $budgetNaira.'.00',
            'budget_code' => 'BC-'.fake()->numerify('####'),
            'contract_value_total' => null,
            'expenditure_to_date' => '0.00',
            'physical_progress' => '0.00',
            'start_date' => null,
            'expected_end_date' => null,
            'revised_end_date' => null,
            'actual_end_date' => null,
            'post_completion_review_due_at' => null,
            'mid_term_flagged_at' => null,
            'reporting_frequency' => null,     // null = the instance default
            'created_by_id' => User::factory(),
            'manager_id' => null,
            'status_changed_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status' => ProjectStatus::Draft,
            'contract_value_total' => null,
            'expenditure_to_date' => '0.00',
            'physical_progress' => '0.00',
            'start_date' => now()->addMonth()->toDateString(),
            'expected_end_date' => now()->addMonths(13)->toDateString(),
            'status_changed_at' => null,
        ]);
    }

    public function awarded(): static
    {
        return $this->state([
            ...$this->financials(0),
            'status' => ProjectStatus::Awarded,
            'start_date' => now()->subWeek()->toDateString(),
            'expected_end_date' => now()->addMonths(11)->toDateString(),
            'status_changed_at' => now()->subWeek(),
        ]);
    }

    public function mobilized(): static
    {
        return $this->state([
            ...$this->financials(5),
            'status' => ProjectStatus::Mobilized,
            'start_date' => now()->subMonth()->toDateString(),
            'expected_end_date' => now()->addMonths(10)->toDateString(),
            'status_changed_at' => now()->subWeek(),
        ]);
    }

    /** Work under way, 10–80% attested physical progress. */
    public function ongoing(): static
    {
        $percent = fake()->numberBetween(10, 80);

        return $this->state([
            ...$this->financials($percent),
            'status' => ProjectStatus::InProgress,
            'start_date' => now()->subMonths(5)->toDateString(),
            'expected_end_date' => now()->addMonths(7)->toDateString(),
            'status_changed_at' => now()->subMonths(4),
            // Mid-term evaluation is an event, not a status: past the default
            // 50% trigger the flag is already raised.
            'mid_term_flagged_at' => $percent >= 50 ? now()->subMonths(2) : null,
        ]);
    }

    /** In progress, past its end date, not finished — the overdue flag case. */
    public function behindSchedule(): static
    {
        return $this->state([
            ...$this->financials(fake()->numberBetween(20, 70)),
            'status' => ProjectStatus::InProgress,
            'start_date' => now()->subMonths(18)->toDateString(),
            'expected_end_date' => now()->subMonths(2)->toDateString(),
            'status_changed_at' => now()->subMonths(16),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            ...$this->financials(100),
            'status' => ProjectStatus::Completed,
            'start_date' => now()->subMonths(14)->toDateString(),
            'expected_end_date' => now()->subMonths(2)->toDateString(),
            'actual_end_date' => now()->subMonth()->toDateString(),
            'post_completion_review_due_at' => now()->addMonths(5)->toDateString(),
            'mid_term_flagged_at' => now()->subMonths(8),
            'status_changed_at' => now()->subMonth(),
        ]);
    }

    /**
     * Completion certificate issued; the post-completion review clock runs
     * (window from `post_completion_review_months`, instance-configurable).
     */
    public function certified(): static
    {
        return $this->completed()->state([
            'status' => ProjectStatus::Certified,
            'status_changed_at' => now()->subWeeks(2),
        ]);
    }

    /**
     * Post-completion monitoring concluded. The review date is in the past —
     * TransitionProjectStatus refuses `→ closed` before it without a
     * StateAdmin override.
     */
    public function closed(): static
    {
        return $this->certified()->state([
            'status' => ProjectStatus::Closed,
            'start_date' => now()->subMonths(26)->toDateString(),
            'expected_end_date' => now()->subMonths(14)->toDateString(),
            'actual_end_date' => now()->subMonths(13)->toDateString(),
            'post_completion_review_due_at' => now()->subMonths(7)->toDateString(),
            'status_changed_at' => now()->subMonths(6),
        ]);
    }

    public function suspended(): static
    {
        return $this->state([
            ...$this->financials(fake()->numberBetween(15, 60)),
            'status' => ProjectStatus::Suspended,
            'start_date' => now()->subMonths(8)->toDateString(),
            'expected_end_date' => now()->addMonths(2)->toDateString(),
            'status_changed_at' => now()->subMonths(2),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => ProjectStatus::Cancelled,
            'status_changed_at' => now()->subMonths(3),
        ]);
    }

    /**
     * Several sites across several LGAs — the case that makes any LGA
     * aggregate count DISTINCT project_id or lie.
     */
    public function multiSite(int $sites = 3): static
    {
        return $this->afterCreating(function (Project $project) use ($sites): void {
            foreach (range(1, $sites) as $index) {
                $lga = Lga::query()->inRandomOrder()->first() ?? Lga::factory()->create();

                ProjectLocation::factory()->create([
                    'project_id' => $project->id,
                    'lga_id' => $lga->id,
                    'site_name' => 'Site '.$index,
                    'is_primary' => $index === 1,
                ]);
            }
        });
    }

    /**
     * Donor money plus the state's counterpart contribution — near-universal
     * on multilateral projects, and the reason funding is a pivot.
     */
    public function coFunded(int $donorPercent = 70): static
    {
        return $this->afterCreating(function (Project $project) use ($donorPercent): void {
            $allocation = $project->budget_allocation;

            $splits = [
                [FundingSourceType::Donor, $donorPercent, true],
                [FundingSourceType::Counterpart, 100 - $donorPercent, false],
            ];

            foreach ($splits as [$type, $percent, $isPrimary]) {
                $source = FundingSource::query()->where('type', $type)->first()
                    ?? FundingSource::factory()->ofType($type)->create();

                ProjectFundingSource::factory()->create([
                    'project_id' => $project->id,
                    'funding_source_id' => $source->id,
                    'amount' => $allocation === null
                        ? null
                        : Money::fromMinor(intdiv($allocation->minor() * $percent, 100)),
                    'percentage' => $percent.'.00',
                    'is_primary' => $isPrimary,
                ]);
            }
        });
    }

    public function ofType(ProjectType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function managedBy(User $manager): static
    {
        return $this->state(['manager_id' => $manager->id]);
    }

    /**
     * Contract value, allocation and expenditure consistent with a given
     * physical percentage — integer naira throughout.
     *
     * @return array<string, string>
     */
    private function financials(int $physicalPercent): array
    {
        $totalNaira = fake()->numberBetween(40, 850) * 1_000_000;

        return [
            'contract_value_total' => $totalNaira.'.00',
            'budget_allocation' => intdiv($totalNaira * 110, 100).'.00',
            'expenditure_to_date' => intdiv($totalNaira * $physicalPercent, 100).'.00',
            'physical_progress' => $physicalPercent.'.00',
        ];
    }
}
