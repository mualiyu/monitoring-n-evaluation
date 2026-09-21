<?php

namespace Database\Factories;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ledger rows are tenant-owned and append-only. The model refuses updates and
 * deletes, so a factory state is the only way a test can stage history.
 *
 * @extends Factory<WorkplanEvent>
 */
class WorkplanEventFactory extends Factory
{
    protected $model = WorkplanEvent::class;

    public function definition(): array
    {
        return [
            'workplan_id' => Workplan::factory(),
            'from_status' => null,
            'to_status' => WorkplanStatus::Draft,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forWorkplan(Workplan $workplan): static
    {
        return $this->state(['workplan_id' => $workplan->id]);
    }

    public function step(WorkplanStatus $from, WorkplanStatus $to, ?string $reason = null): static
    {
        return $this->state([
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
        ]);
    }
}
