{{--
    State GIS dashboard (App\Livewire\Oversight\Gis\GisDashboard).
    Every entity's project sites on one map; the body is shared with the
    workspace map — see livewire/shared/partials/project-map.
--}}
<div>
    <x-ui.page-header
        :title="__('GIS dashboard')"
        :description="__('Every project site across all entities, by status, sector and local government area. Positions are the recorded GPS fixes of each site.')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('oversight.portfolio.index')" variant="secondary" icon="folder">
                {{ __('Portfolio list') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.shared.partials.project-map', [
        'map' => $this->map,
        'sites' => $this->sites,
        'categories' => $this->categories(),
        'filters' => ['status' => $status, 'sector' => $sector, 'lga' => $lga, 'overdue' => $overdue],
        'hasFilters' => $this->hasFilters(),
        'statusOptions' => $this->statusOptions,
        'sectors' => $this->sectors,
        'lgas' => $this->lgas,
        'tenants' => $this->tenants,
        'projectUrl' => route('oversight.projects.show', '__ULID__'),
        'listUrl' => $this->listUrl(...),
        'lgaUrl' => $this->lgaUrl(...),
    ])
</div>
