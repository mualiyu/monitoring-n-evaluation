<?php

namespace Database\Factories;

use App\Enums\ReportingCadence;
use App\Models\ReportingPeriod;
use App\Support\InstanceTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Reporting periods are GLOBAL — this factory sets no tenant_id and needs no
 * bound tenant, unlike every other factory in the reporting module. That
 * asymmetry is the design (§1.1), not an oversight: the statutory calendar is
 * state-wide.
 *
 * The default is the current calendar month, due seven days after it ends —
 * the shape almost every test wants.
 *
 * @extends Factory<ReportingPeriod>
 */
class ReportingPeriodFactory extends Factory
{
    protected $model = ReportingPeriod::class;

    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return $this->window(ReportingCadence::Monthly, $start->year, $start->month);
    }

    /**
     * The calendar a dependent fixture should JOIN rather than duplicate.
     *
     * `reporting_periods` is global and uniquely keyed on (cadence,
     * period_start) — so two obligations in two different MDAs, both for
     * "March 2026", must reference ONE row. Every tenant-owned factory in this
     * module therefore resolves its window through here: the open monthly
     * window if the test has one, else the most recent, else a fresh one.
     * Fixtures that need a specific window pass it explicitly with
     * ->forPeriod().
     *
     * @return int|self the id of an existing window, or a factory for a new one
     */
    public static function currentOrNew(): int|self
    {
        $monthly = fn () => ReportingPeriod::query()
            ->where('cadence', ReportingCadence::Monthly)
            ->orderByDesc('period_start');

        return $monthly()->open()->first()->id
            ?? $monthly()->first()->id
            ?? self::new();
    }

    public function monthly(?int $year = null, ?int $month = null): static
    {
        $now = CarbonImmutable::now();

        return $this->state($this->window(
            ReportingCadence::Monthly,
            $year ?? $now->year,
            $month ?? $now->month,
        ));
    }

    public function quarterly(?int $year = null, int $quarter = 1): static
    {
        return $this->state($this->window(ReportingCadence::Quarterly, $year ?? CarbonImmutable::now()->year, $quarter));
    }

    /** The statutory six-monthly window: H1 is due 31 July, H2 is due 31 January. */
    public function biannual(?int $year = null, int $half = 1): static
    {
        return $this->state($this->window(ReportingCadence::Biannual, $year ?? CarbonImmutable::now()->year, $half));
    }

    public function annual(?int $year = null): static
    {
        return $this->state($this->window(ReportingCadence::Annual, $year ?? CarbonImmutable::now()->year, 1));
    }

    /**
     * A window whose deadline has already passed — the overdue fixture. The
     * period stays OPEN (closes_at null), because the instance accepts late
     * returns by default.
     */
    public function overdue(int $daysPastDue = 10): static
    {
        return $this->state(function () use ($daysPastDue): array {
            $start = CarbonImmutable::now()->subMonth()->startOfMonth();

            return [
                ...$this->window(ReportingCadence::Monthly, $start->year, $start->month),
                'due_at' => InstanceTime::endOfDay(CarbonImmutable::now()->subDays($daysPastDue)),
                'closes_at' => null,
            ];
        });
    }

    /** A hard-closed window — what reporting:close-periods sweeps into `missed`. */
    public function closed(int $daysAgo = 3): static
    {
        return $this->overdue($daysAgo + 7)->state([
            'closes_at' => InstanceTime::endOfDay(CarbonImmutable::now()->subDays($daysAgo)),
        ]);
    }

    /** A window that has not opened yet — submissions are refused. */
    public function upcoming(): static
    {
        return $this->state(function (): array {
            $start = CarbonImmutable::now()->addMonth()->startOfMonth();

            // The whole window moves, code and label included: the calendar is
            // uniquely keyed on (cadence, period_start), so a state that
            // shifted only the dates would collide with the current month.
            return $this->window(ReportingCadence::Monthly, $start->year, $start->month);
        });
    }

    public function dueIn(int $days): static
    {
        return $this->state([
            'due_at' => InstanceTime::endOfDay(CarbonImmutable::now()->addDays($days)),
        ]);
    }

    /**
     * A concrete window of a cadence, with codes and labels that match what
     * GenerateReportingPeriods would produce — so a factory-built calendar and
     * a generated one are interchangeable in a test.
     *
     * @return array<string, mixed>
     */
    private function window(ReportingCadence $cadence, int $year, int $ordinal): array
    {
        $start = $cadence->startOfPeriod($year, $ordinal);
        $end = $cadence->endOfPeriod($year, $ordinal);

        return [
            'code' => $cadence->codeFor($year, $ordinal),
            'cadence' => $cadence,
            'label' => $cadence->labelFor($year, $ordinal),
            // Dates carry the state's calendar date; instants are stored in
            // UTC — the same split GenerateReportingPeriods makes.
            'period_start' => $start,
            'period_end' => $end,
            'opens_at' => $start->utc(),
            'due_at' => InstanceTime::endOfDay($end->addDays(7)),
            'closes_at' => null,
            'generated_by' => 'factory',
        ];
    }
}
