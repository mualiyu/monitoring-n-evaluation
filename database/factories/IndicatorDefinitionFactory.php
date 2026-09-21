<?php

namespace Database\Factories;

use App\Enums\IndicatorTier;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\IndicatorDefinition;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * GLOBAL reference data — no tenant_id, and deliberately so (see the model).
 * A definition is a template: it carries no baseline, no target and no
 * reading, because those are each MDA's own.
 *
 * @extends Factory<IndicatorDefinition>
 */
class IndicatorDefinitionFactory extends Factory
{
    protected $model = IndicatorDefinition::class;

    public function definition(): array
    {
        return [
            'code' => mb_strtoupper(fake()->unique()->bothify('???-###')),
            'name' => fake()->randomElement([
                'Kilometres of road rehabilitated and handed over',
                'Classrooms completed and in use',
                'Households with a functioning water connection',
                'Primary health facilities meeting the minimum equipment standard',
                'Beneficiaries reached by the programme',
            ]),
            'definition' => 'Counted from signed handover certificates; partial works are excluded until handover.',
            'focus' => 'Service delivery',
            'sector_id' => null,
            'unit' => IndicatorUnit::Number,
            'default_measurement_frequency' => MeasurementFrequency::Quarterly,
            'default_target_type' => TargetType::Continuous,
            'default_tier' => IndicatorTier::Output,
            'data_source' => 'Entity administrative records and site inspection reports',
            'means_of_verification' => 'Signed handover certificate with photographic evidence.',
            'responsible_collector_text' => 'M&E Department',
            'smart_statement' => 'Specific to delivered works, measurable from handover records, time-bound to the plan period.',
            'is_active' => true,
            'created_by_id' => null,
        ];
    }

    /** Out of circulation: readable, quotable, but never instantiated again. */
    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function forSector(Sector $sector): static
    {
        return $this->state(['sector_id' => $sector->id]);
    }

    public function percentage(): static
    {
        return $this->state([
            'unit' => IndicatorUnit::Percentage,
            'default_target_type' => TargetType::PercentageAchievement,
        ]);
    }

    /** An outcome-level measure the state reads once a year. */
    public function outcomeLevel(): static
    {
        return $this->state([
            'default_tier' => IndicatorTier::Intermediate,
            'default_measurement_frequency' => MeasurementFrequency::Annual,
        ]);
    }

    public function objectiveLevel(): static
    {
        return $this->state([
            'default_tier' => IndicatorTier::Pdo,
            'default_measurement_frequency' => MeasurementFrequency::Annual,
        ]);
    }

    public function withCode(string $code): static
    {
        return $this->state(['code' => mb_strtoupper($code)]);
    }
}
