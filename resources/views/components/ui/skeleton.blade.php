{{--
    <x-ui.skeleton /> — shimmer placeholder for wire:loading and lazy components.

    Always pair with wire:loading so the user sees structure, not a white flash:

        <div wire:loading.delay.class.remove="hidden" class="hidden">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

    Props
      variant  text | table | card | stat | chart | block
      lines    number of text lines (text variant)
      rows     number of rows (table variant)
      height   Tailwind height class for the chart and block variants

    The shimmer animation is disabled automatically under prefers-reduced-motion.
--}}
@props([
    'variant' => 'text',
    'lines' => 3,
    'rows' => 4,
    'height' => 'h-56',
])

<div {{ $attributes->class('w-full') }} aria-hidden="true" data-loading-placeholder>
    <span class="sr-only">{{ __('Loading…') }}</span>

    @switch($variant)
        @case('table')
            <div class="space-y-3">
                @for ($row = 0; $row < (int) $rows; $row++)
                    <div class="flex items-center gap-4 rounded-lg border border-line p-4 sm:border-0 sm:p-0">
                        <div class="ui-skeleton h-4 w-2/5 rounded"></div>
                        <div class="ui-skeleton hidden h-4 w-1/5 rounded sm:block"></div>
                        <div class="ui-skeleton hidden h-4 w-1/6 rounded sm:block"></div>
                        <div class="ui-skeleton ml-auto h-6 w-20 rounded-md"></div>
                    </div>
                @endfor
            </div>
            @break

        @case('card')
            <div class="rounded-xl border border-line bg-surface-raised p-4 shadow-e1">
                <div class="ui-skeleton h-4 w-1/3 rounded"></div>
                <div class="ui-skeleton mt-3 h-3 w-full rounded"></div>
                <div class="ui-skeleton mt-2 h-3 w-4/5 rounded"></div>
                <div class="ui-skeleton mt-4 h-8 w-28 rounded-lg"></div>
            </div>
            @break

        @case('stat')
            <div class="rounded-xl border border-line bg-surface-raised p-4 shadow-e1">
                <div class="ui-skeleton h-3 w-24 rounded"></div>
                <div class="ui-skeleton mt-3 h-7 w-32 rounded"></div>
                <div class="ui-skeleton mt-3 h-3 w-20 rounded"></div>
            </div>
            @break

        @case('chart')
            <div class="rounded-xl border border-line bg-surface-raised p-4 shadow-e1">
                <div class="ui-skeleton h-3 w-32 rounded"></div>
                <div class="ui-skeleton mt-4 w-full rounded-lg {{ $height }}"></div>
            </div>
            @break

        @case('block')
            {{-- Generic box: QR codes, map panes, image uploads. --}}
            <div class="ui-skeleton w-full rounded-lg {{ $height }}"></div>
            @break

        @default
            <div class="space-y-2">
                @for ($line = 0; $line < (int) $lines; $line++)
                    <div class="ui-skeleton h-3 rounded {{ $line === (int) $lines - 1 ? 'w-2/3' : 'w-full' }}"></div>
                @endfor
            </div>
    @endswitch
</div>
