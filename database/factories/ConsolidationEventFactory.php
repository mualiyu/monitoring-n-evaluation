<?php

namespace Database\Factories;

use App\Enums\ConsolidationStatus;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A row of the append-only chain ledger. GLOBAL, like its parent.
 *
 * ⚠ In application code this table is written ONLY by
 * App\Actions\Consolidation\TransitionConsolidationStatus (and the opening row
 * by OpenConsolidation). A test that cares about the chain should drive those
 * Actions; this factory exists for screens that need a history to render
 * without a full lifecycle behind it.
 *
 * @extends Factory<ConsolidationEvent>
 */
class ConsolidationEventFactory extends Factory
{
    protected $model = ConsolidationEvent::class;

    public function definition(): array
    {
        return [
            'consolidated_report_id' => ConsolidatedReport::factory(),
            'from_status' => ConsolidationStatus::Draft,
            'to_status' => ConsolidationStatus::Compiling,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forReport(ConsolidatedReport $report): static
    {
        return $this->state(['consolidated_report_id' => $report->id]);
    }

    public function by(User $actor): static
    {
        return $this->state(['actor_id' => $actor->id]);
    }

    /** The opening row: nothing preceded it, hence the null. */
    public function opening(): static
    {
        return $this->state([
            'from_status' => null,
            'to_status' => ConsolidationStatus::Draft,
        ]);
    }

    public function transition(?ConsolidationStatus $from, ConsolidationStatus $to): static
    {
        return $this->state(['from_status' => $from, 'to_status' => $to]);
    }

    /** A return — the only move that carries a mandatory reason. */
    public function returned(string $reason = 'The compliance chapter does not explain the two entities that filed nothing.'): static
    {
        return $this->state([
            'from_status' => ConsolidationStatus::InReview,
            'to_status' => ConsolidationStatus::Compiling,
            'reason' => $reason,
        ]);
    }

    public function at(CarbonImmutable $moment): static
    {
        return $this->state(['occurred_at' => $moment]);
    }
}
