{{--
    <x-ui.card /> — the standard surface. Everything on an app screen sits in one.

    Props
      title / subtitle   optional header text (title renders as the given heading level)
      as                 heading level for the title (h2 default)
      padding            true | false — turn off for flush tables/maps
      flush              alias for :padding="false"

    Slots
      $actions   right-hand header controls (filters, export, "New …")
      $slot      body
      $footer    pagination / meta row

      <x-ui.card title="Ongoing projects" subtitle="Ministry of Works & Infrastructure">
          <x-slot:actions><x-ui.button size="sm" icon="arrow-down-tray">Export</x-ui.button></x-slot:actions>
          …
      </x-ui.card>
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'as' => 'h2',
    'padding' => true,
    'flush' => false,
])

@php
    $hasHeader = filled($title) || filled($subtitle) || isset($actions);
    $pad = $padding && ! $flush;
@endphp

<section {{ $attributes->class('rounded-xl border border-line bg-surface-raised shadow-e1') }}>
    @if ($hasHeader)
        <header class="flex flex-col gap-3 border-b border-line px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="min-w-0">
                @if ($title)
                    <{{ $as }} class="truncate text-base font-semibold text-ink">{{ $title }}</{{ $as }}>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-ink-muted">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['px-4 py-4 sm:px-5' => $pad])>
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="border-t border-line px-4 py-3 sm:px-5">{{ $footer }}</footer>
    @endisset
</section>
