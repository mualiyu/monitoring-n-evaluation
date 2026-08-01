{{--
    MDA workspace shell (tenant subdomain surface).

    Two ways in:
      Livewire   #[Layout('layouts::tenant', ['title' => 'Projects'])]  → fills $slot
      Blade      @@extends('layouts.tenant') + @@section('content')

    Variables (all optional, pass via layoutData / view data / @@php before @@extends):
      $title        document + fallback page title
      $tenant       resolved tenant model (brand lockup, per-tenant token overrides)
      $navigation   sidebar structure — see layouts/partials/sidebar-nav.blade.php
      $userRole     label under the user's name in the topbar

    Nothing here names a state, ministry or product: all of it comes from the tenant
    record or config/platform.php.
--}}
@php
    $tenant = $tenant ?? null;
    $userRole = $userRole ?? null;

    // Tenants carry a type enum (Ministry / Department / Agency); its label is the
    // honest subtitle for the workspace. Defensive: the shell also renders for a
    // null tenant (previews, tests) and terminology is per-instance configurable.
    $tenantType = data_get($tenant, 'type');
    $workspaceLabel = is_object($tenantType) && method_exists($tenantType, 'label')
        ? __(':type workspace', ['type' => $tenantType->label()])
        : __('Workspace');

    // Placeholder structure until the route names exist; hrefs stay '#' deliberately.
    $navigation = $navigation ?? [
        ['items' => [
            [
                'label' => __('Dashboard'),
                'icon' => 'squares',
                'href' => url('/'),
                'active' => request()->routeIs('tenant.dashboard'),
            ],
            [
                'label' => __('Switch workspace'),
                'icon' => 'arrows-right-left',
                'href' => url('/workspaces'),
                'active' => request()->routeIs('tenant.workspaces'),
            ],
        ]],
        ['label' => __('Delivery'), 'items' => [
            [
                'label' => __('Projects'),
                'icon' => 'folder',
                'href' => url('/projects'),
                'active' => request()->routeIs('tenant.projects.*'),
            ],
            [
                'label' => __('Contractors'),
                'icon' => 'building-office',
                'href' => url('/contractors'),
                'active' => request()->routeIs('tenant.contractors.*'),
            ],
        ]],
        ['label' => __('Monitoring'), 'items' => [
            ['label' => __('Progress reports'), 'icon' => 'document-text', 'href' => '#', 'badge' => 6, 'disabled' => true],
            ['label' => __('Site inspections'), 'icon' => 'clipboard-check', 'href' => '#', 'disabled' => true],
            ['label' => __('Field visits'), 'icon' => 'map-pin', 'href' => '#', 'disabled' => true],
        ]],
        ['label' => __('Evaluation'), 'items' => [
            ['label' => __('Indicators'), 'icon' => 'chart-bar', 'href' => '#', 'disabled' => true],
            ['label' => __('Evaluations'), 'icon' => 'clipboard-check', 'href' => '#', 'disabled' => true],
            ['label' => __('Stakeholder feedback'), 'icon' => 'chat-bubble', 'href' => '#', 'disabled' => true],
        ]],
        ['label' => __('Administration'), 'collapsed' => true, 'items' => [
            ['label' => __('Users & roles'), 'icon' => 'users', 'href' => '#', 'disabled' => true],
            ['label' => __('Workspace settings'), 'icon' => 'cog', 'href' => '#', 'disabled' => true],
        ]],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-dvh bg-surface font-sans text-ink">
    <x-ui.skip-link />

    <div x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false">
        {{-- Mobile drawer scrim --}}
        <div
            x-show="sidebarOpen"
            x-cloak
            x-transition.opacity
            x-on:click="sidebarOpen = false"
            class="fixed inset-0 z-40 bg-surface-inverse/50 lg:hidden"
            aria-hidden="true"
        ></div>

        {{-- Sidebar / off-canvas drawer --}}
        <aside
            id="primary-navigation"
            x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col border-r border-line bg-surface-raised transition-transform duration-200 lg:translate-x-0! lg:max-w-none"
        >
            <div class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-line px-4">
                <x-ui.brand :tenant="$tenant" href="#" :subtitle="$workspaceLabel" />

                <button
                    type="button"
                    x-on:click="sidebarOpen = false"
                    class="rounded-lg p-2 text-ink-muted hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden"
                >
                    <x-ui.icon name="x-mark" class="size-5" />
                    <span class="sr-only">{{ __('Close navigation') }}</span>
                </button>
            </div>

            @include('layouts.partials.sidebar-nav')

            <div class="shrink-0 border-t border-line p-3">
                <x-ui.nav-item icon="question-mark-circle" href="#">{{ __('Help & guidance') }}</x-ui.nav-item>
            </div>
        </aside>

        <div class="lg:pl-72">
            {{-- Topbar --}}
            <header class="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-line bg-surface/85 px-4 backdrop-blur sm:px-6">
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

                <p class="min-w-0 flex-1 truncate text-sm font-medium text-ink-muted">
                    {{ $title ?? data_get($tenant, 'name') ?? __('Workspace') }}
                </p>

                <x-ui.theme-toggle />

                <button
                    type="button"
                    class="relative rounded-lg p-2 text-ink-muted transition-colors hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    <x-ui.icon name="bell" class="size-5" />
                    <span class="absolute top-1.5 right-1.5 size-2 rounded-full bg-critical ring-2 ring-surface"></span>
                    <span class="sr-only">{{ __('Notifications (unread)') }}</span>
                </button>

                <x-ui.user-menu :role="$userRole" />
            </header>

            <main id="main-content" tabindex="-1" class="px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                @include('layouts.partials.two-factor-notice')

                {{ $slot ?? '' }}
                @yield('content')
            </main>

            <footer class="border-t border-line px-4 py-4 text-xs text-ink-muted sm:px-6 lg:px-8">
                {{ __('Official use — activity on this workspace is logged for audit.') }}
            </footer>
        </div>
    </div>

    @livewireScripts
</body>
</html>
