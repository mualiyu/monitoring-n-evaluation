<?php

namespace Database\Factories;

use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory). Values are decimal
 * strings: an indicator reading is a measurement, and measurements do not go
 * through floats.
 *
 * The default state is a DRAFT indicator with a complete baseline but
 * `is_active = false` — activation is an explicit act (ActivateIndicator), and
 * only active indicators accept readings.
 *
 * @extends Factory<Indicator>
 */
class IndicatorFactory extends Factory
{
    protected $model = Indicator::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'result_framework_id' => null,
            'tier' => 'output',
            'name' => fake()->randomElement([
                'Kilometres of road rehabilitated',
                'Number of facilities equipped',
                'Households with improved water access',
                'Classrooms completed and handed over',
                'Beneficiaries reached',
            ]),
            'definition' => fake()->sentence(14),
            'unit' => IndicatorUnit::Number,
            'measurement_frequency' => MeasurementFrequency::Quarterly,
            'data_source' => fake()->randomElement([
                'Site inspection reports', 'MDA administrative records',
                'Contractor progress returns',
            ]),
            'means_of_verification' => 'Signed inspection report with photographic evidence.',
            'responsible_collector_id' => null,
            'responsible_collector_text' => 'M&E Officer',
            'baseline_value' => '0.0000',
            'baseline_date' => now()->subYear()->toDateString(),
            'baseline_source' => 'Project appraisal document',
            'target_type' => TargetType::Continuous,
            'smart_justification' => 'Specific to the delivered works, measurable from inspection records, time-bound to the contract period.',
            'is_active' => false,
            'activated_at' => null,
            'created_by_id' => User::factory(),
        ];
    }

    /** Activated: baseline complete, so readings may be recorded against it. */
    public function active(): static
    {
        return $this->state([
            'is_active' => true,
            'activated_at' => now()->subMonths(6),
        ]);
    }

    /**
     * The half-drafted case ActivateIndicator must refuse — an explicit null
     * baseline, never a fabricated zero.
     */
    public function withoutBaseline(): static
    {
        return $this->state([
            'baseline_value' => null,
            'baseline_date' => null,
            'baseline_source' => null,
            'is_active' => false,
            'activated_at' => null,
        ]);
    }

    public function percentage(): static
    {
        return $this->state([
            'unit' => IndicatorUnit::Percentage,
            'target_type' => TargetType::PercentageAchievement,
        ]);
    }

    public function timeBound(): static
    {
        return $this->state(['target_type' => TargetType::TimeBound]);
    }

    public function oneOff(): static
    {
        return $this->state([
            'unit' => IndicatorUnit::OneOff,
            'measurement_frequency' => MeasurementFrequency::OneOff,
        ]);
    }

    /** A project-development-objective level indicator (framework tier). */
    public function pdo(): static
    {
        return $this->state(['tier' => 'pdo']);
    }

    /** An MDA-programme indicator, belonging to no single project. */
    public function programmeLevel(): static
    {
        return $this->state(['project_id' => null]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    public function collectedBy(User $user): static
    {
        return $this->state([
            'responsible_collector_id' => $user->id,
            'responsible_collector_text' => null,
        ]);
    }
}
