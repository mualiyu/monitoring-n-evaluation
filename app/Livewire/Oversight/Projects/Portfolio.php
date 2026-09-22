<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Projects;

use App\Actions\Oversight\BuildPortfolioSummary;
use App\Actions\Oversight\ListProjectsAcrossTenants;
use App\Enums\ProjectStatus;
use App\Models\FundingSource;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
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
 * State portfolio — the only list on the platform that crosses MDA boundaries.
 *
 * Both reads go through app/Actions/Oversight/, which re-check
 * `oversight.portfolio.view` in the GLOBAL permission team before bypassing
 * tenancy. This component never calls withoutTenancy() itself: the bypass
 * belongs with the authorization check that justifies it, in one place.
 */
#[Layout('layouts::oversight')]
class Portfolio extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(as: 'funding', except: '')]
    public string $fundingSource = '';

    /** An LGA id; a multi-site project is listed under every LGA it touches. */
    #[Url(except: '')]
    public string $lga = '';

    /**
     * `yes` = at least one site with BOTH coordinates, `no` = no such site;
     * anything else constrains nothing. With an LGA set, only that LGA's sites
     * are tested — the state GIS dashboard's rule, so its tiles drill in here.
     */
    #[Url(except: '')]
    public string $geotagged = '';

    #[Url(except: false)]
    public bool $overdue = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.portfolio.view'), 403);
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

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedFundingSource(): void
    {
        $this->resetPage();
    }

    public function updatedLga(): void
    {
        $this->resetPage();
    }

    public function updatedGeotagged(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'status', 'sector', 'fundingSource', 'lga', 'geotagged', 'overdue']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantId !== '' || $this->status !== ''
            || $this->sector !== '' || $this->fundingSource !== '' || $this->lga !== ''
            || $this->geotaggedFilter() !== null || $this->overdue;
    }

    /**
     * The geotag filter as the Action reads it: true, false, or no constraint.
     * URL state is user input, so an unrecognised value is ignored, not guessed.
     */
    private function geotaggedFilter(): ?bool
    {
        return match ($this->geotagged) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * The Action takes models, not ids — it filters with whereBelongsTo() so
     * that no hand-written tenant clause exists anywhere, including here.
     *
     * @return array{tenant: Tenant|null, status: ProjectStatus|null, sector: Sector|null, funding_source: FundingSource|null, lga: Lga|null, geotagged: bool|null, search: string|null, overdue: bool}
     */
    private function filters(): array
    {
        return [
            'tenant' => $this->tenantId !== '' ? $this->tenants()->firstWhere('id', (int) $this->tenantId) : null,
            'status' => $this->status !== '' ? ProjectStatus::tryFrom($this->status) : null,
            'sector' => $this->sector !== '' ? $this->sectors()->firstWhere('id', (int) $this->sector) : null,
            'funding_source' => $this->fundingSource !== ''
                ? $this->fundingSources()->firstWhere('id', (int) $this->fundingSource)
                : null,
            // Resolved against the active LGAs on offer: an id matching no row
            // is dropped, never passed to SQL raw.
            'lga' => $this->lga !== '' ? $this->lgas()->firstWhere('id', (int) $this->lga) : null,
            'geotagged' => $this->geotaggedFilter(),
            'search' => $this->search === '' ? null : $this->search,
            'overdue' => $this->overdue,
        ];
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListProjectsAcrossTenants)($user, $this->filters());
    }

    /**
     * Portfolio-wide figures. Cached inside the Action (5 minutes) because the
     * aggregate is expensive and the board does not need it to the second —
     * the status counts are what a transition busts.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return (new BuildPortfolioSummary)($user);
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, FundingSource> */
    #[Computed]
    public function fundingSources(): Collection
    {
        return FundingSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Global reference data, so no tenancy question arises — and every active
     * LGA is offered, since a state-wide list spans the whole state roll.
     *
     * @return Collection<int, Lga>
     */
    #[Computed]
    public function lgas(): Collection
    {
        return Lga::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(ProjectStatus::cases())
            ->mapWithKeys(fn (ProjectStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.oversight.projects.portfolio');
    }
}
