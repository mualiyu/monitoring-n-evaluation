{{--
    <x-ui.nav-item /> — sidebar / drawer navigation link, shared by the tenant and
    oversight shells so the two never drift.

    Props
      href, icon, active, badge (count or short string), disabled
--}}
@props([
    'href' => '#',
    'icon' => null,
    'active' => false,
    'badge' => null,
    'disabled' => false,
])

<a
    href="{{ $disabled ? '#' : $href }}"
    @if ($active) aria-current="page" @endif
    @if ($disabled) aria-disabled="true" tabindex="-1" @endif
    {{ $attributes->class([
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
        'bg-brand-soft text-brand-ink' => $active,
        'text-ink-muted hover:bg-neutral-soft hover:text-ink' => ! $active && ! $disabled,
        'pointer-events-none text-ink-subtle opacity-60' => $disabled,
    ]) }}
>
    @if ($icon)
        <x-ui.icon :name="$icon" class="size-5 shrink-0" />
    @endif

    <span class="min-w-0 flex-1 truncate">{{ $slot }}</span>

    @if (filled($badge))
        <span class="shrink-0 rounded-full bg-critical-soft px-2 py-0.5 text-xs font-semibold text-critical-ink tabular-nums">
            {{ $badge }}
        </span>
    @endif

    @if ($active)
        <span class="sr-only">({{ __('current page') }})</span>
    @endif
</a>
