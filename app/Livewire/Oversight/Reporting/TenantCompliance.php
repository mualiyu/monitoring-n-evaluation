<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Reporting;

use App\Actions\Oversight\BuildTenantComplianceDetail;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One entity's compliance record (progress-reporting.md §6,
 * `/compliance/{tenant}`) — the league table's drill-down.
 *
 * The board ranks; this explains the rank. Window by window: what was owed,
 * what was filed, how much of it on time, what was missed outright and what
 * was excused — and then, for whichever window is open, the individual
 * projects behind those numbers.
 *
 * Bound by SLUG. The board links here with `route()`, so a wrong binding key
 * fails at generation instead of 404-ing every row at click time — which is
 * exactly how the portfolio drill-down shipped broken once, with a tenant_id
 * handed to a `{tenant:slug}` binding.
 *
 * Both reads go through BuildTenantComplianceDetail, which re-checks
 * `oversight.compliance.view` in the GLOBAL permission team before bypassing
 * tenancy. This component never bypasses tenancy itself.
 */
#[Layout('layouts::oversight')]
class TenantCompliance extends Component
{
    use WithPagination;

    public Tenant $tenant;

    /** The window whose per-project detail is open. */
    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    public function mount(Tenant $tenant): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.compliance.view'), 403);

        $this->tenant = $tenant;
    }

    public function showPeriod(int $periodId): void
    {
        $this->periodId = (string) $periodId;
        $this->resetPage();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @return array{
     *     tenant: array{id: int, name: string, slug: string},
     *     periods: list<array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    #[Computed]
    public function detail(): array
    {
        return app(BuildTenantComplianceDetail::class)($this->user(), $this->tenant);
    }

    /**
     * The windows on screen, as models — the table prints each one's deadline
     * and derives its open/closed state, which the aggregate rows do not carry.
     *
     * @return Collection<int, ReportingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        $ids = array_map(
            fn (array $row): int => (int) $row['period_id'],
            $this->detail()['periods'],
        );

        // The calendar is global — no tenancy involved, so no bypass either.
        return ReportingPeriod::query()
            ->whereIn('id', $ids)
            ->orderByDesc('period_start')
            ->get();
    }

    /** The window whose detail is open — the most recent one unless chosen. */
    #[Computed]
    public function period(): ?ReportingPeriod
    {
        if ($this->periodId !== '') {
            $chosen = $this->periods()->firstWhere('id', (int) $this->periodId);

            if ($chosen instanceof ReportingPeriod) {
                return $chosen;
            }
        }

        return $this->periods()->first();
    }

    /**
     * The projects behind the chosen window's numbers.
     *
     * @return LengthAwarePaginator<int, ReportObligation>|null
     */
    #[Computed]
    public function obligations(): ?LengthAwarePaginator
    {
        $period = $this->period();

        if ($period === null) {
            return null;
        }

        return app(BuildTenantComplianceDetail::class)
            ->obligations($this->user(), $this->tenant, $period);
    }

    /**
     * A window's aggregate row, keyed for the table.
     *
     * @return array<string, mixed>|null
     */
    public function rowFor(ReportingPeriod $period): ?array
    {
        foreach ($this->detail()['periods'] as $row) {
            if ((int) $row['period_id'] === $period->id) {
                return $row;
            }
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.oversight.reporting.tenant-compliance');
    }
}
