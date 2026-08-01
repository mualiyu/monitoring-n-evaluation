<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Projects;

use App\Actions\Oversight\BuildPortfolioSummary;
use App\Actions\Oversight\ListProjectsAcrossTenants;
use App\Enums\ProjectStatus;
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
 * One entity's portfolio, seen from the state surface (design §5).
 *
 * Same Action as the cross-MDA list with the tenant filter locked — one query
 * path for both, so the drill-down can never disagree with the row that led to
 * it. `Tenant` itself is not tenant-scoped, so binding it by slug is safe here.
 */
#[Layout('layouts::oversight')]
class TenantPortfolio extends Component
{
    use WithPagination;

    public Tenant $tenant;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(except: false)]
    public bool $overdue = false;

    public function mount(Tenant $tenant): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.portfolio.view'), 403);

        $this->tenant = $tenant;
    }

    public function updatedSearch(): void
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

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'sector', 'overdue']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->sector !== '' || $this->overdue;
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListProjectsAcrossTenants)($user, [
            'tenant' => $this->tenant,
            'status' => $this->status !== '' ? ProjectStatus::tryFrom($this->status) : null,
            'sector' => $this->sector !== '' ? $this->sectors()->firstWhere('id', (int) $this->sector) : null,
            'search' => $this->search === '' ? null : $this->search,
            'overdue' => $this->overdue,
        ]);
    }

    /**
     * This entity's slice of the cached portfolio aggregate — the same numbers
     * as the league table row that linked here, by construction.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        return collect((new BuildPortfolioSummary)($user)['tenants'])
            ->firstWhere('tenant_id', $this->tenant->id);
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
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
        return view('livewire.oversight.projects.tenant-portfolio');
    }
}
