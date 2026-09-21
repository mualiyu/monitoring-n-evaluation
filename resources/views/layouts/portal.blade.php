{{--
    Public transparency portal shell (apex domain).

    Top-nav rather than sidebar: citizens arrive on one page from a link, not from a
    workspace. Read-only surface — no auth chrome beyond the feedback call to action.

    Variables (all optional):
      $title           document + page title
      $tenant          publishing entity (brand lockup)
      $portalNav       [['label' => …, 'href' => …, 'active' => bool], …]
      $footerColumns   [['heading' => …, 'links' => [['label' => …, 'href' => …]]]]
      $footerNote      small print under the columns
      $leaflet         true on the ONE screen that needs a map library

    Escape hatches for branding: @@section('footer-brand') and @@section('footer-extra').

    LEAFLET LIVES HERE AND NOWHERE ELSE. The stack decision puts the map library on a
    CDN; App\Http\Middleware\PortalSecurityHeaders allow-lists exactly that origin for
    this surface, and no other layout on the platform allows it. It is opt-in per page
    (`$leaflet`) because a 150KB map library on the feedback form is 150KB of somebody's
    data bundle spent on nothing. The map itself degrades to a plain list in <noscript>,
    so the library never becomes load-bearing.
--}}
@php
    $tenant = $tenant ?? null;
    $leaflet = $leaflet ?? false;

    $portalNav = $portalNav ?? [
        ['label' => __('Home'), 'href' => route('portal.home'), 'active' => request()->routeIs('portal.home')],
        ['label' => __('Projects'), 'href' => route('portal.projects.index'), 'active' => request()->routeIs('portal.projects.*')],
        ['label' => __('Map'), 'href' => route('portal.map'), 'active' => request()->routeIs('portal.map')],
        ['label' => __('Published reports'), 'href' => route('portal.reports.index'), 'active' => request()->routeIs('portal.reports.*')],
        ['label' => __('Give feedback'), 'href' => route('portal.feedback.create'), 'active' => request()->routeIs('portal.feedback.*')],
    ];

    $footerColumns = $footerColumns ?? [
        ['heading' => __('Explore'), 'links' => [
            ['label' => __('All published projects'), 'href' => route('portal.projects.index')],
            ['label' => __('Project map'), 'href' => route('portal.map')],
            ['label' => __('Published reports'), 'href' => route('portal.reports.index')],
        ]],
        ['heading' => __('Participate'), 'links' => [
            ['label' => __('Report an issue on a project'), 'href' => route('portal.feedback.create')],
        ]],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')

    @if ($leaflet)
        {{-- Pinned version + subresource integrity: an immutable unpkg URL and a
             hash the browser checks before executing anything. A CDN that starts
             serving something else serves it to nobody. --}}
        <link
            rel="stylesheet"
            href="{{ \App\Http\Middleware\PortalSecurityHeaders::LEAFLET_CDN }}/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin="anonymous"
            referrerpolicy="no-referrer"
        />
        <script
            defer
            src="{{ \App\Http\Middleware\PortalSecurityHeaders::LEAFLET_CDN }}/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin="anonymous"
            referrerpolicy="no-referrer"
        ></script>
    @endif
</head>
<body class="flex min-h-dvh flex-col bg-surface font-sans text-ink">
    <x-ui.skip-link />

    <header x-data="{ menuOpen: false }" class="sticky top-0 z-30 border-b border-line bg-surface-raised/90 backdrop-blur">
        <div class="mx-auto flex h-16 w-full max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
            <x-ui.brand :tenant="$tenant" :href="route('portal.home')" :subtitle="__('Public project transparency')" class="flex-1" />

            <nav aria-label="{{ __('Portal navigation') }}" class="hidden items-center gap-1 md:flex">
                @foreach ($portalNav as $link)
                    <a
                        href="{{ $link['href'] ?? '#' }}"
                        @if ($link['active'] ?? false) aria-current="page" @endif
                        @class([
                            'rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                            'bg-brand-soft text-brand-ink' => $link['active'] ?? false,
                            'text-ink-muted hover:bg-neutral-soft hover:text-ink' => ! ($link['active'] ?? false),
                        ])
                    >{{ $link['label'] ?? '' }}</a>
                @endforeach
            </nav>

            <x-ui.theme-toggle size="sm" />

            <button
                type="button"
                x-on:click="menuOpen = ! menuOpen"
                x-bind:aria-expanded="menuOpen ? 'true' : 'false'"
                aria-controls="portal-navigation"
                class="rounded-lg p-2 text-ink-muted hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus md:hidden"
            >
                <x-ui.icon name="bars-3" class="size-6" x-show="! menuOpen" />
                <x-ui.icon name="x-mark" class="size-6" x-show="menuOpen" x-cloak />
                <span class="sr-only">{{ __('Toggle menu') }}</span>
            </button>
        </div>

        {{-- The mobile menu is rendered by Alpine on click, so it must also exist
             without JavaScript: a portal whose navigation needs a runtime is a
             portal that does not work on half the phones it is built for. --}}
        <nav
            id="portal-navigation"
            x-show="menuOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            aria-label="{{ __('Portal navigation') }}"
            class="border-t border-line px-4 py-3 md:hidden"
        >
            <ul class="space-y-1">
                @foreach ($portalNav as $link)
                    <li>
                        <a
                            href="{{ $link['href'] ?? '#' }}"
                            @if ($link['active'] ?? false) aria-current="page" @endif
                            @class([
                                'block rounded-lg px-3 py-2 text-sm font-medium',
                                'bg-brand-soft text-brand-ink' => $link['active'] ?? false,
                                'text-ink-muted hover:bg-neutral-soft hover:text-ink' => ! ($link['active'] ?? false),
                            ])
                        >{{ $link['label'] ?? '' }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <noscript>
            <nav aria-label="{{ __('Portal navigation') }}" class="border-t border-line px-4 py-3 md:hidden">
                <ul class="space-y-1">
                    @foreach ($portalNav as $link)
                        <li>
                            <a href="{{ $link['href'] ?? '#' }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-ink-muted hover:bg-neutral-soft hover:text-ink">
                                {{ $link['label'] ?? '' }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </noscript>
    </header>

    <main id="main-content" tabindex="-1" class="flex-1 focus:outline-none">
        <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>

    <footer class="border-t border-line bg-surface-sunken">
        <div class="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="grid gap-8 md:grid-cols-4">
                <div class="space-y-3">
                    @hasSection('footer-brand')
                        @yield('footer-brand')
                    @else
                        <x-ui.brand :tenant="$tenant" />
                        <p class="max-w-xs text-sm text-ink-muted">
                            {{ $footerNote ?? __('Published project data is reviewed and approved before it appears here.') }}
                        </p>
                    @endif
                </div>

                @foreach ($footerColumns as $column)
                    <nav aria-labelledby="footer-col-{{ $loop->index }}">
                        <h2 id="footer-col-{{ $loop->index }}" class="text-sm font-semibold text-ink">{{ $column['heading'] ?? '' }}</h2>
                        <ul class="mt-3 space-y-2">
                            @foreach ($column['links'] ?? [] as $link)
                                <li>
                                    <a href="{{ $link['href'] ?? '#' }}" class="rounded text-sm text-ink-muted hover:text-ink hover:underline">
                                        {{ $link['label'] ?? '' }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
            </div>

            @hasSection('footer-extra')
                <div class="mt-8 border-t border-line pt-6">@yield('footer-extra')</div>
            @endif

            <div class="mt-8 flex flex-col gap-2 border-t border-line pt-6 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; {{ now()->year }} {{ data_get($tenant, 'name') ?? config('platform.instance.name') ?? config('app.name') }}</p>
                <p class="inline-flex items-center gap-1.5">
                    <x-ui.icon name="eye" class="size-3.5" />
                    {{ __('Read-only public data') }}
                </p>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
