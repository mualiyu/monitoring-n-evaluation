{{--
    <x-ui.button /> — the only button in the platform.

    Props
      variant  primary | secondary | ghost | destructive
      size     sm | md   (md = 44px tall: the field-monitor thumb target)
      href     renders an <a> instead of a <button>
      icon / trailingIcon   <x-ui.icon> names
      iconOnly bool — pass an aria-label with it, always
      loading  true (any Livewire request) | 'methodName' (wire:target) — shows the
               spinner and disables the control while in flight
      <x-slot:spinner> overrides the default spinner markup

    Examples
      <x-ui.button icon="plus" wire:click="create">New project</x-ui.button>
      <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray" :href="route('exports')">Excel</x-ui.button>
      <x-ui.button variant="destructive" loading="deleteReport">Delete report</x-ui.button>
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'trailingIcon' => null,
    'iconOnly' => false,
    'loading' => false,
    'disabled' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium leading-none whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:pointer-events-none disabled:opacity-55 aria-disabled:pointer-events-none aria-disabled:opacity-55';

    $sizes = [
        'sm' => $iconOnly ? 'size-9 text-sm' : 'h-9 px-3 text-sm',
        'md' => $iconOnly ? 'size-11 text-sm' : 'h-11 px-4 text-sm',
    ];

    $variants = [
        'primary' => 'bg-brand text-on-brand shadow-e1 hover:bg-brand-strong',
        'secondary' => 'border border-line bg-surface-raised text-ink shadow-e1 hover:border-line-strong hover:bg-surface-sunken',
        'ghost' => 'text-ink-muted hover:bg-neutral-soft hover:text-ink',
        'destructive' => 'bg-critical-strong text-on-critical shadow-e1 hover:brightness-95 dark:hover:brightness-110',
    ];

    $classes = trim($base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']));

    $iconSize = $size === 'sm' ? 'size-4' : 'size-[1.125rem]';
    $tag = $href ? 'a' : 'button';

    $wireAttributes = [];
    if ($loading !== false) {
        $wireAttributes['wire:loading.attr'] = 'disabled';
        $wireAttributes['wire:loading.class'] = 'cursor-wait';
        if (is_string($loading)) {
            $wireAttributes['wire:target'] = $loading;
        }
    }
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @disabled($disabled) @endif
    @if ($disabled && $href) aria-disabled="true" @endif
    {{ $attributes->merge($wireAttributes)->class($classes) }}
>
    @if ($loading !== false)
        <span
            wire:loading.delay
            @if (is_string($loading)) wire:target="{{ $loading }}" @endif
            class="contents"
        >
            @isset($spinner)
                {{ $spinner }}
            @else
                <x-ui.icon name="arrow-path" class="{{ $iconSize }} animate-spin" />
                <span class="sr-only">{{ __('Working…') }}</span>
            @endisset
        </span>
    @endif

    @if ($icon)
        <x-ui.icon :name="$icon" class="{{ $iconSize }}" />
    @endif

    @if ($iconOnly)
        <span class="sr-only">{{ $slot }}</span>
    @else
        {{ $slot }}
    @endif

    @if ($trailingIcon)
        <x-ui.icon :name="$trailingIcon" class="{{ $iconSize }}" />
    @endif
</{{ $tag }}>
