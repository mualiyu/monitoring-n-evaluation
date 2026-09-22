{{--
    <x-ui.steps /> — wizard step indicator for long M&E forms (project creation,
    quarterly progress report, inspection checklist).

    Mobile shows "Step 2 of 5" + progress bar + the current step name; desktop shows
    the full trail. Completed steps carry a check icon, not just a colour.

    Props
      steps    array of strings, or arrays: ['label' => …, 'description' => …, 'href' => …]
      current  1-based index of the active step (server-rendered state)
      label    accessible name for the <nav>
      state    OPTIONAL Alpine expression (e.g. "step") that holds the current step
               client-side. When given, the indicator re-renders reactively while
               `current` stays the no-JS / first-paint fallback — keep them in sync.

        <x-ui.steps :steps="['Identity', 'Scope & budget', 'Location']" :current="2" />
        <x-ui.steps :steps="$steps" :current="1" state="step" />   (inside x-data)

    NB: no Blade comment markers inside this block — a nested closing marker
    ends the whole comment early and prints the real one on every wizard.
--}}
@props([
    'steps' => [],
    'current' => 1,
    'label' => null,
    'state' => null,
])

@php
    $items = collect($steps)->values()->map(function ($step, $index) use ($current) {
        $step = is_array($step) ? $step : ['label' => $step];
        $number = $index + 1;

        return [
            'number' => $number,
            'label' => $step['label'] ?? '',
            'description' => $step['description'] ?? null,
            'href' => $step['href'] ?? null,
            'state' => $number < $current ? 'complete' : ($number === $current ? 'current' : 'upcoming'),
        ];
    });

    $total = $items->count();
    $activeStep = $items->firstWhere('state', 'current') ?? $items->first();
    $percent = $total > 0 ? (int) round(($current - 1) / max($total - 1, 1) * 100) : 0;

    // Reactive mode helpers. $state is a trusted developer-authored expression,
    // never user input; labels go through Js::from() so quoting is safe.
    $reactive = filled($state);
    $labelsJs = \Illuminate\Support\Js::from($items->pluck('label')->all());
    $descriptionsJs = \Illuminate\Support\Js::from($items->pluck('description')->all());
    $lastIndex = max($total - 1, 0);
    $span = max($total - 1, 1);
    $clamped = "Math.max(0, Math.min({$state} - 1, {$lastIndex}))";
@endphp

