<?php

namespace App\Actions\Oversight;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * THE cross-MDA roll-up behind a state consolidation: for one reporting
 * window, every entity's portfolio position, statutory compliance, filed
 * returns and indicator performance, in one array the compiler can write
 * straight into consolidated_report_entries.
 *
 * COMPOSED, NOT REIMPLEMENTED. The headline figures come from the very Actions
 * the dashboards call —
 *
 *   • portfolio counts and money  → BuildPortfolioSummary (the /portfolio board)
 *   • compliance counts           → BuildComplianceLeagueTable (the /compliance board)
 *   • indicator achievement       → AggregateForIndicatorPerformance, folded
 *
 * — because a state report that contradicts the screen it was compiled from is
 * worse than no report. Only two figures are computed here, and only because
 * no dashboard publishes them: the returns actually filed for the window, and
 * average physical progress per entity.
 *
 * STOCK vs FLOW, said out loud. Portfolio figures are a STOCK measure read as
 * at the compile ("where the register stands"); compliance, returns and
 * readings are FLOW measures bounded by the window ("what happened in it").
 * The snapshot frozen at approval is what makes the stock measure honest after
 * the fact — see ApproveConsolidation.
 *
 * Cross-tenant reads are an explicit oversight privilege, confined to this
 * directory (rules/tenancy.md, enforced by the discipline test). Authority is
 * read from the GLOBAL permission team, so an MDA admin holds nothing here
 * however the request arrived.
 */
class AggregateForConsolidation
{
    /** Returns that count as filed — a draft is not a return (§ListReportsAcrossTenants). */
    private const FILED_STATUSES = [
        ProgressReportStatus::Submitted,
        ProgressReportStatus::Reviewed,
        ProgressReportStatus::Approved,
    ];

