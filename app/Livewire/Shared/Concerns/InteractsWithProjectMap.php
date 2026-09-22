<?php

namespace App\Livewire\Shared\Concerns;

use App\Enums\ProjectMapCategory;
use App\Enums\ProjectStatus;
use App\Models\Lga;
use App\Models\Sector;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Filter state and map plumbing shared by both GIS dashboards. Each surface's
 * component supplies map() — the one place its data is read, through its own
 * Action — and everything a filter change triggers is handled here.
 *
 * The map itself sits under wire:ignore (Leaflet owns that DOM), so a filter
 * change cannot redraw it by morphing. Instead every update dispatches the new
 * pins as a browser event the map listens for. The first paint reads them
 * from the page, so the map draws without waiting for a round trip.
 */
trait InteractsWithProjectMap
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(except: '')]
    public string $lga = '';

    #[Url(except: false)]
    public bool $overdue = false;

    /** @return array<string, mixed> */
    abstract public function map(): array;

    public function updated(string $property): void
    {
        if (in_array($property, $this->filterProperties(), true)) {
            $this->resetPage();
            $this->redrawMap();
        }
    }

    public function clearFilters(): void
    {
        $this->reset($this->filterProperties());
        $this->resetPage();
        $this->redrawMap();
    }

    /** Drill into one LGA from the breakdown table — or back out of it. */
    public function focusLga(?int $lgaId): void
    {
        $this->lga = $lgaId === null ? '' : (string) $lgaId;
        $this->resetPage();
        $this->redrawMap();
    }

    /**
     * The same sites as the map, as a paginated list: the accessible route to
     * what the markers show (a map is not readable by a screen reader however
     * it is marked up), and the no-JavaScript fallback.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function sites(): LengthAwarePaginator
    {
        /** @var list<array<string, mixed>> $pins */
        $pins = $this->map['pins'];
        $page = max(1, $this->getPage());

        return new LengthAwarePaginator(
            array_slice($pins, ($page - 1) * 25, 25),
            count($pins),
            25,
            $page,
        );
    }

    public function hasFilters(): bool
    {
        foreach ($this->filterProperties() as $property) {
            if ($this->{$property} !== '' && $this->{$property} !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function filterProperties(): array
    {
        return ['status', 'sector', 'lga', 'overdue'];
    }

    /**
     * The shared filters as models. An id that matches no active row is
     * dropped rather than trusted — URL state is user input.
     *
     * @return array{status: ProjectStatus|null, sector: Sector|null, lga: Lga|null, overdue: bool}
     */
    protected function sharedFilters(): array
    {
        return [
            'status' => $this->status !== '' ? ProjectStatus::tryFrom($this->status) : null,
            'sector' => $this->sector !== '' ? $this->sectors->firstWhere('id', (int) $this->sector) : null,
            'lga' => $this->lga !== '' ? $this->lgas->firstWhere('id', (int) $this->lga) : null,
            'overdue' => $this->overdue,
        ];
    }

    private function redrawMap(): void
    {
        unset($this->map);

        $this->dispatch('project-map-updated', pins: $this->map['pins']);
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Lga> */
    #[Computed]
    public function lgas(): Collection
    {
        return Lga::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(ProjectStatus::cases())
            ->mapWithKeys(fn (ProjectStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return list<ProjectMapCategory> */
    public function categories(): array
    {
        return ProjectMapCategory::cases();
    }
}
