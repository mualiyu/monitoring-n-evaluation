<?php

namespace Database\Factories;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\EvaluationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: never sets tenant_id. Run inside a bound tenant context.
 *
 * The ledger is append-only and the model refuses updates and deletes, so a
 * fixture may only ever CREATE rows here — which is exactly the constraint the
 * real Action works under.
 *
 * @extends Factory<EvaluationEvent>
 */
class EvaluationEventFactory extends Factory
{
    protected $model = EvaluationEvent::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'from_status' => null,
            'to_status' => EvaluationStatus::Planned,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forEvaluation(Evaluation $evaluation): static
    {
        return $this->state(['evaluation_id' => $evaluation->id]);
    }

    public function step(EvaluationStatus $from, EvaluationStatus $to, ?User $actor = null): static
    {
        return $this->state(array_filter([
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
        ], fn (mixed $value): bool => $value !== null));
    }
}
