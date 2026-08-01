<?php

namespace Database\Factories;

use App\Enums\IndicatorReadingStatus;
use App\Enums\ReadingSourceType;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory). Defaults to a
 * draft reading: submission, validation and publication are separate acts with
 * separate actors.
 *
 * @extends Factory<IndicatorReading>
 */
class IndicatorReadingFactory extends Factory
{
    protected $model = IndicatorReading::class;

    public function definition(): array
    {
        $start = Carbon::now()->subQuarter()->startOfQuarter();

        return [
            'indicator_id' => Indicator::factory()->active(),
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfQuarter()->toDateString(),
            'actual_value' => fake()->numberBetween(1, 4000).'.0000',
            'source_type' => ReadingSourceType::Primary,
            'collection_method' => 'Field verification by the assigned monitor.',
            'notes' => null,
            'status' => IndicatorReadingStatus::Draft,
            'submitted_by_id' => null,
            'submitted_at' => null,
            'validated_by_id' => null,
            'validated_at' => null,
        ];
    }

    public function submitted(?User $by = null): static
    {
        return $this->state([
            'status' => IndicatorReadingStatus::Submitted,
            'submitted_by_id' => $by === null ? User::factory() : $by->id,
            'submitted_at' => now()->subWeeks(2),
        ]);
    }

    /**
     * Validation is a separate act by a separate actor — the data-quality
     * reviewer, never the submitter.
     */
    public function validated(?User $by = null): static
    {
        return $this->submitted()->state([
            'status' => IndicatorReadingStatus::Validated,
            'validated_by_id' => $by === null ? User::factory() : $by->id,
            'validated_at' => now()->subWeek(),
        ]);
    }

    public function secondarySource(): static
    {
        return $this->state([
            'source_type' => ReadingSourceType::Secondary,
            'collection_method' => 'Extracted from the MDA administrative dataset.',
        ]);
    }

    public function forIndicator(Indicator $indicator): static
    {
        return $this->state(['indicator_id' => $indicator->id]);
    }
}
