{{--
    MDA workspace dashboard.

    Thin by construction: every panel is a lazy Livewire component with its own
    skeleton, so the shell paints immediately and no single aggregate can hold
    the page. Every figure on it is read from this workspace — there are no
    placeholder numbers left here.

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
                <x-ui.button size="sm" icon="plus" :href="route('tenant.projects.create')">
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
        KPI row — live, read from this workspace. It replaced four hard-coded
        literals that shipped labelled "sample figure"; a number on a
        government dashboard is either true or it is a liability. Lazy, so the
        page header paints before the aggregate returns, and scoped by
        visibleTo() inside the Action so a consultant lands on their own work.
    --}}
    <livewire:tenant.dashboard.workspace-kpis lazy />

    {{--
        Live reporting widgets. Both are LAZY: the shell and the KPI row paint
        immediately, and each card fills in behind its own skeleton rather than
        holding the whole dashboard on its query. Both scope themselves with
        visibleTo(), so a consultant lands on their own work and a director on
        the workspace's.
    --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <livewire:tenant.dashboard.delivery-chart lazy />
        <livewire:tenant.reporting.upcoming-deadlines-card lazy />
    </div>

    <div class="mt-4">
        <livewire:tenant.reporting.recent-reports-card lazy />
    </div>
@endsection
