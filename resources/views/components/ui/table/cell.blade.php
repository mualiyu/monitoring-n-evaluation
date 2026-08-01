{{--
    <x-ui.table.cell /> — cell that carries its own label for the mobile card layout.

    Props
      label     column name, shown only below `sm:` (required for readable cards)
      align     left | right | center — applies from `sm:` up
      numeric   tabular figures + right alignment (use for ₦ amounts and counts)
      primary   emphasises the value (use on the row's identifying column)
      stacked   full-width on mobile instead of the label/value pair (long text)
--}}
@props([
    'label' => null,
    'align' => 'left',
    'numeric' => false,
    'primary' => false,
    'stacked' => false,
])

@php
    $alignment = $numeric ? 'right' : $align;

    $alignClasses = match ($alignment) {
        'right' => 'sm:text-right',
        'center' => 'sm:text-center',
        default => 'sm:text-left',
    };

    $valueAlignment = match ($alignment) {
        'right' => 'text-right',
        'center' => 'sm:text-center',
        default => '',
    };
@endphp

<td
    {{ $attributes->class([
        'gap-3 py-1.5 text-sm text-ink first:pt-0 last:pb-0',
        'flex items-baseline justify-between' => ! $stacked,
        'block' => $stacked,
        'sm:table-cell sm:px-4 sm:py-3 sm:first:pt-3 sm:last:pb-3 sm:align-middle',
        $alignClasses,
        'font-semibold' => $primary,
        'tabular-nums' => $numeric,
    ]) }}
>
    @if ($label)
        <span class="shrink-0 text-xs font-medium tracking-wide text-ink-muted uppercase sm:hidden">{{ $label }}</span>
    @endif
    {{-- div, not span: cells legitimately contain menus, badges and progress bars. --}}
    <div class="min-w-0 {{ $valueAlignment }}">{{ $slot }}</div>
</td>
