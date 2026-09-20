{{--
    MDA workspace dashboard.

    Deliberately thin: every panel below becomes a lazy Livewire component in Phase 1
    (@@lazy + computed properties with eager loading). The KPI figures are placeholder
    literals, marked as such, so nobody mistakes them for live data.

    $tenant is shared by the ResolveTenant middleware; still read null-safely so the
    view renders in previews and tests.
--}}
@php
    $tenantName = data_get($tenant, 'name') ?? __('Workspace');
    $tenantType = data_get($tenant, 'type');
    $tenantTypeLabel = is_object($tenantType) && method_exists($tenantType, 'label')
        ? $tenantType->label()
        : null;

    $title = __('Dashboard');
@endphp

@extends('layouts.tenant')

@section('content')
    <x-ui.page-header
        :title="$tenantName"
        :description="__('Live picture of everything this entity is delivering, and what needs your attention this week.')"
    >
        <x-slot:actions>
            {{--
                Both of these were <button> elements with no href and no
                wire:click, so they rendered as controls that did nothing when
                clicked. "Register project" now goes to the wizard, gated on the
                same permission the wizard itself enforces in mount(), so it is
                never offered to someone who would get a 403. The export control
                lives on the projects index next to the filters it exports, so
                it is not duplicated here.
            --}}
            @can('create', \App\Models\Project::class)
                <x-ui.button size="sm" icon="plus" :href="url('/projects/create')">
                    {{ __('Register project') }}
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($tenantTypeLabel)
        <p class="-mt-3 mb-6 flex items-center gap-1.5 text-sm text-ink-muted">
            <x-ui.icon name="building-office" class="size-4" />
            {{ $tenantTypeLabel }}
        </p>
    @endif

    {{--
        KPI row. The FIGURES are still placeholder literals — they are not read
        from this workspace and must not be shown to a client as live data; the
        real aggregates arrive with the summary-table widgets on the roadmap.
        The LINKS are now real: every tile reaches the list that would explain
        it (design system: "every metric card links to the drill-down"), where
        they previously all pointed at href="#".
    --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Active projects')"
            value="24"
            icon="folder"
            delta="+3"
            trend="up"
            intent="positive"
            :hint="__('sample figure')"
            :href="url('/projects')"
        />
        <x-ui.stat
            :label="__('Contract value monitored')"
            value="₦8.6bn"
            icon="banknotes"
            :hint="__('sample figure')"
            :href="url('/projects')"
        />
        <x-ui.stat
            :label="__('Reports awaiting review')"
            value="6"
            icon="document-text"
            delta="+2"
            trend="up"
            intent="warning"
            :hint="__('sample figure')"
            :href="url('/reports')"
        />
        <x-ui.stat
            :label="__('Overdue submissions')"
            value="1"
            icon="exclamation-triangle"
            delta="−2"
            trend="down"
            intent="positive"
            :hint="__('sample figure')"
            :href="url('/reports')"
        />
    </div>

    {{--
        Live reporting widgets. Both are LAZY: the shell and the KPI row paint
        immediately, and each card fills in behind its own skeleton rather than
        holding the whole dashboard on its query. Both scope themselves with
        visibleTo(), so a consultant lands on their own work and a director on
        the workspace's.
    --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <livewire:tenant.reporting.recent-reports-card lazy />
        <livewire:tenant.reporting.upcoming-deadlines-card lazy />
    </div>
@endsection
