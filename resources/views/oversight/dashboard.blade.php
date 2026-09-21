{{--
    State-level oversight dashboard (cross-entity).

    The KPI row is a lazy Livewire component reading through the oversight
    Actions, which own the sanctioned cross-tenant reads. The panels below it
    link into the boards that hold the detail.
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
        {{--
            The Excel and PDF buttons had no href and no wire:click — dead
            controls. The report builder that would produce them is on the
            roadmap, so they are removed rather than left looking operable.
        --}}
    </x-ui.page-header>

    {{--
        KPI row — live, read across every MDA through the oversight Actions,
        which re-check their own permission in the GLOBAL team before any
        tenancy bypass. Lazy: a cross-MDA aggregate must never hold the page.
    --}}
    <livewire:oversight.dashboard.state-kpis lazy />

    <div class="mt-5">
        <livewire:oversight.dashboard.portfolio-chart lazy />
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <x-ui.card
            class="xl:col-span-2"
            :title="__('Entity league table')"
            :subtitle="__('Ranked by reporting compliance and verified physical progress')"
        >
            <x-slot:actions>
                {{-- The real ranking, with its own filters, is the compliance board. --}}
                <x-ui.button variant="ghost" size="sm" trailing-icon="arrow-right" :href="route('oversight.compliance.index')">
                    {{ __('Open compliance board') }}
                </x-ui.button>
            </x-slot:actions>

            <x-ui.empty-state
                compact
                icon="chart-bar"
                :title="__('No entities are reporting yet')"
                :description="__('Provision at least one MDA workspace and register its projects — rankings appear once the first reporting period closes.')"
            >
                <x-slot:actions>
                    {{--
                        "Create a workspace" is dropped: tenant provisioning has
                        no screen yet, and a button to a screen that does not
                        exist is worse than no button. Inviting administrators
                        does have one, so that action now reaches it.
                    --}}
                    <x-ui.button size="sm" variant="secondary" icon="users" :href="route('oversight.users.index')">
                        {{ __('Invite entity administrators') }}
                    </x-ui.button>
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
                    {{--
                        The publishing queue is a Phase 3 portal feature with no
                        route yet, so the button is removed. The reports the
                        secretariat can actually act on are on the compliance
                        board today.
                    --}}
                    <x-ui.button size="sm" variant="secondary" trailing-icon="arrow-right" :href="route('oversight.compliance.index')">
                        {{ __('Review reporting compliance') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    </div>
@endsection
