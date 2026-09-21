<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Workplans;

use App\Actions\Oversight\ListWorkplansAcrossTenants;
use App\Enums\WorkplanStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Support\Money;
use App\Support\WorkplanProgress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * State-wide work-plan board: which MDAs have a plan for the year, whether it
 * has been approved, how it is tracking — and, the manual's rule made visible
 * across the state, how many activities carry no output indicator.
 *
 * READ-ONLY. The secretariat does not edit an MDA's plan; it asks for one.
 *
 * The cross-MDA read goes through app/Actions/Oversight, which re-checks
 * `workplans.view` in the GLOBAL permission team before bypassing tenancy.
 * This component never calls withoutTenancy() itself: the bypass belongs with
 * the authorization check that justifies it, in one place.
 */
#[Layout('layouts::oversight')]
class WorkplanBoard extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $year = '';

    #[Url(except: false)]
    public bool $unlinked = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        // The Action re-checks this too; asking here as well is what turns a
        // would-be exception into a 403 page for a role that passed the
        // surface's role middleware but holds no workplans.view.
        abort_unless($user->holdsGlobalPermission('workplans.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedUnlinked(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'status', 'year', 'unlinked']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantId !== '' || $this->status !== ''
            || $this->year !== '' || $this->unlinked;
    }

    /** @return LengthAwarePaginator<int, Workplan> */
    #[Computed]
    public function workplans(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return app(ListWorkplansAcrossTenants::class)($user, [
            // The Action takes models, not ids — it filters with
            // whereBelongsTo() so no hand-written tenant clause exists
            // anywhere, including here.
            'tenant' => $this->tenantId !== '' ? $this->tenants()->firstWhere('id', (int) $this->tenantId) : null,
            'status' => $this->status !== '' ? WorkplanStatus::tryFrom($this->status) : null,
            'year' => $this->year !== '' ? (int) $this->year : null,
            'search' => $this->search === '' ? null : $this->search,
            'unlinked' => $this->unlinked,
        ]);
    }

    /**
     * The roll-up for one plan, from the single derivation every surface uses.
     *
     * @return array<string, mixed>
     */
    public function summary(Workplan $workplan): array
    {
        return WorkplanProgress::summarise($workplan->activities);
    }

    /**
     * State-wide totals for the rows on this page. Deliberately page-scoped
     * and labelled as such on screen: an honest "on this page" beats a
     * state-wide figure the filter bar silently contradicts.
     *
     * @return array{plans: int, unlinked: int, budget: string, awaiting: int}
     */
    #[Computed]
    public function totals(): array
    {
        $unlinked = 0;
        $awaiting = 0;
        $budget = Money::zero();

        foreach ($this->workplans()->items() as $plan) {
            $summary = WorkplanProgress::summarise($plan->activities);
            $unlinked += $summary['unlinked'];
            $budget = $budget->plus($summary['budget']);

            if ($plan->status->isAwaitingDecision()) {
                $awaiting++;
            }
        }

        return [
            'plans' => $this->workplans()->total(),
            'unlinked' => $unlinked,
            'budget' => $budget->toDecimalString(),
            'awaiting' => $awaiting,
        ];
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        $options = [];

        foreach (WorkplanStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<int, string> */
    #[Computed]
    public function yearOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $options = [];

        foreach (app(ListWorkplansAcrossTenants::class)->years($user) as $year) {
            $options[$year] = (string) $year;
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.oversight.workplans.workplan-board');
    }
}