<nav aria-label="{{ $label ?? __('Progress') }}" {{ $attributes->class('w-full') }}>
    {{-- Mobile --}}
    <div class="sm:hidden">
        <p class="flex items-baseline justify-between gap-2 text-sm">
            <span
                class="font-semibold text-ink"
                @if ($reactive) x-text="({{ $labelsJs }})[{{ $clamped }}]" @endif
            >{{ $activeStep['label'] ?? '' }}</span>
            <span class="shrink-0 text-ink-muted tabular-nums">
                @if ($reactive)
                    {{ __('Step') }} <span x-text="{{ $state }}">{{ $current }}</span> {{ __('of') }} {{ $total }}
                @else
                    {{ __('Step :current of :total', ['current' => $current, 'total' => $total]) }}
                @endif
            </span>
        </p>
        @if (($activeStep['description'] ?? null) || $reactive)
            <p
                class="mt-1 text-sm text-ink-muted"
                @if ($reactive) x-text="({{ $descriptionsJs }})[{{ $clamped }}] ?? ''" @endif
            >{{ $activeStep['description'] ?? '' }}</p>
        @endif
        <div
            class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-neutral-soft"
            role="progressbar"
            aria-valuemin="1"
            aria-valuemax="{{ $total }}"
            aria-valuenow="{{ $current }}"
            aria-valuetext="{{ __('Step :current of :total', ['current' => $current, 'total' => $total]) }}"
            @if ($reactive)
                x-bind:aria-valuenow="{{ $state }}"
                x-bind:aria-valuetext="'{{ __('Step') }} ' + {{ $state }} + ' {{ __('of') }} {{ $total }}'"
            @endif
        >
            <div
                class="h-full rounded-full bg-brand transition-[width] duration-300"
                style="width: {{ max($percent, 6) }}%"
                @if ($reactive)
                    x-bind:style="'width: ' + Math.max(6, Math.round(({{ $state }} - 1) / {{ $span }} * 100)) + '%'"
                @endif
            ></div>
        </div>
    </div>

    {{-- Desktop --}}
    <ol class="hidden sm:flex sm:items-start sm:gap-2">
        @foreach ($items as $item)
            <li class="flex flex-1 items-start gap-3 {{ ! $loop->last ? 'pr-2' : '' }}">
                @php
                    $tag = $item['href'] && $item['state'] === 'complete' ? 'a' : 'div';
                @endphp

                <{{ $tag }}
                    @if ($tag === 'a') href="{{ $item['href'] }}" @endif
                    @if ($item['state'] === 'current') aria-current="step" @endif
                    @if ($reactive)
                        x-bind:aria-current="{{ $state }} === {{ $item['number'] }} ? 'step' : null"
                    @endif
                    class="group flex min-w-0 flex-1 items-start gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    <span
                        @class([
                            'flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-semibold tabular-nums',
                            'border-brand bg-brand text-on-brand' => $item['state'] === 'complete',
                            'border-brand bg-brand-soft text-brand-ink ring-2 ring-brand/30' => $item['state'] === 'current',
                            'border-line bg-surface-raised text-ink-subtle' => $item['state'] === 'upcoming',
                        ])
                        @if ($reactive)
                            x-bind:class="{{ $state }} > {{ $item['number'] }}
                                ? 'border-brand bg-brand text-on-brand'
                                : ({{ $state }} === {{ $item['number'] }}
                                    ? 'border-brand bg-brand-soft text-brand-ink ring-2 ring-brand/30'
                                    : 'border-line bg-surface-raised text-ink-subtle')"
                        @endif
                    >
                        @if ($reactive)
                            <span x-show="{{ $state }} > {{ $item['number'] }}" @if ($item['state'] !== 'complete') x-cloak @endif class="contents">
                                <x-ui.icon name="check" class="size-4" />
                            </span>
                            <span x-show="{{ $state }} <= {{ $item['number'] }}" @if ($item['state'] === 'complete') x-cloak @endif>{{ $item['number'] }}</span>
                        @elseif ($item['state'] === 'complete')
                            <x-ui.icon name="check" class="size-4" />
                        @else
                            {{ $item['number'] }}
                        @endif
                    </span>

                    <span class="min-w-0 pt-0.5">
                        <span
                            @class([
                                'block truncate text-sm font-medium',
                                'text-ink' => $item['state'] !== 'upcoming',
                                'text-ink-muted' => $item['state'] === 'upcoming',
                            ])
                            @if ($reactive)
                                x-bind:class="{{ $state }} >= {{ $item['number'] }} ? 'text-ink' : 'text-ink-muted'"
                            @endif
                        >{{ $item['label'] }}</span>

                        <span
                            class="sr-only"
                            @if ($reactive)
                                x-text="{{ $state }} > {{ $item['number'] }}
                                    ? '— {{ __('completed') }}'
                                    : ({{ $state }} === {{ $item['number'] }} ? '— {{ __('current step') }}' : '— {{ __('not started') }}')"
                            @endif
                        >
                            @if ($item['state'] === 'complete')
                                — {{ __('completed') }}
                            @elseif ($item['state'] === 'current')
                                — {{ __('current step') }}
                            @else
                                — {{ __('not started') }}
                            @endif
                        </span>

                        @if ($item['description'])
                            <span class="mt-0.5 block truncate text-xs text-ink-muted">{{ $item['description'] }}</span>
                        @endif
                    </span>
                </{{ $tag }}>

                @unless ($loop->last)
                    <span
                        aria-hidden="true"
                        @class([
                            'mt-4 hidden h-px flex-1 lg:block',
                            'bg-brand' => $item['state'] === 'complete',
                            'bg-line' => $item['state'] !== 'complete',
                        ])
                        @if ($reactive)
                            x-bind:class="{{ $state }} > {{ $item['number'] }} ? 'bg-brand' : 'bg-line'"
                        @endif
                    ></span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
