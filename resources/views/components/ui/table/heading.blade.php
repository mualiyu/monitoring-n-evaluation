{{--
    <x-ui.table.heading /> — column header, optionally sortable.

    Sorting is a real <button> (keyboard reachable) and announces state via aria-sort.

    Props
      align     left | right | center
      sortable  bool
      sort      asc | desc | null — current direction for THIS column
      click     Livewire expression for wire:click, e.g. "sortBy('contract_sum')"
--}}
@props([
    'align' => 'left',
    'sortable' => false,
    'sort' => null,
    'click' => null,
])

@php
    $alignClasses = match ($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };

    $ariaSort = match ($sort) {
        'asc' => 'ascending',
        'desc' => 'descending',
        default => $sortable ? 'none' : null,
    };

    $sortIcon = match ($sort) {
        'asc' => 'chevron-up-down',
        'desc' => 'chevron-up-down',
        default => 'chevron-up-down',
    };
@endphp

<th
    scope="col"
    @if ($ariaSort) aria-sort="{{ $ariaSort }}" @endif
    {{ $attributes->class([
        'px-4 py-3 text-xs font-semibold tracking-wide text-ink-muted uppercase',
        $alignClasses,
    ]) }}
>
    @if ($sortable)
        <button
            type="button"
            @if ($click) wire:click="{{ $click }}" @endif
            class="inline-flex items-center gap-1 rounded transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
            <span>{{ $slot }}</span>
            <x-ui.icon :name="$sortIcon" class="size-3.5 {{ $sort ? 'text-brand-ink' : 'text-ink-subtle' }}" />
            <span class="sr-only">
                @if ($sort === 'asc')
                    {{ __('sorted ascending') }}
                @elseif ($sort === 'desc')
                    {{ __('sorted descending') }}
                @else
                    {{ __('not sorted') }}
                @endif
            </span>
        </button>
    @else
        {{ $slot }}
    @endif
</th>
