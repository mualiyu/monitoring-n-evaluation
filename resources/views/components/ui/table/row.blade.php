{{--
    <x-ui.table.row /> — a table row on desktop, a card on mobile.
    Pass wire:key on every row inside a Livewire loop.
--}}
@props([
    'muted' => false,
])

<tr
    {{ $attributes->class([
        // mobile: card
        'mb-3 block rounded-xl border border-line bg-surface-raised p-4 shadow-e1 last:mb-0',
        // sm+: table row
        'sm:mb-0 sm:table-row sm:rounded-none sm:border-0 sm:border-b sm:border-line sm:p-0 sm:shadow-none sm:transition-colors sm:hover:bg-surface-sunken',
        'opacity-70' => $muted,
    ]) }}
>
    {{ $slot }}
</tr>
