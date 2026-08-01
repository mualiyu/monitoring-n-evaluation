{{--
    <x-ui.empty-state /> — designed empty screen. Never ship a blank table body.

    Three flavours via :variant —
      empty     nothing exists yet → CTA to create the first record
      filtered  records exist but the filters exclude them → CTA to clear filters
      error     something failed → CTA to retry (wire:click="$refresh")

    Props: variant, icon, title, description, compact
    Slots:  $actions (buttons), $slot (extra help text / links)
--}}
@props([
    'variant' => 'empty',
    'icon' => null,
    'title' => null,
    'description' => null,
    'compact' => false,
])

@php
    $defaults = match ($variant) {
        'filtered' => ['icon' => 'funnel', 'title' => __('No matching records'), 'tone' => 'neutral'],
        'error' => ['icon' => 'exclamation-triangle', 'title' => __('Something went wrong'), 'tone' => 'critical'],
        default => ['icon' => 'inbox', 'title' => __('Nothing here yet'), 'tone' => 'brand'],
    };

    $iconTone = match ($defaults['tone']) {
        'critical' => 'bg-critical-soft text-critical-ink',
        'neutral' => 'bg-neutral-soft text-neutral-ink',
        default => 'bg-brand-soft text-brand-ink',
    };
@endphp

<div
    role="{{ $variant === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->class([
        'flex flex-col items-center justify-center px-6 text-center',
        'py-8' => $compact,
        'py-14' => ! $compact,
    ]) }}
>
    <span class="flex size-12 items-center justify-center rounded-full {{ $iconTone }}">
        @isset($iconSlot)
            {{ $iconSlot }}
        @else
            <x-ui.icon :name="$icon ?? $defaults['icon']" class="size-6" />
        @endisset
    </span>

    <h3 class="mt-4 text-base font-semibold text-ink">{{ $title ?? $defaults['title'] }}</h3>

    @if ($description)
        <p class="mt-1 max-w-md text-sm text-ink-muted">{{ $description }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-2 max-w-md text-sm text-ink-muted">{{ $slot }}</div>
    @endif

    @isset($actions)
        <div class="mt-5 flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:justify-center">{{ $actions }}</div>
    @endisset
</div>
