<?php

namespace Database\Factories;

use App\Enums\MeasurementFrequency;
use App\Models\Indicator;
use App\Models\IndicatorTarget;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory).
 *
 * @extends Factory<IndicatorTarget>
 */
class IndicatorTargetFactory extends Factory
{
    protected $model = IndicatorTarget::class;

    public function definition(): array
    {
        $start = Carbon::now()->startOfYear();

        return [
            'indicator_id' => Indicator::factory(),
            'period_type' => MeasurementFrequency::Annual,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfYear()->toDateString(),
            'target_value' => fake()->numberBetween(10, 5000).'.0000',
            'notes' => null,
        ];
    }

    /** One quarter of the current year (1–4). */
    public function quarter(int $quarter): static
    {
        $start = Carbon::now()->startOfYear()->addMonths(($quarter - 1) * 3);

        return $this->state([
            'period_type' => MeasurementFrequency::Quarterly,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->addMonths(3)->subDay()->toDateString(),
        ]);
    }

    public function ofValue(string $value): static
    {
        return $this->state(['target_value' => $value]);
    }

    public function forIndicator(Indicator $indicator): static
    {
        return $this->state(['indicator_id' => $indicator->id]);
    }
}
