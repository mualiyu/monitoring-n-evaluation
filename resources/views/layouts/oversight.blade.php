{{--
    State-level oversight shell (apex + oversight. subdomain).

    Same sidebar pattern as the MDA workspace, but deliberately distinguishable:
      - denser rhythm (56px topbar, 16rem sidebar, compact nav) because these users
        live in cross-MDA tables all day;
      - tinted page surface via `.surface-oversight` (token-derived, re-skins itself);
      - a labelled "State-level oversight" chip, so the distinction is never colour
        alone — you always know which surface you are on before you click Approve.

    Usage: #[Layout('layouts::oversight')] or @@extends('layouts.oversight').
    Variables: $title, $navigation, $userRole.
--}}
@php
    $tenant = $tenant ?? null;
    $userRole = $userRole ?? null;
    $density = 'compact';

    // Live badge for the assurance queue. The Action owns the cross-MDA read,
    // the authorization behind it and a 60-second cache — a nav badge renders
    // on every page of this surface, so it must never cost a scan per view.
    // It returns null for a user without oversight authority: chrome is the
    // wrong place to raise an authorization exception, and the screens behind
    // the badge do that themselves.
    $awaitingReview = auth()->check()
        ? (new \App\Actions\Oversight\CountReportsAwaitingReview)(auth()->user())
        : null;

    $navigation = $navigation ?? [
        ['items' => [
            [
                'label' => __('State dashboard'),
                'icon' => 'squares',
                'href' => url('/'),
                'active' => request()->routeIs('oversight.dashboard'),
            ],
        ]],
        ['label' => __('Portfolio'), 'items' => [
            [
                'label' => __('All projects'),
                'icon' => 'folder',
                'href' => url('/portfolio'),
                'active' => request()->routeIs('oversight.portfolio.*') || request()->routeIs('oversight.projects.*'),
            ],
            ['label' => __('Budget performance'), 'icon' => 'banknotes', 'href' => '#', 'disabled' => true],
            ['label' => __('Sector analysis'), 'icon' => 'chart-bar', 'href' => '#', 'disabled' => true],
            ['label' => __('Project map'), 'icon' => 'map-pin', 'href' => '#', 'disabled' => true],
        ]],
        ['label' => __('Assurance'), 'items' => [
            [
                'label' => __('Reporting compliance'),
                'icon' => 'chart-bar',
                'href' => url('/compliance'),
                'active' => request()->routeIs('oversight.compliance.*'),
            ],
            [
                'label' => __('Reports awaiting review'),
                'icon' => 'document-text',
                'href' => url('/compliance'),
                // Null (no oversight authority) and zero (nothing pending)
                // both render without a chip — a badge that says "0" is noise.
                'badge' => $awaitingReview ?: null,
            ],
            ['label' => __('Evaluations'), 'icon' => 'clipboard-check', 'href' => '#', 'disabled' => true],
            ['label' => __('Publishing queue'), 'icon' => 'globe', 'href' => '#', 'badge' => 3, 'disabled' => true],
        ]],
        ['label' => __('Administration'), 'items' => [
            ['label' => __('Entities & workspaces'), 'icon' => 'building-office', 'href' => '#', 'disabled' => true],
            [
                'label' => __('Vendor registry'),
                'icon' => 'clipboard-check',
                'href' => url('/contractors'),
                'active' => request()->routeIs('oversight.contractors.*'),
            ],
            ['label' => __('Users & roles'), 'icon' => 'users', 'href' => '#', 'disabled' => true],
            ['label' => __('Indicator library'), 'icon' => 'adjustments', 'href' => '#', 'disabled' => true],
            ['label' => __('Audit log'), 'icon' => 'shield-check', 'href' => '#', 'disabled' => true],
            ['label' => __('Instance settings'), 'icon' => 'cog', 'href' => '#', 'disabled' => true],
        ]],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
</head>
<body class="surface-oversight min-h-dvh bg-surface font-sans text-ink">
    <x-ui.skip-link />

    <div x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false">
        <div
            x-show="sidebarOpen"
            x-cloak
            x-transition.opacity
            x-on:click="sidebarOpen = false"
            class="fixed inset-0 z-40 bg-surface-inverse/50 lg:hidden"
            aria-hidden="true"
        ></div>

        <aside
            id="primary-navigation"
            x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-50 flex w-64 max-w-[85vw] flex-col border-r border-line bg-surface-raised transition-transform duration-200 lg:translate-x-0! lg:max-w-none"
        >
            <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-line px-3">
                <x-ui.brand href="#" :subtitle="__('State-level oversight')" />

                <button
                    type="button"
                    x-on:click="sidebarOpen = false"
                    class="rounded-lg p-2 text-ink-muted hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden"
                >
                    <x-ui.icon name="x-mark" class="size-5" />
                    <span class="sr-only">{{ __('Close navigation') }}</span>
                </button>
            </div>

            @include('layouts.partials.sidebar-nav', ['density' => 'compact'])
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line bg-surface/85 px-3 backdrop-blur sm:px-5">
                <button
                    type="button"
                    x-on:click="sidebarOpen = true"
                    x-bind:aria-expanded="sidebarOpen ? 'true' : 'false'"
                    aria-controls="primary-navigation"
                    class="-ml-1 rounded-lg p-2 text-ink-muted hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden"
                >
                    <x-ui.icon name="bars-3" class="size-6" />
                    <span class="sr-only">{{ __('Open navigation') }}</span>
                </button>

                {{-- Surface identity: icon + text, never colour alone. --}}
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-brand-soft px-2 py-1 text-xs font-semibold text-brand-ink ring-1 ring-brand/30 ring-inset">
                    <x-ui.icon name="shield-check" class="size-3.5" />
                    <span class="hidden sm:inline">{{ __('State-level oversight') }}</span>
                    <span class="sm:hidden">{{ __('Oversight') }}</span>
                </span>

                <p class="min-w-0 flex-1 truncate text-sm font-medium text-ink-muted">{{ $title ?? '' }}</p>

                <x-ui.theme-toggle size="sm" />
                <x-ui.user-menu :role="$userRole ?? __('Oversight administrator')" />
            </header>

            <main id="main-content" tabindex="-1" class="px-3 py-5 focus:outline-none sm:px-5 lg:px-6">
                @include('layouts.partials.two-factor-notice')

                {{ $slot ?? '' }}
                @yield('content')
            </main>

            <footer class="border-t border-line px-3 py-3 text-xs text-ink-muted sm:px-5 lg:px-6">
                {{ __('Cross-entity data. Every view, export and approval is attributed and logged.') }}
            </footer>
        </div>
    </div>

    @livewireScripts
</body>
</html>
