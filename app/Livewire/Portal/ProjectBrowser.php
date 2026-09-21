<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Portal\ListPortalFilterOptions;
use App\Actions\Portal\ListPublishedProjects;
use App\Enums\ProjectStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The published-projects browser — the portal's main screen.
 *
 * Read-only, and structurally so: it holds no mutating method at all, and the
 * only data it can reach comes back from ListPublishedProjects as arrays of
 * whitelisted keys. There is no Project model in this component, so there is
 * no attribute for a future view to reach for.
 *
 * There is no authorize() here and that is correct — the portal has no users.
 * The gate is the published predicate, applied in the Action, not a policy.
 *
 * Progressive enhancement: the filter bar in the view is a real
 * <form method="GET"> whose input names match these #[Url] aliases, so a
 * visitor with JavaScript off still filters the list with a normal page load,
 * and one with JavaScript on gets debounced live filtering over the same
 * query string. A public portal in a state where data is expensive cannot
 * require a 100KB runtime to answer "what is being built in my LGA".
 */
#[Layout('layouts::portal')]
class ProjectBrowser extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(except: '')]
    public string $lga = '';

    #[Url(except: '')]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedLga(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'sector', 'lga', 'status']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->sector !== '' || $this->lga !== '' || $this->status !== '';
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return (new ListPublishedProjects)([
            'search' => $this->search === '' ? null : $this->search,
            'sector' => $this->sector === '' ? null : (int) $this->sector,
            'lga' => $this->lga === '' ? null : (int) $this->lga,
            'status' => $this->status === '' ? null : ProjectStatus::tryFrom($this->status),
        ]);
    }

    /**
     * @return array{sectors: array<int, string>, lgas: array<int, string>, statuses: array<string, string>}
     */
    #[Computed]
    public function filterOptions(): array
    {
        return (new ListPortalFilterOptions)();
    }

    public function render(): View
    {
        return view('livewire.portal.project-browser');
    }
}
