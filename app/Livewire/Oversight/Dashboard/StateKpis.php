<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Dashboard;

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Actions\Oversight\BuildPortfolioSummary;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The state dashboard's KPI row: entities reporting, projects under
 * monitoring, portfolio value, reporting compliance.
 *
 * It replaces four honest zeros with four honest figures. Every cross-MDA read
 * happens inside an Action under app/Actions/Oversight — the only sanctioned
 * place for a tenancy bypass — and each of those Actions re-checks its own
 * oversight permission in the GLOBAL team before bypassing anything.
 *
 * An ExecutiveViewer without portfolio authority gets an empty row rather than
 * an exception: this is the landing page of the surface, and the screens
 * behind each tile do their own refusing.
 */
class StateKpis extends Component
{
    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $portfolio = (new BuildPortfolioSummary)($user);
        } catch (AuthorizationException) {
            return null;
        }

        $reporting = $this->reportingFigures($user);

        return [
            'tenant_count' => Tenant::query()->where('is_active', true)->count(),
            // "Reporting" means a workspace that actually has something under
            // monitoring — a provisioned but empty MDA is not yet reporting,
            // and counting it would flatter the denominator.
            'tenants_reporting' => count(array_filter(
                $portfolio['tenants'],
                fn (array $row): bool => ($row['project_count'] ?? 0) > 0,
            )),
            'project_count' => $portfolio['totals']['project_count'],
            'contract_value' => $portfolio['totals']['contract_value_total'],
            'expenditure' => $portfolio['totals']['expenditure_total'],
            ...$reporting,
        ];
    }

    /**
     * Compliance for the most recent period whose window has closed. The open
     * period is deliberately skipped: an MDA with three days left to file is
     * not non-compliant, and a tile that says so teaches people to ignore it.
     *
     * @return array{period: ReportingPeriod|null, compliance_rate: float|null, on_time_rate: float|null}
     */
    private function reportingFigures(User $user): array
    {
        $period = ReportingPeriod::query()
            ->where('due_at', '<', now())
            ->orderByDesc('due_at')
            ->first();

        if (! $period instanceof ReportingPeriod) {
            return ['period' => null, 'compliance_rate' => null, 'on_time_rate' => null];
        }

        try {
            $table = (new BuildComplianceLeagueTable)($user, $period);
        } catch (AuthorizationException) {
            return ['period' => $period, 'compliance_rate' => null, 'on_time_rate' => null];
        }

        $scored = $table['totals']['expected'] - $table['totals']['waived'];

        return [
            'period' => $period,
            'compliance_rate' => $scored > 0 ? round($table['totals']['submitted'] * 100 / $scored, 1) : null,
            'on_time_rate' => $scored > 0 ? round($table['totals']['on_time'] * 100 / $scored, 1) : null,
        ];
    }

    public function money(mixed $value): string
    {
        return $value instanceof Money ? $value->format() : '—';
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.oversight.dashboard.state-kpis');
    }
}
