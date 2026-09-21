<?php

namespace Database\Factories;

use App\Enums\IndicatorReadingStatus;
use App\Models\IndicatorReading;
use App\Models\IndicatorReadingEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory).
 *
 * In production these rows are written ONLY by
 * App\Actions\Indicators\TransitionIndicatorReadingStatus. The factory exists
 * so a test can assert the ledger's append-only guarantee without driving the
 * whole chain to get one row.
 *
 * @extends Factory<IndicatorReadingEvent>
 */
class IndicatorReadingEventFactory extends Factory
{
    protected $model = IndicatorReadingEvent::class;

    public function definition(): array
    {
        return [
            'indicator_reading_id' => IndicatorReading::factory(),
            'from_status' => null,
            'to_status' => IndicatorReadingStatus::Draft,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => now(),
        ];
    }

    public function transition(IndicatorReadingStatus $from, IndicatorReadingStatus $to): static
    {
        return $this->state(['from_status' => $from, 'to_status' => $to]);
    }

    public function forReading(IndicatorReading $reading): static
    {
        return $this->state(['indicator_reading_id' => $reading->id]);
    }
}
