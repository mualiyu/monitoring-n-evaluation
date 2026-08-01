<?php

namespace App\Actions\Oversight;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;

/**
 * The state-wide portfolio board: every MDA, every status, in ONE grouped
 * query (design §6). Not one query per card — a board with eight cards and
 * twelve MDAs is otherwise ninety-six queries for a page a governor's office
 * refreshes all morning.
 *
 * This and ListProjectsAcrossTenants are the only sanctioned withoutTenancy()
 * call sites in the module: cross-tenant reads are an explicit oversight
 * privilege, confined to app/Actions/Oversight (rules/tenancy.md, enforced by
 * the discipline test).
 *
 * Cached for five minutes and busted by the ProjectStatusChanged listener, so
 * the number that matters most — how many projects are in what state — is
 * never stale after a transition.
 */
class BuildPortfolioSummary
{
    public const CACHE_KEY = 'oversight:portfolio:v1';

    public const TTL_SECONDS = 300;

    /**
     * @return array{
     *     tenants: list<array<string, mixed>>,
     *     by_status: array<string, int>,
     *     totals: array{project_count: int, contract_value_total: Money, expenditure_total: Money}
     * }
     */
    public function __invoke(User $actor): array
    {
        // Read from the GLOBAL team: oversight authority is never granted
        // inside an MDA workspace, so an MDA admin can hold no portfolio
        // permission however the request arrived.
        if (! $actor->holdsGlobalPermission('oversight.portfolio.view')) {
            throw new AuthorizationException('Viewing the state portfolio requires oversight authority.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): array => $this->aggregate());

        return $this->shape($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregate(): array
    {
        // Raw SQL: aggregate functions have no Eloquent equivalent, and the
        // whole point of this Action is that the database does the grouping.
        // No user input reaches the expression — it is a constant.
        return Project::query()
            ->withoutTenancy()
            ->toBase()
            ->selectRaw(
                'tenant_id, status, COUNT(*) as project_count, '
                .'SUM(contract_value_total) as contract_value_total, '
                .'SUM(expenditure_to_date) as expenditure_total, '
                .'AVG(physical_progress) as average_physical_progress'
            )
            ->groupBy('tenant_id', 'status')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     tenants: list<array<string, mixed>>,
     *     by_status: array<string, int>,
     *     totals: array{project_count: int, contract_value_total: Money, expenditure_total: Money}
     * }
     */
    private function shape(array $rows): array
    {
        $names = Tenant::query()->get(['id', 'name', 'slug'])->keyBy('id');

        $byStatus = array_fill_keys(
            array_column(ProjectStatus::cases(), 'value'),
            0,
        );

        $tenants = [];
        $totalCount = 0;
        $totalValue = Money::zero();
        $totalSpend = Money::zero();

        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            $status = (string) $row['status'];
            $count = (int) $row['project_count'];
            $value = $this->money($row['contract_value_total']);
            $spend = $this->money($row['expenditure_total']);

            $tenants[$tenantId] ??= [
                'tenant_id' => $tenantId,
                'name' => $names[$tenantId]->name ?? null,
                'slug' => $names[$tenantId]->slug ?? null,
                'project_count' => 0,
                'contract_value_total' => Money::zero(),
                'expenditure_total' => Money::zero(),
                'by_status' => $byStatus,
            ];

            $tenants[$tenantId]['project_count'] += $count;
            $tenants[$tenantId]['contract_value_total'] = $tenants[$tenantId]['contract_value_total']->plus($value);
            $tenants[$tenantId]['expenditure_total'] = $tenants[$tenantId]['expenditure_total']->plus($spend);
            $tenants[$tenantId]['by_status'][$status] = ($tenants[$tenantId]['by_status'][$status] ?? 0) + $count;

            $byStatus[$status] = ($byStatus[$status] ?? 0) + $count;
            $totalCount += $count;
            $totalValue = $totalValue->plus($value);
            $totalSpend = $totalSpend->plus($spend);
        }

        return [
            'tenants' => array_values($tenants),
            'by_status' => $byStatus,
            'totals' => [
                'project_count' => $totalCount,
                'contract_value_total' => $totalValue,
                'expenditure_total' => $totalSpend,
            ],
        ];
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
