{{--
    Public transparency portal — landing page.

    Read-only. $summary and $recent both come from app/Actions/Portal, computed
    over PUBLISHED rows only, so the headline figure a citizen quotes back at a
    commissioner is the same figure the list below will let them drill into.

    Zeros and an empty state until the first publishing run. A portal that
    invents a number on day one has spent the only thing it has.

    Variables: $summary (BuildPortalSummary), $recent (list of PublicProjectPayload arrays).
--}}
@php
    $instanceName = config('platform.instance.name') ?? config('app.name');
    $title = __('Public project transparency');

    $features = [
        [
            'icon' => 'folder',
            'title' => __('Track public projects'),
            'description' => __('Browse capital projects by entity, sector and location — with contract value, contractor and current status for each one.'),
            'action' => __('Browse projects'),
            'href' => route('portal.projects.index'),
        ],
        [
            'icon' => 'map-pin',
            'title' => __('Find what is near you'),
            'description' => __('Every published site on one map, with the local government area and ward recorded against it — so you can tell whether the project on this portal is the one at the end of your street.'),
            'action' => __('Open the map'),
            'href' => route('portal.map'),
        ],
        [
            'icon' => 'chat-bubble',
            'title' => __('Tell the monitoring team'),
            'description' => __('Report stalled or substandard work. Comments are read by the entity delivering the project and by the state monitoring secretariat.'),
            'action' => __('Submit feedback'),
            'href' => route('portal.feedback.create'),
        ],
    ];
@endphp

@extends('layouts.portal')

@section('content')
    {{-- Hero --}}
    <section class="rounded-2xl border border-line bg-surface-raised px-5 py-10 shadow-e1 sm:px-10 sm:py-14">
        <div class="max-w-3xl">
            <p class="inline-flex items-center gap-1.5 rounded-md bg-brand-soft px-2 py-1 text-xs font-semibold text-brand-ink ring-1 ring-brand/30 ring-inset">
                <x-ui.icon name="globe" class="size-3.5" />
                {{ __('Public transparency portal') }}
            </p>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">
                {{ __('See how public projects are progressing') }}
            </h1>

            <p class="mt-4 text-base text-ink-muted sm:text-lg">
                {{ __(':instance publishes verified monitoring and evaluation data on capital projects — what was contracted, what has been paid, and what has actually been built.', ['instance' => $instanceName]) }}
            </p>

            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <x-ui.button :href="route('portal.projects.index')" icon="magnifying-glass">
                    {{ __('Find a project near you') }}
                </x-ui.button>
                <x-ui.button :href="route('portal.feedback.create')" variant="secondary" icon="chat-bubble">
                    {{ __('Report an issue') }}
                </x-ui.button>
            </div>

            <p class="mt-6 flex items-start gap-1.5 text-xs text-ink-muted">
                <x-ui.icon name="shield-check" class="mt-0.5 size-3.5 shrink-0" />
                {{ __('Only data approved for publication by the responsible entity and the state M&E secretariat appears on this portal.') }}
            </p>
        </div>
    </section>

    {{-- What you can do here --}}
    <section class="mt-8" aria-labelledby="portal-features">
        <h2 id="portal-features" class="sr-only">{{ __('What you can do on this portal') }}</h2>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($features as $feature)
                <x-ui.card>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-brand-soft text-brand-ink">
                        <x-ui.icon :name="$feature['icon']" class="size-5" />
                    </span>

                    <h3 class="mt-4 text-base font-semibold text-ink">{{ $feature['title'] }}</h3>
                    <p class="mt-1.5 text-sm text-ink-muted">{{ $feature['description'] }}</p>

                    <a
                        href="{{ $feature['href'] }}"
                        class="mt-4 inline-flex items-center gap-1 rounded text-sm font-medium text-brand-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        {{ $feature['action'] }}
                        <x-ui.icon name="chevron-right" class="size-4" />
                    </a>
                </x-ui.card>
            @endforeach
        </div>
    </section>

    {{-- Published portfolio at a glance --}}
    <section class="mt-8" aria-labelledby="portal-figures">
        <h2 id="portal-figures" class="text-lg font-semibold tracking-tight text-ink">{{ __('Published portfolio at a glance') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">
            {{ __('Every figure below counts published projects only, and updates the moment an entity publishes or withdraws one.') }}
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Published projects')"
                :value="number_format($summary['projects'])"
                icon="folder"
                :hint="$summary['projects'] === 0 ? __('awaiting first publication') : __('open to the public')"
                :href="route('portal.projects.index')"
            />
            <x-ui.stat
                :label="__('Published contract value')"
                :value="$summary['contract_value']->format()"
                icon="banknotes"
                :hint="__('across all sectors')"
            />
            <x-ui.stat
                :label="__('Finished projects')"
                :value="number_format($summary['completed'])"
                icon="flag"
                :hint="__('completed, certified or closed')"
            />
            <x-ui.stat
                :label="__('Local government areas')"
                :value="number_format($summary['lgas'])"
                icon="map-pin"
                :hint="__('with a published project site')"
                :href="route('portal.map')"
            />
        </div>
    </section>

    {{-- Most recently published --}}
    <section class="mt-8" aria-labelledby="portal-recent">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="portal-recent" class="text-lg font-semibold tracking-tight text-ink">{{ __('Recently published') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('The latest projects the state has opened to the public.') }}</p>
            </div>

            @if (filled($recent))
                <x-ui.button :href="route('portal.projects.index')" variant="secondary" size="sm" trailing-icon="arrow-right">
                    {{ __('All published projects') }}
                </x-ui.button>
            @endif
        </div>

        @if (blank($recent))
            <x-ui.card class="mt-4" flush>
                <x-ui.empty-state
                    icon="inbox"
                    :title="__('Nothing has been published yet')"
                    :description="__('Project data appears here as soon as an entity publishes it. Nothing on this page is a projection or a plan — until then, the figures above stay at zero.')"
                >
                    <x-slot:actions>
                        <x-ui.button :href="route('portal.feedback.create')" variant="secondary" icon="chat-bubble">
                            {{ __('Tell the monitoring team what you see') }}
                        </x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach ($recent as $project)
                    @include('portal.partials.project-card', ['project' => $project])
                @endforeach
            </div>
        @endif
    </section>
@endsection
