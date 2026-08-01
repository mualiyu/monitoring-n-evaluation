{{--
    <x-ui.page-header /> — h1 + context + primary actions, first thing in <main>.
    Kept out of the shells so each screen owns its own actions and breadcrumbs.

    Props
      title, description, back (href for a back link), backLabel
      breadcrumbs  [['label' => …, 'href' => …], …] — last item is the current page

    Slots: $actions
--}}
@props([
    'title' => '',
    'description' => null,
    'back' => null,
    'backLabel' => null,
    'breadcrumbs' => [],
])

<header {{ $attributes->class('mb-6 space-y-3') }}>
    @if (filled($breadcrumbs))
        <nav aria-label="{{ __('Breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-1 text-sm text-ink-muted">
                @foreach ($breadcrumbs as $crumb)
                    <li class="flex items-center gap-1">
                        @if (! $loop->first)
                            <x-ui.icon name="chevron-right" class="size-3.5 text-ink-subtle" />
                        @endif

                        @if (($crumb['href'] ?? null) && ! $loop->last)
                            <a href="{{ $crumb['href'] }}" class="rounded hover:text-ink hover:underline">{{ $crumb['label'] ?? '' }}</a>
                        @else
                            <span @if ($loop->last) aria-current="page" @endif class="text-ink">{{ $crumb['label'] ?? '' }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    @if ($back)
        <a href="{{ $back }}" class="inline-flex items-center gap-1.5 rounded text-sm font-medium text-ink-muted hover:text-ink">
            <x-ui.icon name="arrow-left" class="size-4" />
            {{ $backLabel ?? __('Back') }}
        </a>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold tracking-tight text-ink sm:text-2xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 max-w-3xl text-sm text-ink-muted">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</header>
