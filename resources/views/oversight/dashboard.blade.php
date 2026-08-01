{{--
    State-level oversight dashboard (cross-entity).

    Thin by design: the league table and KPI tiles become lazy Livewire components
    reading through explicit Model::withoutTenancy() calls in oversight Actions.
    Figures below are placeholder literals, not live data.
--}}
@php
    $title = __('State dashboard');
@endphp

@extends('layouts.oversight')

@section('content')
    <x-ui.page-header
        :title="__('State dashboard')"
        :description="__('Portfolio-wide delivery and reporting compliance across every entity on this instance.')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray">{{ __('Excel') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="document-text">{{ __('Executive brief (PDF)') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Entities reporting')"
            value="0 / 0"
            icon="building-office"
            :hint="__('workspaces provisioned')"
            href="#"
        />
        <x-ui.stat
            :label="__('Projects under monitoring')"
            value="0"
            icon="folder"
            :hint="__('across all sectors')"
            href="#"
        />
        <x-ui.stat
            :label="__('Portfolio value')"
            value="₦0.00"
            icon="banknotes"
            :hint="__('of appropriated capital budget')"
            href="#"
        />
        <x-ui.stat
            :label="__('Reporting compliance')"
            value="—"
            icon="clipboard-check"
            :hint="__('on-time submissions this quarter')"
            href="#"
        />
    </div>

    <div class="mt-5 grid gap-4 xl:grid-cols-3">
        <x-ui.card
            class="xl:col-span-2"
            :title="__('Entity league table')"
            :subtitle="__('Ranked by reporting compliance and verified physical progress')"
        >
            <x-slot:actions>
                <x-ui.button variant="ghost" size="sm" icon="funnel">{{ __('Filters') }}</x-ui.button>
            </x-slot:actions>

            <x-ui.empty-state
                compact
                icon="chart-bar"
                :title="__('No entities are reporting yet')"
                :description="__('Provision at least one MDA workspace and register its projects — rankings appear once the first reporting period closes.')"
            >
                <x-slot:actions>
                    <x-ui.button size="sm" icon="plus">{{ __('Create a workspace') }}</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" icon="users">{{ __('Invite entity administrators') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>

        <x-ui.card :title="__('Needs attention')" :subtitle="__('Queued for the M&E secretariat')">
            <x-ui.empty-state
                compact
                icon="inbox"
                :title="__('Your queue is clear')"
                :description="__('Reports awaiting state review, flagged inspections and items in the publishing queue will collect here.')"
            >
                <x-slot:actions>
                    <x-ui.button size="sm" variant="secondary" icon="globe">{{ __('Open publishing queue') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    </div>
@endsection
