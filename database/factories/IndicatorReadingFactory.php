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
            'recorded_by_id' => User::factory(),
            'status' => IndicatorReadingStatus::Draft,
            'submitted_by_id' => null,
            'submitted_at' => null,
            'validated_by_id' => null,
            'validated_at' => null,
            'published_by_id' => null,
            'rejected_by_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ];
    }

    /**
     * A working note nobody has filed yet — the factory's default, named
     * explicitly so a lifecycle dataset can say which state it means rather
     * than relying on the reader knowing what the default is.
     */
    public function draft(): static
    {
        return $this->state([
            'status' => IndicatorReadingStatus::Draft,
            'submitted_by_id' => null,
            'submitted_at' => null,
            'validated_by_id' => null,
            'validated_at' => null,
            'published_by_id' => null,
            'published_at' => null,
        ]);
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

    /**
     * Published: quotable outside the platform, and terminal — a published
     * figure is corrected by recording another reading, never by editing it.
     */
    public function published(?User $by = null): static
    {
        return $this->validated()->state([
            'status' => IndicatorReadingStatus::Published,
            'published_by_id' => $by === null ? User::factory() : $by->id,
            'published_at' => now()->subDays(3),
        ]);
    }

    /** Sent back to the person who measured it, with a reason on the record. */
    public function rejected(?User $by = null): static
    {
        return $this->state([
            'status' => IndicatorReadingStatus::Draft,
            'submitted_by_id' => User::factory(),
            'submitted_at' => now()->subWeeks(2),
            'rejected_by_id' => $by === null ? User::factory() : $by->id,
            'rejected_at' => now()->subWeek(),
            'rejection_reason' => 'The figure counts households reached rather than households with a working connection.',
        ]);
    }

    /** Measured by a specific person — the identity the separation guard weighs. */
    public function recordedBy(User $user): static
    {
        return $this->state(['recorded_by_id' => $user->id]);
    }

    public function forPeriod(string $start, string $end): static
    {
        return $this->state(['period_start' => $start, 'period_end' => $end]);
    }

    public function ofValue(string $value): static
    {
        return $this->state(['actual_value' => $value]);
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
