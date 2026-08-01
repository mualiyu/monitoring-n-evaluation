{{--
    <x-ui.progress /> — physical / financial progress bar.

    The number is always printed next to the bar: progress is a figure a
    commissioner reads off a printed board pack, not a colour to squint at.

    Props
      value      0–100 (clamped; nulls render as "—", not as 0% — an unreported
                 project is not a project at 0%)
      label      accessible name, e.g. "Physical progress"
      size       sm | md
      intent     auto | brand | positive | warning | critical
                 auto = neutral brand fill; pass an intent only where the domain
                 genuinely judges the number (e.g. behind schedule)
      showValue  print the % beside the bar (default true)
--}}
@props([
    'value' => null,
    'label' => null,
    'size' => 'md',
    'intent' => 'auto',
    'showValue' => true,
])

@php
    $hasValue = $value !== null && $value !== '';
    $percent = $hasValue ? max(0, min(100, (float) $value)) : null;
    $display = $hasValue ? rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%' : '—';

    $fill = match ($intent) {
        'positive' => 'bg-positive',
        'warning' => 'bg-warning',
        'critical' => 'bg-critical',
        default => 'bg-brand',
    };

    $track = $size === 'sm' ? 'h-1.5' : 'h-2';
@endphp

<div {{ $attributes->class('flex items-center gap-2') }}>
    <div
        class="{{ $track }} w-full min-w-10 overflow-hidden rounded-full bg-neutral-soft"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        @if ($hasValue) aria-valuenow="{{ $percent }}" @endif
        aria-valuetext="{{ $hasValue ? $display : __('Not reported') }}"
        @if ($label) aria-label="{{ $label }}" @endif
    >
        @if ($hasValue)
            <div class="h-full rounded-full {{ $fill }}" style="width: {{ $percent }}%"></div>
        @endif
    </div>

    @if ($showValue)
        <span @class([
            'shrink-0 text-sm tabular-nums',
            'text-ink' => $hasValue,
            'text-ink-subtle' => ! $hasValue,
        ])>{{ $display }}</span>
    @endif
</div>
