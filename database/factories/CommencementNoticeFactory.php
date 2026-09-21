<?php

namespace Database\Factories;

use App\Enums\CommencementNoticeStatus;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Commencement notices are tenant-owned: this factory NEVER sets tenant_id.
 * Run it inside a bound tenant context (`actingOnTenant()` in tests) and
 * BelongsToTenant fills it — an unbound factory throws, which is the intended
 * teaching moment.
 *
 * `status` and the whole service chain are deliberately not fillable; the
 * Actions in App\Actions\Lifecycle are their only writers in application code.
 * Factories run unguarded, which is the point: a fixture may state where a
 * record IS, while only an Action may move it there.
 *
 * The default row derives its project and contractor FROM the contract it
 * serves, so a fixture can never describe a notice that belongs to one
 * project and points at another's award.
 *
 * @extends Factory<CommencementNotice>
 */
class CommencementNoticeFactory extends Factory
{
    protected $model = CommencementNotice::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            // Resolved from the contract above — closures receive the
            // attributes expanded so far, and `contract_id` precedes these.
            'project_id' => fn (array $attributes): int => $this->fromContract($attributes, 'project_id'),
            'contractor_id' => fn (array $attributes): int => $this->fromContract($attributes, 'contractor_id'),
            'reference' => 'CTR-'.fake()->unique()->numerify('#####').'/CN',
            'status' => CommencementNoticeStatus::Pending,
            'scope_of_works' => 'Construction, completion and maintenance of the works described in the contract documents.',
            'contract_sum' => fake()->numberBetween(40, 850).'000000.00',
            'duration_days' => fake()->randomElement([180, 270, 365, 540]),
            'commencement_date' => CarbonImmutable::now()->subMonths(5)->toDateString(),
            'expected_completion_date' => CarbonImmutable::now()->addMonths(6)->toDateString(),
            'supervising_agency_name' => null,
            'instructions' => null,
            // Award + the statutory window. A fixture supplies its own,
            // because the column is not nullable.
            'due_at' => CarbonImmutable::now()->subMonths(6)->addDays(3)->toDateString(),
            'issued_late' => false,
            'overdue_notified_at' => null,
            'issued_by_id' => null,
            'issued_at' => null,
            'acknowledged_by_id' => null,
            'acknowledged_by_contractor_at' => null,
            'acknowledgement_note' => null,
            'created_by_id' => User::factory(),
        ];
    }

    /** Awaiting service — the state the award itself creates. */
    public function pending(): static
    {
        return $this->state([
            'status' => CommencementNoticeStatus::Pending,
            'issued_by_id' => null,
            'issued_at' => null,
            'issued_late' => false,
        ]);
    }

    /** Served on the contractor. */
    public function issued(): static
    {
        return $this->state([
            'status' => CommencementNoticeStatus::Issued,
            'issued_by_id' => User::factory(),
            'issued_at' => CarbonImmutable::now()->subMonths(5),
        ]);
    }

    /** Served, and receipt confirmed. */
    public function acknowledged(): static
    {
        return $this->issued()->state([
            'status' => CommencementNoticeStatus::Acknowledged,
            'acknowledged_by_id' => User::factory(),
            'acknowledged_by_contractor_at' => CarbonImmutable::now()->subMonths(5)->addDays(2),
            'acknowledgement_note' => 'Signed hard copy returned by the site engineer.',
        ]);
    }

    /** Unserved, with the statutory window already closed. */
    public function overdue(int $daysLate = 5): static
    {
        return $this->pending()->state([
            'due_at' => CarbonImmutable::now()->subDays($daysLate)->toDateString(),
            'overdue_notified_at' => null,
        ]);
    }

    /** Served, but after the window closed. */
    public function late(): static
    {
        return $this->issued()->state([
            'due_at' => CarbonImmutable::now()->subMonths(5)->subDays(10)->toDateString(),
            'issued_late' => true,
        ]);
    }

    /** Already announced to the entity administrator — the sweep's idempotency gate. */
    public function alreadyFlagged(): static
    {
        return $this->state(['overdue_notified_at' => CarbonImmutable::now()->subDay()]);
    }

    public function forContract(Contract $contract): static
    {
        return $this->state([
            'contract_id' => $contract->id,
            'project_id' => $contract->project_id,
            'contractor_id' => $contract->contractor_id,
            'contract_sum' => $contract->sum->toDecimalString(),
            'due_at' => $contract->award_date->addDays(3)->toDateString(),
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fromContract(array $attributes, string $column): int
    {
        $contractId = $attributes['contract_id'] ?? null;

        return (int) Contract::query()->whereKey($contractId)->value($column);
    }
}
