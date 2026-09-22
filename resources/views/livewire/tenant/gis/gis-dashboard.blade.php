{{--
    Workspace GIS dashboard (App\Livewire\Tenant\Gis\GisDashboard).
    This entity's project sites on a map, narrowed by role like the register;
    the body is shared with the state map — see livewire/shared/partials/project-map.
--}}
<div>
    <x-ui.page-header
        :title="__('Project map')"
        :description="__('Where this workspace’s projects are being delivered, by status, sector and local government area. Positions are the recorded GPS fixes of each site.')"
    >
        <x-slot:actions>
            <x-ui.button :href="$this->listUrl([])" variant="secondary" icon="folder">
                {{ __('Project list') }}
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
        'tenants' => null,
        'projectUrl' => $this->projectUrl(),
        'listUrl' => $this->listUrl(...),
        'lgaUrl' => $this->lgaUrl(...),
    ])
</div>
