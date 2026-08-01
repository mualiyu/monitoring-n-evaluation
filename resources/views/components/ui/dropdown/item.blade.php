{{--
    <x-ui.dropdown.item /> — a single menu entry (link or button).

    Props: href, icon, destructive
--}}
@props([
    'href' => null,
    'icon' => null,
    'destructive' => false,
])

@php
    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="button" @endif
    role="menuitem"
    {{ $attributes->class([
        'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus',
        'text-ink hover:bg-neutral-soft' => ! $destructive,
        'text-critical-ink hover:bg-critical-soft' => $destructive,
    ]) }}
>
    @if ($icon)
        <x-ui.icon :name="$icon" class="size-4 shrink-0" />
    @endif
    <span class="min-w-0 flex-1 truncate">{{ $slot }}</span>
</{{ $tag }}>
