<?php

namespace App\Actions\Oversight;

use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;

/**
 * The rewards-and-sanctions board (progress-reporting.md §3.1): for one
 * reporting window, how many returns each MDA owed, filed, filed on time and
 * missed — every MDA in ONE grouped query.
 *
 * NO PROGRESS REPORT ROW IS SCANNED to compute compliance. That is the whole
 * point of materializing obligations (§1.2): the aggregate is served by the
 * `(tenant_id, reporting_period_id, status)` index, so the board costs the same
 * with 40 MDAs and 80 000 obligations as with two.
 *
 * `CASE WHEN … THEN 1 END` rather than `SUM(boolean)`: MySQL is happy with
 * either, SQLite is not, and the test connection is SQLite.
 *
 * This is the module's ONLY sanctioned withoutTenancy() call site — cross-tenant
 * reads are an explicit oversight privilege, confined to app/Actions/Oversight
 * and enforced by the tenancy discipline test. Authority is read from the
 * GLOBAL team, so an MDA admin holds no compliance permission however the
 * request arrived.
 *
 * FULFILMENT IS MEASURED AT SUBMISSION, not approval (§3.1, flagged to the
 * domain expert as §9.1): the manual's sanctions ask whether the MDA filed on
 * time, and counting only approved returns would let a director's inaction
 * sanction their own MDA.
 */
class BuildComplianceLeagueTable
{
    public const TTL_SECONDS = 300;

    public static function cacheKey(int $reportingPeriodId): string
    {
        return "oversight:compliance:v1:period:{$reportingPeriodId}";
    }

    /**
     * @return array{
     *     period: array{id: int, code: string, label: string, due_at: string},
     *     tenants: list<array{tenant_id: int, name: string|null, slug: string|null, expected: int, submitted: int, on_time: int, missed: int, waived: int, compliance_rate: float|null, on_time_rate: float|null}>,
     *     totals: array{expected: int, submitted: int, on_time: int, missed: int, waived: int}
     * }
     */
    public function __invoke(User $actor, ReportingPeriod $period): array
    {
        if (! $actor->holdsGlobalPermission('oversight.compliance.view')) {
            throw new AuthorizationException('Viewing state-wide compliance requires oversight authority.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            self::cacheKey($period->id),
            self::TTL_SECONDS,
            fn (): array => $this->aggregate($period),
        );

        return $this->shape($period, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregate(ReportingPeriod $period): array
    {
        // Raw SQL: conditional aggregates have no Eloquent equivalent, and the
        // whole point of this Action is that the database does the counting.
        // No user input reaches the expression — the period is a binding.
        return ReportObligation::query()
            ->withoutTenancy()
            ->toBase()
            ->selectRaw(
                'tenant_id, '
                .'COUNT(*) as expected, '
                ."COUNT(CASE WHEN status = 'fulfilled' THEN 1 END) as submitted, "
                ."COUNT(CASE WHEN status = 'fulfilled' AND submitted_late = 0 THEN 1 END) as on_time, "
                ."COUNT(CASE WHEN status = 'missed' THEN 1 END) as missed, "
                ."COUNT(CASE WHEN status = 'waived' THEN 1 END) as waived"
            )
            ->where('reporting_period_id', $period->id)
            ->groupBy('tenant_id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     period: array{id: int, code: string, label: string, due_at: string},
     *     tenants: list<array{tenant_id: int, name: string|null, slug: string|null, expected: int, submitted: int, on_time: int, missed: int, waived: int, compliance_rate: float|null, on_time_rate: float|null}>,
     *     totals: array{expected: int, submitted: int, on_time: int, missed: int, waived: int}
     * }
     */
    private function shape(ReportingPeriod $period, array $rows): array
    {
        $names = Tenant::query()->get(['id', 'name', 'slug'])->keyBy('id');

        $tenants = [];
        $totals = ['expected' => 0, 'submitted' => 0, 'on_time' => 0, 'missed' => 0, 'waived' => 0];

        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            $expected = (int) $row['expected'];
            $submitted = (int) $row['submitted'];
            $onTime = (int) $row['on_time'];
            $missed = (int) $row['missed'];
            $waived = (int) $row['waived'];

            // The denominator excludes waived obligations: an MDA excused from
            // reporting must not be scored as though it failed to report.
            $scored = $expected - $waived;

            $tenants[] = [
                'tenant_id' => $tenantId,
                'name' => $names[$tenantId]->name ?? null,
                'slug' => $names[$tenantId]->slug ?? null,
                'expected' => $expected,
                'submitted' => $submitted,
                'on_time' => $onTime,
                'missed' => $missed,
                'waived' => $waived,
                'compliance_rate' => $scored > 0 ? round($submitted * 100 / $scored, 2) : null,
                'on_time_rate' => $scored > 0 ? round($onTime * 100 / $scored, 2) : null,
            ];

            $totals['expected'] += $expected;
            $totals['submitted'] += $submitted;
            $totals['on_time'] += $onTime;
            $totals['missed'] += $missed;
            $totals['waived'] += $waived;
        }

        // Best compliance first — the board is a ranking, and the ranking is
        // the sanction.
        usort($tenants, fn (array $a, array $b): int => ($b['on_time_rate'] ?? -1) <=> ($a['on_time_rate'] ?? -1));

        return [
            'period' => [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'due_at' => $period->due_at->toDateTimeString(),
            ],
            'tenants' => $tenants,
            'totals' => $totals,
        ];
    }
}
