<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Gis;

use App\Actions\Projects\BuildProjectMap;
use App\Livewire\Shared\Concerns\InteractsWithProjectMap;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Workspace GIS dashboard — this MDA's project sites on a map, by status,
 * sector and LGA. Role-narrowed exactly like the project register: a
 * consultant or field monitor sees only the projects they are assigned to.
 *
 * @property-read array{pins: list<array<string, mixed>>, capped: bool, totals: array{projects: int, sites: int, contract_value: Money, overdue: int, unmapped: int}, by_category: array<string, int>, by_lga: list<array{lga_id: int|null, name: string, projects: int, sites: int, overdue: int, average_progress: float}>} $map
 * @property-read Collection<int, Sector> $sectors
 * @property-read Collection<int, Lga> $lgas
 * @property-read array<string, string> $statusOptions
 * @property-read LengthAwarePaginator<int, array<string, mixed>> $sites
 */
#[Layout('layouts::tenant')]
class GisDashboard extends Component
{
    use InteractsWithProjectMap;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function map(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return (new BuildProjectMap)($user, $this->sharedFilters());
    }

    /**
     * The tenant routes carry a {tenant} DOMAIN parameter. ResolveTenant fills
     * it through URL::defaults() on a real request, but a component test never
     * crosses HTTP — so the bound tenant is passed explicitly, and the screen
     * renders the same way in both worlds (as DocumentPanel does).
     *
     * @return array<string, string>
     */
    private function tenantParameter(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return $tenant instanceof Tenant ? ['tenant' => $tenant->slug] : [];
    }

    /** The project detail URL, with __ULID__ where the project key goes. */
    protected function projectUrl(): string
    {
        return route('tenant.projects.show', [...$this->tenantParameter(), 'project' => '__ULID__']);
    }

    /**
     * The project register with this map's filters carried over — every one
     * of them, LGA included, so a tile drills into exactly its rows.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function listUrl(array $extra): string
    {
        return route('tenant.projects.index', [...$this->tenantParameter(), ...array_filter([
            'status' => $this->status,
            'sector' => $this->sector,
            'lga' => $this->lga,
            'overdue' => $this->overdue ? 1 : null,
            ...$extra,
        ], fn (mixed $value): bool => $value !== '' && $value !== null)]);
    }

    /** This map, focused on one LGA, keeping the other filters. */
    protected function lgaUrl(int $lgaId): string
    {
        return route('tenant.gis', [...$this->tenantParameter(), ...array_filter([
            'status' => $this->status,
            'sector' => $this->sector,
            'lga' => $lgaId,
            'overdue' => $this->overdue ? 1 : null,
        ], fn (mixed $value): bool => $value !== '' && $value !== null)]);
    }

    public function render(): View
    {
        return view('livewire.tenant.gis.gis-dashboard')
            ->title(__('Project map'));
    }
}
