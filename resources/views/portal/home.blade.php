{{--
    Public transparency portal — landing page.

    Read-only surface: everything shown here comes from published (approved) data
    only. Counters stay at zero until the first publishing run, which is honest
    rather than fictional — this page is public-facing.
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
        ],
        [
            'icon' => 'chart-bar',
            'title' => __('See verified progress'),
            'description' => __('Progress is published only after a monitoring officer verifies it on site, with photographs and inspection dates attached.'),
            'action' => __('View progress reports'),
        ],
        [
            'icon' => 'chat-bubble',
            'title' => __('Give feedback'),
            'description' => __('Tell the monitoring team what you see on the ground. Reports on stalled or substandard work reach the evaluation officers directly.'),
            'action' => __('Submit feedback'),
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
                <x-ui.button href="#" icon="magnifying-glass">{{ __('Find a project near you') }}</x-ui.button>
                <x-ui.button href="#" variant="secondary" icon="chat-bubble">{{ __('Report an issue') }}</x-ui.button>
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
                        href="#"
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
        <p class="mt-1 text-sm text-ink-muted">{{ __('Figures update whenever an entity publishes new monitoring data.') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <x-ui.stat :label="__('Published projects')" value="0" icon="folder" :hint="__('awaiting first publication')" />
            <x-ui.stat :label="__('Published contract value')" value="₦0.00" icon="banknotes" :hint="__('across all sectors')" />
            <x-ui.stat :label="__('Verified site inspections')" value="0" icon="clipboard-check" :hint="__('with photographic evidence')" />
        </div>

        <x-ui.card class="mt-4" flush>
            <x-ui.empty-state
                icon="inbox"
                :title="__('Nothing has been published yet')"
                :description="__('Monitoring data appears here as soon as the first reporting period is approved for publication. Check back shortly, or subscribe for updates.')"
            >
                <x-slot:actions>
                    <x-ui.button href="#" variant="secondary" icon="bell">{{ __('Notify me when data is published') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    </section>
@endsection
