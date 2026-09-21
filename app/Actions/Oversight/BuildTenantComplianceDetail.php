<?php

namespace App\Actions\Oversight;

use App\Enums\ReportObligationStatus;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The league-table drill-down (progress-reporting.md §3.1 → §6,
 * `/compliance/{tenant}`): ONE entity's reporting record, window by window.
 *
 * The board answers "which MDA is behind"; this answers the next question a
 * secretariat asks — "behind since when, and on what". Same source of truth as
 * the board (materialized obligations, never a scan of progress reports), same
 * definitions (fulfilment at SUBMISSION, waived obligations out of the base),
 * so a drill-down can never contradict the row that led to it.
 *
 * One grouped query for the whole history: `(tenant_id, reporting_period_id,
 * status)` serves it, and an MDA has tens of windows, not thousands.
 * `CASE WHEN … THEN 1 END` rather than `SUM(boolean)` — MySQL accepts either,
 * SQLite (the test connection) does not.
 *
 * Like every cross-tenant read in this module the bypass lives here, beside
 * the authority check that justifies it, and authority is read from the GLOBAL
 * permission team.
 */
class BuildTenantComplianceDetail
{
    /**
     * One row per reporting window this entity has ever owed a return for,
     * newest first.
     *
     * @return array{
     *     tenant: array{id: int, name: string, slug: string},
     *     periods: list<array{period_id: int, code: string, label: string, cadence: string, due_at: CarbonImmutable, expected: int, submitted: int, on_time: int, late: int, missed: int, waived: int, pending: int, overdue: int, compliance_rate: float|null, on_time_rate: float|null}>,
     *     totals: array{expected: int, submitted: int, on_time: int, late: int, missed: int, waived: int, pending: int, overdue: int, compliance_rate: float|null, on_time_rate: float|null}
     * }
     */
    public function __invoke(User $actor, Tenant $tenant, int $windows = 12): array
    {
        $this->authorize($actor);

        /** @var list<array<string, mixed>> $rows */
        $rows = app(CurrentTenant::class)->bypass(fn (): array => $this->aggregate($tenant));

        return $this->shape($tenant, $rows, $windows);
    }

    /**
     * The per-project detail behind ONE of those windows — the "and on what"
     * half of the question. Paginated: a large ministry owes a return for
     * every project under execution, every month.
     *
     * @return LengthAwarePaginator<int, ReportObligation>
     */
    public function obligations(User $actor, Tenant $tenant, ReportingPeriod $period, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => ReportObligation::query()
                ->whereBelongsTo($tenant)
                ->whereBelongsTo($period, 'reportingPeriod')
                ->with([
                    'project:id,ulid,title,reference,physical_progress',
                    'progressReport:id,ulid,status,submitted_at',
                ])
                // Outstanding first, then by deadline: the rows that explain
                // the score are the ones that were never filed.
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ReportObligationStatus::Pending->value])
                ->orderBy('due_at')
                ->orderBy('id')
                ->paginate($perPage),
        );
    }

    private function authorize(User $actor): void
    {
        if (! $actor->holdsGlobalPermission('oversight.compliance.view')) {
            throw new AuthorizationException('Viewing an entity’s compliance record requires oversight authority.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregate(Tenant $tenant): array
    {
        $now = CarbonImmutable::now();

        // Raw SQL: conditional aggregates have no Eloquent equivalent, and the
        // point of this Action is that the database does the counting. No user
        // input reaches the expression — the entity, the statuses and "now"
        // are all bindings.
        return ReportObligation::query()
            ->whereBelongsTo($tenant)
            ->toBase()
            ->selectRaw('reporting_period_id')
            ->selectRaw('COUNT(*) as expected')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as submitted', [ReportObligationStatus::Fulfilled->value])
            ->selectRaw('COUNT(CASE WHEN status = ? AND submitted_late = 0 THEN 1 END) as on_time', [ReportObligationStatus::Fulfilled->value])
            ->selectRaw('COUNT(CASE WHEN status = ? AND submitted_late = 1 THEN 1 END) as late', [ReportObligationStatus::Fulfilled->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as missed', [ReportObligationStatus::Missed->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as waived', [ReportObligationStatus::Waived->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as pending', [ReportObligationStatus::Pending->value])
            ->selectRaw('COUNT(CASE WHEN status = ? AND due_at < ? THEN 1 END) as overdue', [ReportObligationStatus::Pending->value, $now])
            ->groupBy('reporting_period_id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     tenant: array{id: int, name: string, slug: string},
     *     periods: list<array{period_id: int, code: string, label: string, cadence: string, due_at: CarbonImmutable, expected: int, submitted: int, on_time: int, late: int, missed: int, waived: int, pending: int, overdue: int, compliance_rate: float|null, on_time_rate: float|null}>,
     *     totals: array{expected: int, submitted: int, on_time: int, late: int, missed: int, waived: int, pending: int, overdue: int, compliance_rate: float|null, on_time_rate: float|null}
     * }
     */
    private function shape(Tenant $tenant, array $rows, int $windows): array
    {
        // The calendar is global and unscoped — no bypass needed to read it.
        $periods = ReportingPeriod::query()
            ->whereIn('id', array_map(fn (array $row): int => (int) $row['reporting_period_id'], $rows))
            ->get()
            ->keyBy('id');

        $shaped = [];
        $totals = [
            'expected' => 0, 'submitted' => 0, 'on_time' => 0, 'late' => 0,
            'missed' => 0, 'waived' => 0, 'pending' => 0, 'overdue' => 0,
        ];

        foreach ($rows as $row) {
            $period = $periods->get((int) $row['reporting_period_id']);

            if (! $period instanceof ReportingPeriod) {
                continue;
            }

            $counts = [
                'expected' => (int) $row['expected'],
                'submitted' => (int) $row['submitted'],
                'on_time' => (int) $row['on_time'],
                'late' => (int) $row['late'],
                'missed' => (int) $row['missed'],
                'waived' => (int) $row['waived'],
                'pending' => (int) $row['pending'],
                'overdue' => (int) $row['overdue'],
            ];

            $shaped[] = [
                'period_id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'cadence' => $period->cadence->value,
                'due_at' => $period->due_at,
                ...$counts,
                ...$this->rates($counts),
            ];

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
        }

        // Newest window first, and only as many as the screen asked for: a
        // drill-down is a recent record, not an archive dump. Ordered by the
        // window's START, not its deadline — a quarterly and a monthly window
        // can share a due date, and "which period" is the calendar fact.
        usort($shaped, function (array $a, array $b) use ($periods): int {
            $left = $periods->get($a['period_id']);
            $right = $periods->get($b['period_id']);

            return ($right?->period_start->getTimestamp() ?? 0) <=> ($left?->period_start->getTimestamp() ?? 0);
        });

        return [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'periods' => array_slice($shaped, 0, max(1, $windows)),
            'totals' => [...$totals, ...$this->rates($totals)],
        ];
    }

    /**
     * Rates over the SCORED base — expected minus waived. An entity excused
     * from reporting must not be scored as though it failed to report, which
     * is the same rule the board applies.
     *
     * @param  array{expected: int, submitted: int, on_time: int, waived: int}  $counts
     * @return array{compliance_rate: float|null, on_time_rate: float|null}
     */
    private function rates(array $counts): array
    {
        $scored = $counts['expected'] - $counts['waived'];

        return [
            'compliance_rate' => $scored > 0 ? round($counts['submitted'] * 100 / $scored, 2) : null,
            'on_time_rate' => $scored > 0 ? round($counts['on_time'] * 100 / $scored, 2) : null,
        ];
    }
}
