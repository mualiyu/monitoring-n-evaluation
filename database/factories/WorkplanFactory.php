<?php

namespace Database\Factories;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Work plans are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * `status` and the whole approval chain are deliberately not fillable —
 * TransitionWorkplanStatus is their only writer in application code.
 * Factories run unguarded, which is the point: a fixture may state where a
 * record IS, while only an Action may move it there.
 *
 * @extends Factory<Workplan>
 */
class WorkplanFactory extends Factory
{
    protected $model = Workplan::class;

    public function definition(): array
    {
        $year = (int) CarbonImmutable::now()->year;

        return [
            'title' => 'Annual Work Plan & Budget '.$year,
            'year' => $year,
            'year_basis' => 'calendar',
            'period_start' => CarbonImmutable::create($year, 1, 1),
            'period_end' => CarbonImmutable::create($year, 12, 31),
            'owner_id' => User::factory(),
            'status' => WorkplanStatus::Draft,
            'narrative' => 'Delivery of the entity\'s capital and programme commitments for the year, '
                .'with quarterly review against the results framework.',
            'created_by_id' => User::factory(),
            'submitted_by_id' => null,
            'submitted_at' => null,
            'approved_by_id' => null,
            'approved_at' => null,
            'rejected_by_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'activated_at' => null,
            'closed_by_id' => null,
            'closed_at' => null,
            'status_changed_at' => null,
        ];
    }

    public function forYear(int $year, string $basis = 'calendar'): static
    {
        return $this->state([
            'title' => 'Annual Work Plan & Budget '.$year,
            'year' => $year,
            'year_basis' => $basis,
            'period_start' => $basis === 'financial'
                ? CarbonImmutable::create($year, 4, 1)
                : CarbonImmutable::create($year, 1, 1),
            'period_end' => $basis === 'financial'
                ? CarbonImmutable::create($year + 1, 3, 31)
                : CarbonImmutable::create($year, 12, 31),
        ]);
    }

    /** A plan whose period has not begun — the only state that refuses activation. */
    public function future(): static
    {
        $year = (int) CarbonImmutable::now()->addYear()->year;

        return $this->forYear($year);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(['owner_id' => $owner->id]);
    }

    public function by(User $author): static
    {
        return $this->state(['created_by_id' => $author->id]);
    }

    public function draft(): static
    {
        return $this->state(['status' => WorkplanStatus::Draft]);
    }

    public function submitted(?User $submitter = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkplanStatus::Submitted,
            'submitted_by_id' => $submitter->id ?? $attributes['created_by_id'],
            'submitted_at' => CarbonImmutable::now()->subDays(3),
            'status_changed_at' => CarbonImmutable::now()->subDays(3),
        ]);
    }

    public function approved(?User $approver = null): static
    {
        return $this->submitted()->state([
            'status' => WorkplanStatus::Approved,
            'approved_by_id' => $approver->id ?? User::factory(),
            'approved_at' => CarbonImmutable::now()->subDay(),
            'status_changed_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function active(?User $approver = null): static
    {
        return $this->approved($approver)->state([
            'status' => WorkplanStatus::Active,
            'activated_at' => CarbonImmutable::now(),
            'status_changed_at' => CarbonImmutable::now(),
        ]);
    }

    public function rejected(string $reason = 'The training budget is not reconciled with the appropriation — revise and resubmit.'): static
    {
        return $this->submitted()->state([
            'status' => WorkplanStatus::Rejected,
            'rejected_by_id' => User::factory(),
            'rejected_at' => CarbonImmutable::now(),
            'rejection_reason' => $reason,
            'status_changed_at' => CarbonImmutable::now(),
        ]);
    }

    public function closed(): static
    {
        return $this->active()->state([
            'status' => WorkplanStatus::Closed,
            'closed_by_id' => User::factory(),
            'closed_at' => CarbonImmutable::now(),
            'status_changed_at' => CarbonImmutable::now(),
        ]);
    }
}