    /**
     * @return array{
     *     period: array{id: int, code: string, label: string, due_at: string},
     *     entities: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     denominator: int,
     *     compiled_at: string
     * }
     */
    public function __invoke(User $actor, ReportingPeriod $period): array
    {
        if (! $actor->holdsGlobalPermission('oversight.consolidation.manage')) {
            throw new AuthorizationException('Compiling a state consolidation requires secretariat authority.');
        }

        $portfolio = app(BuildPortfolioSummary::class)($actor);
        $compliance = app(BuildComplianceLeagueTable::class)($actor, $period);
        $returns = $this->returnsForPeriod($period);
        $progress = $this->averagePhysicalProgress();
        $indicators = $this->indicatorCounts($actor, $period);

        /** @var array<int, Tenant> $tenants */
        $tenants = app(CurrentTenant::class)->bypass(
            fn (): array => Tenant::query()->orderBy('name')->get()->keyBy('id')->all(),
        );

        $entities = [];

        foreach ($tenants as $tenantId => $tenant) {
            $entity = $this->blank($tenantId, $tenant);

            foreach ($portfolio['tenants'] as $row) {
                if ((int) $row['tenant_id'] === $tenantId) {
                    $entity['projects_total'] = (int) $row['project_count'];
                    $entity['projects_by_status'] = $row['by_status'];
                    $entity['contract_value_total'] = $row['contract_value_total'];
                    $entity['expenditure_total'] = $row['expenditure_total'];
                }
            }

            foreach ($compliance['tenants'] as $row) {
                if ((int) $row['tenant_id'] === $tenantId) {
                    $entity['obligations_expected'] = (int) $row['expected'];
                    $entity['obligations_submitted'] = (int) $row['submitted'];
                    $entity['obligations_on_time'] = (int) $row['on_time'];
                    $entity['obligations_missed'] = (int) $row['missed'];
                    $entity['obligations_waived'] = (int) $row['waived'];
                }
            }

            $entity['reports_filed'] = (int) ($returns[$tenantId]['filed'] ?? 0);
            $entity['reports_approved'] = (int) ($returns[$tenantId]['approved'] ?? 0);
            $entity['period_expenditure_total'] = $returns[$tenantId]['period_expenditure'] ?? Money::zero();
            $entity['physical_progress_avg'] = $progress[$tenantId] ?? null;
            $entity = [...$entity, ...($indicators[$tenantId] ?? $this->blankIndicators())];

            $entities[$tenantId] = $entity;
        }

        return [
            'period' => $compliance['period'],
            'entities' => $entities,
            'totals' => $this->totals($entities),
            // Every active entity is expected to appear in a state roll-up:
            // the denominator is the question "who did not answer", and an
            // entity silently absent from the list is exactly the finding the
            // consolidation exists to surface.
            'denominator' => count(array_filter($tenants, fn (Tenant $tenant): bool => $tenant->is_active)),
            'compiled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(int $tenantId, Tenant $tenant): array
    {
        return [
            'subject_tenant_id' => $tenantId,
            'entity' => $tenant->name,
            'entity_slug' => $tenant->slug,
            'is_active' => (bool) $tenant->is_active,
            'projects_total' => 0,
            'projects_by_status' => [],
            'contract_value_total' => Money::zero(),
            'expenditure_total' => Money::zero(),
            'physical_progress_avg' => null,
            'obligations_expected' => 0,
            'obligations_submitted' => 0,
            'obligations_on_time' => 0,
            'obligations_missed' => 0,
            'obligations_waived' => 0,
            'reports_filed' => 0,
            'reports_approved' => 0,
            'period_expenditure_total' => Money::zero(),
            ...$this->blankIndicators(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function blankIndicators(): array
    {
        return [
            'indicators_reported' => 0,
            'indicators_on_track' => 0,
            'indicators_at_risk' => 0,
            'indicators_off_track' => 0,
            'readings_validated' => 0,
        ];
    }

    /**
     * Returns actually filed for the window, per entity. One grouped query —
     * the figure no dashboard publishes, and the only progress-report scan in
     * the whole compile.
     *
     * @return array<int, array{filed: int, approved: int, period_expenditure: Money}>
     */
    private function returnsForPeriod(ReportingPeriod $period): array
    {
        /** @var list<object> $rows */
        $rows = app(CurrentTenant::class)->bypass(fn (): array => ProgressReport::query()
            ->withoutTenancy()
            ->toBase()
            // Raw SQL: conditional aggregates have no Eloquent equivalent, and
            // the whole point is that the database does the counting. The one
            // user-free value in the expression is bound, not interpolated.
            ->selectRaw(
                'tenant_id, COUNT(*) as filed, '
                .'COUNT(CASE WHEN status = ? THEN 1 END) as approved, '
                .'SUM(period_expenditure) as period_expenditure',
                [ProgressReportStatus::Approved->value],
            )
            ->where('reporting_period_id', $period->id)
            ->whereIn('status', array_map(
                fn (ProgressReportStatus $status): string => $status->value,
                self::FILED_STATUSES,
            ))
            ->groupBy('tenant_id')
            ->get()
            ->all());

        $byTenant = [];

        foreach ($rows as $row) {
            $byTenant[(int) $row->tenant_id] = [
                'filed' => (int) $row->filed,
                'approved' => (int) $row->approved,
                'period_expenditure' => $this->money($row->period_expenditure),
            ];
        }

        return $byTenant;
    }

    /**
     * Average physical progress per entity — a stock measure, as at the
     * compile. The second figure no dashboard publishes.
     *
     * @return array<int, string>
     */
    private function averagePhysicalProgress(): array
    {
        /** @var list<object> $rows */
        $rows = app(CurrentTenant::class)->bypass(fn (): array => Project::query()
            ->withoutTenancy()
            ->toBase()
            // Constant expression, no user input.
            ->selectRaw('tenant_id, AVG(physical_progress) as physical_progress_avg')
            ->groupBy('tenant_id')
            ->get()
            ->all());

        $byTenant = [];

        foreach ($rows as $row) {
            if ($row->physical_progress_avg !== null) {
                $byTenant[(int) $row->tenant_id] = sprintf('%.2F', (float) $row->physical_progress_avg);
            }
        }

        return $byTenant;
    }

    /**
     * Per-entity indicator counts, folded from the per-reading rows
     * AggregateForIndicatorPerformance produces. A GROUP BY done in PHP over a
     * list the builder also prints — deliberately, so the counts can never
     * disagree with the list that explains them.
     *
     * @return array<int, array<string, int>>
     */
    private function indicatorCounts(User $actor, ReportingPeriod $period): array
    {
        $counts = [];

        app(AggregateForIndicatorPerformance::class)->chunk(
            $actor,
            $period,
            [],
            function (array $rows) use (&$counts): bool {
                foreach ($rows as $row) {
                    $tenantId = (int) $row['tenant_id'];
                    $counts[$tenantId] ??= $this->blankIndicators();

                    if ($row['is_validated']) {
                        $counts[$tenantId]['readings_validated']++;
                    }

                    // An unvalidated reading is reported but not counted when
                    // the instance requires data-quality review — which is the
                    // whole point of having a reviewer role.
                    if (! $row['counts_towards_achievement']) {
                        continue;
                    }

                    $counts[$tenantId]['indicators_reported']++;

                    match ($row['band']) {
                        AggregateForIndicatorPerformance::BAND_ON_TRACK => $counts[$tenantId]['indicators_on_track']++,
                        AggregateForIndicatorPerformance::BAND_AT_RISK => $counts[$tenantId]['indicators_at_risk']++,
                        AggregateForIndicatorPerformance::BAND_OFF_TRACK => $counts[$tenantId]['indicators_off_track']++,
                        default => null, // no target set — reported, unscored
                    };
                }

                return true;
            },
        );

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entities
     * @return array<string, mixed>
     */
    private function totals(array $entities): array
    {
        $totals = [
            'entities_reporting' => 0,
            'projects_total' => 0,
            'projects_by_status' => [],
            'contract_value_total' => Money::zero(),
            'expenditure_total' => Money::zero(),
            'obligations_expected' => 0,
            'obligations_submitted' => 0,
            'obligations_on_time' => 0,
            'obligations_missed' => 0,
            'obligations_waived' => 0,
            'reports_filed' => 0,
            'reports_approved' => 0,
            'period_expenditure_total' => Money::zero(),
            'indicators_reported' => 0,
            'indicators_on_track' => 0,
            'indicators_at_risk' => 0,
            'indicators_off_track' => 0,
            'readings_validated' => 0,
        ];

        foreach ($entities as $entity) {
            // "Reporting" means the entity put something into this window —
            // an entity with a register but no return is not a reporting
            // entity, and counting it as one would flatter the coverage rate.
            if ($entity['reports_filed'] > 0 || $entity['obligations_submitted'] > 0) {
                $totals['entities_reporting']++;
            }

            foreach (['projects_total', 'obligations_expected', 'obligations_submitted',
                'obligations_on_time', 'obligations_missed', 'obligations_waived',
                'reports_filed', 'reports_approved', 'indicators_reported',
                'indicators_on_track', 'indicators_at_risk', 'indicators_off_track',
                'readings_validated'] as $counter) {
                $totals[$counter] += $entity[$counter];
            }

            foreach (['contract_value_total', 'expenditure_total', 'period_expenditure_total'] as $sum) {
                $totals[$sum] = $totals[$sum]->plus($entity[$sum]);
            }

            foreach ($entity['projects_by_status'] as $status => $count) {
                $totals['projects_by_status'][$status] = ($totals['projects_by_status'][$status] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }

    /**
     * SUM() over a DECIMAL column comes back as a string on MySQL and a float
     * on SQLite. The float stops here, at the boundary, exactly as MoneyCast
     * does it for a column read.
     */
    private function money(mixed $value): Money
    {
        return match (true) {
            $value === null => Money::zero(),
            is_float($value) => Money::fromDecimalString(sprintf('%.2F', $value)),
            default => Money::fromDecimalString((string) $value),
        };
    }
}
