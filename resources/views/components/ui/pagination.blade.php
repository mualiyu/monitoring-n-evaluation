{{--
    <x-ui.pagination /> — tokenised paginator. Laravel's bundled pagination views
    are hard-coded grey/white Tailwind, which would be the one off-palette element
    on every list screen (and would not survive a re-skin), so lists use this.

    Props
      paginator  LengthAwarePaginator|Paginator
      wire       true  → Livewire (buttons + gotoPage, no full page load)
                 false → plain links (query-string filters survive either way)
      label      accessible name for the <nav>

        <x-ui.pagination :paginator="$this->projects" />
--}}
@props([
    'paginator' => null,
    'wire' => true,
    'label' => null,
])

@php
    $hasPages = $paginator && $paginator->hasPages();
    $elements = method_exists($paginator, 'links') && $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator
        ? $paginator->onEachSide(1)->getUrlRange(
            max($paginator->currentPage() - 2, 1),
            min($paginator->currentPage() + 2, $paginator->lastPage()),
        )
        : [];

    $itemClasses = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
@endphp

@if ($paginator)
    <div class="flex flex-col items-center justify-between gap-3 sm:flex-row">
        {{-- Always show the count, even on a single page: "is that everything?"
             is the first question a reviewer asks of a list. --}}
        <p class="text-sm text-ink-muted">
            @if ($paginator->total() > 0)
                {{ __('Showing :from–:to of :total', [
                    'from' => number_format($paginator->firstItem() ?? 0),
                    'to' => number_format($paginator->lastItem() ?? 0),
                    'total' => number_format($paginator->total()),
                ]) }}
            @else
                {{ __('No results') }}
            @endif
        </p>

        @if ($hasPages)
            <nav aria-label="{{ $label ?? __('Pagination') }}" class="flex items-center gap-1">
                {{-- Previous --}}
                @if ($paginator->onFirstPage())
                    <span class="{{ $itemClasses }} cursor-not-allowed text-ink-subtle" aria-disabled="true">
                        <x-ui.icon name="chevron-left" class="size-4" />
                        <span class="ml-1 hidden sm:inline">{{ __('Previous') }}</span>
                    </span>
                @elseif ($wire)
                    <button type="button" wire:click="previousPage" wire:loading.attr="disabled" class="{{ $itemClasses }} border border-line bg-surface-raised text-ink hover:bg-surface-sunken">
                        <x-ui.icon name="chevron-left" class="size-4" />
                        <span class="ml-1 hidden sm:inline">{{ __('Previous') }}</span>
                    </button>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $itemClasses }} border border-line bg-surface-raised text-ink hover:bg-surface-sunken">
                        <x-ui.icon name="chevron-left" class="size-4" />
                        <span class="ml-1 hidden sm:inline">{{ __('Previous') }}</span>
                    </a>
                @endif

                {{-- Page numbers (desktop only — thumbs get Previous/Next) --}}
                <span class="hidden items-center gap-1 sm:inline-flex">
                    @if (($paginator->currentPage() - 2) > 1)
                        <span class="{{ $itemClasses }} text-ink-subtle" aria-hidden="true">…</span>
                    @endif

                    @foreach ($elements as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $itemClasses }} bg-brand text-on-brand tabular-nums" aria-current="page">
                                {{ $page }}<span class="sr-only"> ({{ __('current page') }})</span>
                            </span>
                        @elseif ($wire)
                            <button type="button" wire:click="gotoPage({{ $page }})" class="{{ $itemClasses }} text-ink-muted tabular-nums hover:bg-neutral-soft hover:text-ink">
                                {{ $page }}<span class="sr-only"> ({{ __('go to page :page', ['page' => $page]) }})</span>
                            </button>
                        @else
                            <a href="{{ $url }}" class="{{ $itemClasses }} text-ink-muted tabular-nums hover:bg-neutral-soft hover:text-ink">
                                {{ $page }}<span class="sr-only"> ({{ __('go to page :page', ['page' => $page]) }})</span>
                            </a>
                        @endif
                    @endforeach

                    @if (($paginator->currentPage() + 2) < $paginator->lastPage())
                        <span class="{{ $itemClasses }} text-ink-subtle" aria-hidden="true">…</span>
                    @endif
                </span>

                {{-- Mobile page counter --}}
                <span class="px-2 text-sm text-ink-muted tabular-nums sm:hidden">
                    {{ __(':current / :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
                </span>

                {{-- Next --}}
                @if (! $paginator->hasMorePages())
                    <span class="{{ $itemClasses }} cursor-not-allowed text-ink-subtle" aria-disabled="true">
                        <span class="mr-1 hidden sm:inline">{{ __('Next') }}</span>
                        <x-ui.icon name="chevron-right" class="size-4" />
                    </span>
                @elseif ($wire)
                    <button type="button" wire:click="nextPage" wire:loading.attr="disabled" class="{{ $itemClasses }} border border-line bg-surface-raised text-ink hover:bg-surface-sunken">
                        <span class="mr-1 hidden sm:inline">{{ __('Next') }}</span>
                        <x-ui.icon name="chevron-right" class="size-4" />
                    </button>
                @else
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $itemClasses }} border border-line bg-surface-raised text-ink hover:bg-surface-sunken">
                        <span class="mr-1 hidden sm:inline">{{ __('Next') }}</span>
                        <x-ui.icon name="chevron-right" class="size-4" />
                    </a>
                @endif
            </nav>
        @endif
    </div>
@endif
