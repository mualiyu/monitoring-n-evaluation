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
            <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray">{{ __('Export portfolio') }}</x-ui.button>
            <x-ui.button size="sm" icon="plus">{{ __('Register project') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($tenantTypeLabel)
        <p class="-mt-3 mb-6 flex items-center gap-1.5 text-sm text-ink-muted">
            <x-ui.icon name="building-office" class="size-4" />
            {{ $tenantTypeLabel }}
        </p>
    @endif

    {{-- KPI row — placeholder figures until the Livewire widgets land. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Active projects')"
            value="24"
            icon="folder"
            delta="+3"
            trend="up"
            intent="positive"
            :hint="__('vs last quarter')"
            href="#"
        />
        <x-ui.stat
            :label="__('Contract value monitored')"
            value="₦8.6bn"
            icon="banknotes"
            :hint="__('across 24 active contracts')"
            href="#"
        />
        <x-ui.stat
            :label="__('Reports awaiting review')"
            value="6"
            icon="document-text"
            delta="+2"
            trend="up"
            intent="warning"
            :hint="__('2 due this week')"
            href="#"
        />
        <x-ui.stat
            :label="__('Overdue submissions')"
            value="1"
            icon="exclamation-triangle"
            delta="−2"
            trend="down"
            intent="positive"
            :hint="__('down from 3 last month')"
            href="#"
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
