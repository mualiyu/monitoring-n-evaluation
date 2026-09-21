{{--
    Published reports.

    There is nothing to list, and that is a decision rather than a gap. No
    report type on this platform carries a PUBLICATION decision yet: progress
    reports are approved, which is an internal act by an M&E officer, not a
    public one by the state. Quietly publishing approved returns would break
    the single rule this module exists to enforce — that a human decides, on
    the record, what the public sees.

    So the screen says what will appear here and why it has not, and points at
    the data that IS published. An empty page with a spinner would be worse
    than useless on a government transparency portal: it reads as concealment.
--}}
@php
    $title = __('Published reports');
@endphp

@extends('layouts.portal')

@section('content')
    <x-ui.page-header
        :title="__('Published reports')"
        :description="__('Periodic monitoring and evaluation reports, once the state publishes them.')"
        :breadcrumbs="[
            ['label' => __('Home'), 'href' => route('portal.home')],
            ['label' => __('Published reports')],
        ]"
    />

    <x-ui.card flush>
        <x-ui.empty-state
            icon="document-text"
            :title="__('No report has been published yet')"
            :description="__('Monitoring reports are filed by the delivering entity and reviewed internally. Being approved is not the same as being published: a report appears here only when the state takes a separate, recorded decision to open it to the public.')"
        >
            <x-slot:actions>
                <x-ui.button :href="route('portal.projects.index')" icon="folder">
                    {{ __('See published projects') }}
                </x-ui.button>
                <x-ui.button :href="route('portal.map')" variant="secondary" icon="map-pin">
                    {{ __('Open the project map') }}
                </x-ui.button>
            </x-slot:actions>

            {{ __('Project-level progress, expenditure and site photographs are published today — each project page carries the figures as the monitoring team verified them.') }}
        </x-ui.empty-state>
    </x-ui.card>

    <x-ui.card class="mt-6" :title="__('What will appear here')">
        <ul class="space-y-3 text-sm text-ink-muted">
            <li class="flex items-start gap-2">
                <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                {{ __('Periodic performance reports consolidated by the state monitoring secretariat.') }}
            </li>
            <li class="flex items-start gap-2">
                <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                {{ __('Evaluation reports, once the commissioning entity has cleared them for release.') }}
            </li>
            <li class="flex items-start gap-2">
                <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                {{ __('Each one carries the date it was published and the entity that published it.') }}
            </li>
        </ul>
    </x-ui.card>
@endsection
