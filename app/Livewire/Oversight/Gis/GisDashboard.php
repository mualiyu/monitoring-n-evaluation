<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Gis;

use App\Actions\Oversight\BuildStateProjectMap;
use App\Livewire\Shared\Concerns\InteractsWithProjectMap;
use App\Models\Lga;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * State GIS dashboard — every MDA's project sites on one map, by status,
 * sector, LGA and entity.
 *
 * The cross-MDA read and its authorization live in BuildStateProjectMap; this
 * component never bypasses tenancy itself. mount() checks the permission for
 * the page, and the Action re-checks it on every update request, because
 * Livewire does not re-run mount() when a filter changes.
 *
 * @property-read array{pins: list<array<string, mixed>>, capped: bool, totals: array{projects: int, sites: int, contract_value: Money, overdue: int, unmapped: int}, by_category: array<string, int>, by_lga: list<array{lga_id: int|null, name: string, projects: int, sites: int, overdue: int, average_progress: float}>} $map
 * @property-read Collection<int, Sector> $sectors
 * @property-read Collection<int, Lga> $lgas
 * @property-read array<string, string> $statusOptions
 * @property-read LengthAwarePaginator<int, array<string, mixed>> $sites
 * @property-read Collection<int, Tenant> $tenants
 */
#[Layout('layouts::oversight')]
class GisDashboard extends Component
{
    use InteractsWithProjectMap;

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.portfolio.view'), 403);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function map(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return (new BuildStateProjectMap)($user, [
            ...$this->sharedFilters(),
            'tenant' => $this->tenantId !== '' ? $this->tenants->firstWhere('id', (int) $this->tenantId) : null,
        ]);
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /**
     * The portfolio list with this map's filters carried over — entity,
     * status, sector, LGA and overdue — so a tile drills into exactly the rows
     * that make up its number.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function listUrl(array $extra): string
    {
        return route('oversight.portfolio.index', array_filter([
            'mda' => $this->tenantId,
            'status' => $this->status,
            'sector' => $this->sector,
            'lga' => $this->lga,
            'overdue' => $this->overdue ? 1 : null,
            ...$extra,
        ], fn (mixed $value): bool => $value !== '' && $value !== null));
    }

    /** This map, focused on one LGA, keeping the other filters. */
    protected function lgaUrl(int $lgaId): string
    {
        return route('oversight.gis', array_filter([
            'mda' => $this->tenantId,
            'status' => $this->status,
            'sector' => $this->sector,
            'lga' => $lgaId,
            'overdue' => $this->overdue ? 1 : null,
        ], fn (mixed $value): bool => $value !== '' && $value !== null));
    }

    /** @return list<string> */
    protected function filterProperties(): array
    {
        return ['tenantId', 'status', 'sector', 'lga', 'overdue'];
    }

    public function render(): View
    {
        return view('livewire.oversight.gis.gis-dashboard')
            ->title(__('GIS dashboard'));
    }
}
