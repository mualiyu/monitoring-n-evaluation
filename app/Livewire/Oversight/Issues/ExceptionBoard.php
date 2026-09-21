<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Issues;

use App\Actions\Oversight\ListExceptionsAcrossTenants;
use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Models\ExceptionReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The state's deviation board: every MDA's exception reports on one screen,
 * worst first.
 *
 * READ-ONLY, deliberately. The state watches deviations; the MDA answers for
 * them. An oversight officer acknowledging another entity's exception report
 * would be signing off work they did not do and, worse, would let the state
 * clear its own board without anything changing on the ground.
 *
 * The cross-MDA read lives entirely in ListExceptionsAcrossTenants, which
 * re-checks `exceptions.view` in the GLOBAL permission team before any
 * tenancy bypass. This component holds no bypass of its own — it asks the
 * Action, which is the only thing allowed to answer.
 */
#[Layout('layouts::oversight')]
class ExceptionBoard extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantSlug = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $trigger = '';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(as: 'live', except: true)]
    public bool $liveOnly = true;

    public function mount(): void
    {
        // The Action is the authority and re-checks this anyway; asking here
        // turns a 500 from deep inside a computed property into an honest 403
        // on the way in.
        $this->authorize('viewAny', ExceptionReport::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantSlug(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if ($this->status !== '' && ! ExceptionStatus::from($this->status)->isLive()) {
            $this->liveOnly = false;
        }

        $this->resetPage();
    }

    public function updatedTrigger(): void
    {
        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedLiveOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantSlug', 'status', 'trigger', 'severity']);
        $this->liveOnly = true;
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantSlug !== '' || $this->status !== ''
            || $this->trigger !== '' || $this->severity !== '' || ! $this->liveOnly;
    }

    /**
     * @return LengthAwarePaginator<int, ExceptionReport>
     */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return app(ListExceptionsAcrossTenants::class)($user, [
            'tenant' => $this->selectedTenant(),
            'status' => $this->status === '' ? null : ExceptionStatus::from($this->status),
            'trigger' => $this->trigger === '' ? null : ExceptionTrigger::from($this->trigger),
            'severity' => $this->severity === '' ? null : IssueSeverity::from($this->severity),
            'search' => $this->search === '' ? null : $this->search,
            'live_only' => $this->liveOnly,
        ]);
    }

    /**
     * @return array{live: int, critical: int, automatic: int, entities: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return app(ListExceptionsAcrossTenants::class)->summary($user);
    }

    /**
     * Tenants are global reference data on this surface — no scope to bypass.
     * Keyed by slug, because that is what the URL carries and what every other
     * oversight filter uses.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tenantOptions(): array
    {
        return Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    private function selectedTenant(): ?Tenant
    {
        if ($this->tenantSlug === '') {
            return null;
        }

        return Tenant::query()->where('slug', $this->tenantSlug)->first();
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return ExceptionStatus::options();
    }

    /** @return array<string, string> */
    public function triggerOptions(): array
    {
        return ExceptionTrigger::options();
    }

    /** @return array<string, string> */
    public function severityOptions(): array
    {
        return IssueSeverity::options();
    }

    public function render(): View
    {
        return view('livewire.oversight.issues.exception-board');
    }
}
