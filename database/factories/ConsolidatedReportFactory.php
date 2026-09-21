<?php

namespace Database\Factories;

use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use App\Enums\ReportingCadence;
use App\Models\ConsolidatedReport;
use App\Models\ReportingPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Consolidations are GLOBAL — this factory sets no tenant_id and needs no
 * bound tenant. That asymmetry is the design (see the model), not an
 * oversight: a roll-up spans every MDA by definition.
 *
 * `status` and the whole chain are deliberately not fillable —
 * App\Actions\Consolidation\TransitionConsolidationStatus is their only writer
 * in application code. Factories run unguarded, which is the point: a fixture
 * may state where a record IS, while only the chokepoint may move it there.
 *
 * ⚠ The chain states below stamp the columns WITHOUT writing ledger rows, so
 * a test that needs the history (or needs the separation guard to bite) must
 * drive the Actions rather than jump straight to ->inReview(). Use
 * ->compiledBy() when you want the guard to see a compiler.
 *
 * @extends Factory<ConsolidatedReport>
 */
class ConsolidatedReportFactory extends Factory
{
    protected $model = ConsolidatedReport::class;

    public function definition(): array
    {
        $type = ConsolidatedReportType::AnnualApr;

        return [
            // Unique by column, and the reference a real OpenConsolidation
            // would mint is derived from the window — so fixtures take a
            // random suffix rather than colliding with a generated one.
            'reference' => 'APR-'.Str::upper(Str::random(10)),
            'title' => $type->label().' — fixture window',
            'type' => $type,
            'status' => ConsolidationStatus::Draft,
            'reporting_period_id' => self::annualWindow(),
            'snapshot' => null,
            'snapshot_taken_at' => null,
            'totals' => null,
            'entity_count' => 0,
            'denominator' => 0,
            'created_by_id' => User::factory(),
            'compiled_by_id' => null,
            'compiled_at' => null,
            'submitted_by_id' => null,
            'submitted_at' => null,
            'approved_by_id' => null,
            'approved_at' => null,
            'published_by_id' => null,
            'published_at' => null,
            'returned_by_id' => null,
            'returned_at' => null,
            'return_reason' => null,
        ];
    }

    /**
     * The annual calendar a dependent fixture should JOIN rather than
     * duplicate — `reporting_periods` is global and uniquely keyed on
     * (cadence, period_start), so two APR fixtures in one test must reference
     * ONE row. Mirrors ReportingPeriodFactory::currentOrNew().
     *
     * @return int|ReportingPeriodFactory the id of an existing window, or a factory for a new one
     */
    public static function annualWindow(): int|ReportingPeriodFactory
    {
        $existing = ReportingPeriod::query()
            ->where('cadence', ReportingCadence::Annual)
            ->orderByDesc('period_start')
            ->first();

        return $existing->id ?? ReportingPeriodFactory::new()->annual();
    }

    public function forPeriod(ReportingPeriod $period): static
    {
        return $this->state(['reporting_period_id' => $period->id]);
    }

    public function ofType(ConsolidatedReportType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'title' => $type->label().' — fixture window',
            'reference' => Str::upper(Str::substr($type->value, 0, 3)).'-'.Str::upper(Str::random(10)),
        ]);
    }

    public function openedBy(User $user): static
    {
        return $this->state(['created_by_id' => $user->id]);
    }

    public function draft(): static
    {
        return $this->state(['status' => ConsolidationStatus::Draft]);
    }

    /**
     * Figures pulled, narrative being written. The roll-up counters are set
     * because `hasFigures()` is what the chokepoint checks before review — a
     * "compiling" fixture with a zero entity count is a draft wearing the
     * wrong label.
     */
    public function compiling(int $entities = 2, int $denominator = 2): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ConsolidationStatus::Compiling,
            'entity_count' => $entities,
            'denominator' => $denominator,
            'totals' => ['entities_reporting' => $entities, 'projects_total' => 0],
            'compiled_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    /** Who the separation guard will see as the compiler. */
    public function compiledBy(User $user): static
    {
        return $this->state([
            'compiled_by_id' => $user->id,
            'compiled_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function inReview(?User $submitter = null): static
    {
        return $this->compiling()->state([
            'status' => ConsolidationStatus::InReview,
            'submitted_by_id' => $submitter->id ?? User::factory(),
            'submitted_at' => CarbonImmutable::now()->subHours(6),
        ]);
    }

    /**
     * Signed — and therefore FROZEN. The snapshot is what makes this state
     * meaningful: an "approved" fixture with no snapshot is the one thing the
     * publish guard refuses, so the fixture carries one.
     */
    public function approved(?User $approver = null): static
    {
        return $this->inReview()->state(fn (array $attributes): array => [
            'status' => ConsolidationStatus::Approved,
            'approved_by_id' => $approver->id ?? User::factory(),
            'approved_at' => CarbonImmutable::now()->subHour(),
            'snapshot_taken_at' => CarbonImmutable::now()->subHour(),
            'snapshot' => [
                'version' => 1,
                'taken_at' => CarbonImmutable::now()->subHour()->toIso8601String(),
                'totals' => $attributes['totals'] ?? [],
                'entities' => [],
                'sections' => [],
                'entity_count' => $attributes['entity_count'] ?? 0,
                'denominator' => $attributes['denominator'] ?? 0,
            ],
        ]);
    }

    public function published(?User $publisher = null): static
    {
        return $this->approved()->state([
            'status' => ConsolidationStatus::Published,
            'published_by_id' => $publisher->id ?? User::factory(),
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    /** Sent back to the desk with a reason — where a return lands. */
    public function returned(string $reason = 'The compliance chapter does not explain the two entities that filed nothing.'): static
    {
        return $this->compiling()->state([
            'status' => ConsolidationStatus::Compiling,
            'returned_by_id' => User::factory(),
            'returned_at' => CarbonImmutable::now()->subHour(),
            'return_reason' => $reason,
        ]);
    }
}
