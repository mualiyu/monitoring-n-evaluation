{{--
    <x-ui.stat /> — KPI tile for the summary row above every data table.

    Per the design system, every metric links to the list that explains it, so pass
    :href unless the number genuinely has no drill-down.

    Props
      label     what the number is (always visible, never a tooltip)
      value     the number itself, pre-formatted (₦, %, counts)
      hint      small clarifier under the value, e.g. "of ₦4.2bn appropriated"
      icon      <x-ui.icon> name
      delta     e.g. "+8.4%" — rendered with an arrow icon AND the direction in text
      trend     up | down | flat  (drives the icon)
      intent    positive | critical | neutral — colour of the delta; keep neutral
                when "up" is not automatically good (e.g. overdue reports)
      href      drill-down target

    Slots
      $slot (optional) replaces the delta row entirely — e.g. a sparkline.
--}}
@props([
    'label' => '',
    'value' => '—',
    'hint' => null,
    'icon' => null,
    'delta' => null,
    'trend' => null,
    'intent' => 'neutral',
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';

    $trendIcon = match ($trend) {
        'up' => 'arrow-trending-up',
        'down' => 'arrow-trending-down',
        'flat' => 'minus',
        default => null,
    };

    $trendLabel = match ($trend) {
        'up' => __('up'),
        'down' => __('down'),
        'flat' => __('no change'),
        default => null,
    };

    $intentClasses = match ($intent) {
        'positive' => 'text-positive-ink',
        'critical' => 'text-critical-ink',
        'warning' => 'text-warning-ink',
        default => 'text-ink-muted',
    };
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'group flex flex-col gap-2 rounded-xl border border-line bg-surface-raised p-4 shadow-e1 transition-colors',
        'hover:border-line-strong hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus' => (bool) $href,
    ]) }}
>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-ink-muted">{{ $label }}</p>
        @if ($icon)
            <x-ui.icon :name="$icon" class="size-5 text-ink-subtle" />
        @endif
    </div>

    <p class="text-2xl font-semibold tracking-tight text-ink tabular-nums sm:text-3xl">{{ $value }}</p>

    @if ($slot->isNotEmpty())
        <div class="text-sm text-ink-muted">{{ $slot }}</div>
    @elseif ($delta || $hint)
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            @if ($delta)
                <span class="inline-flex items-center gap-1 font-medium {{ $intentClasses }}">
                    @if ($trendIcon)
                        <x-ui.icon :name="$trendIcon" class="size-4" />
                    @endif
                    {{ $delta }}
                    @if ($trendLabel)
                        <span class="sr-only">({{ $trendLabel }})</span>
                    @endif
                </span>
            @endif
            @if ($hint)
                <span class="text-ink-muted">{{ $hint }}</span>
            @endif
        </p>
    @endif

    @if ($href)
        <span class="mt-auto inline-flex items-center gap-1 pt-1 text-sm font-medium text-brand-ink">
            {{ __('View details') }}
            <x-ui.icon name="chevron-right" class="size-4 transition-transform group-hover:translate-x-0.5" />
        </span>
    @endif
</{{ $tag }}>
