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

    /*
     | Sidebar structure. Two rules hold for every entry:
     |
     |  1. It points at a NAMED route. A hand-built url('/…') string 404s
     |     silently in production; route() fails loudly at render, which is
     |     how a broken link gets found before a permanent secretary finds it.
     |  2. It is permission-gated. An item a user would get a 403 from is
     |     worse than an absent one — it advertises a door that does not open.
     |
     | `can` is checked against the CURRENT workspace's permission team, so a
     | consultant's sidebar is genuinely a consultant's sidebar.
     */
    $user = auth()->user();
    $permits = fn (?string $permission): bool => $permission === null
        || ($user !== null && $user->can($permission));

    $navigation = $navigation ?? collect([
        ['items' => [
            [
                'label' => __('Dashboard'),
                'icon' => 'squares',
                'href' => route('tenant.dashboard'),
                'active' => request()->routeIs('tenant.dashboard'),
            ],
            [
                'label' => __('Switch workspace'),
                'icon' => 'arrows-right-left',
                'href' => route('tenant.workspaces'),
                'active' => request()->routeIs('tenant.workspaces'),
            ],
        ]],
        ['label' => __('Delivery'), 'items' => [
            [
                'label' => __('Projects'),
                'icon' => 'folder',
                'href' => route('tenant.projects.index'),
                'active' => request()->routeIs('tenant.projects.*'),
                'can' => 'projects.view',
            ],
            [
                'label' => __('Contractors'),
                'icon' => 'building-office',
                'href' => route('tenant.contractors.index'),
                'active' => request()->routeIs('tenant.contractors.*'),
                'can' => 'contractors.view',
            ],
            [
                'label' => __('Work plans'),
                'icon' => 'clipboard-check',
                'href' => route('tenant.workplans.index'),
                'active' => request()->routeIs('tenant.workplans.*'),
                'can' => 'workplans.view',
            ],
        ]],
        ['label' => __('Monitoring'), 'items' => [
            [
                'label' => __('Progress reports'),
                'icon' => 'document-text',
                'href' => route('tenant.reports.index'),
                'active' => request()->routeIs('tenant.reports.index')
                    || request()->routeIs('tenant.reports.show')
                    || request()->routeIs('tenant.reports.create')
                    || request()->routeIs('tenant.reports.edit'),
                'can' => 'reports.view',
            ],
            [
                'label' => __('Review inbox'),
                'icon' => 'inbox',
                'href' => route('tenant.reports.inbox'),
                'active' => request()->routeIs('tenant.reports.inbox'),
                'can' => 'reports.review',
            ],
            [
                'label' => __('M&E calendar'),
                'icon' => 'calendar-days',
                'href' => route('tenant.reports.calendar'),
                'active' => request()->routeIs('tenant.reports.calendar'),
                'can' => 'reports.view',
            ],
            [
                'label' => __('Site inspections'),
                'icon' => 'clipboard-check',
                'href' => route('tenant.inspections.index'),
                'active' => request()->routeIs('tenant.inspections.*'),
                'can' => 'inspections.view',
            ],
            [
                'label' => __('Issues register'),
                'icon' => 'exclamation-triangle',
                'href' => route('tenant.issues.index'),
                'active' => request()->routeIs('tenant.issues.*'),
                'can' => 'issues.view',
            ],
            [
                'label' => __('Exception reports'),
                'icon' => 'shield-check',
                'href' => route('tenant.exceptions.index'),
                'active' => request()->routeIs('tenant.exceptions.*'),
                'can' => 'exceptions.view',
            ],
        ]],
        ['label' => __('Results'), 'items' => [
            [
                'label' => __('Indicators'),
                'icon' => 'chart-bar',
                'href' => route('tenant.indicators.index'),
                'active' => request()->routeIs('tenant.indicators.*') || request()->routeIs('tenant.projects.framework'),
                'can' => 'indicators.view',
            ],
            [
                'label' => __('Evaluations'),
                'icon' => 'clipboard-check',
                'href' => route('tenant.evaluations.index'),
                'active' => request()->routeIs('tenant.evaluations.*'),
                'can' => 'evaluations.view',
            ],
            [
                'label' => __('Recommendations'),
                'icon' => 'adjustments',
                'href' => route('tenant.recommendations.index'),
                'active' => request()->routeIs('tenant.recommendations.*'),
                'can' => 'recommendations.view',
            ],
        ]],
        ['label' => __('Completion & transparency'), 'items' => [
            [
                'label' => __('Certificates'),
                'icon' => 'shield-check',
                'href' => route('tenant.certificates.index'),
                'active' => request()->routeIs('tenant.certificates.*'),
                'can' => 'certificates.view',
            ],
            [
                'label' => __('Publishing queue'),
                'icon' => 'globe',
                'href' => route('tenant.publishing.index'),
                'active' => request()->routeIs('tenant.publishing.*'),
                'can' => 'projects.publish',
            ],
            [
                'label' => __('Stakeholder feedback'),
                'icon' => 'chat-bubble',
                'href' => route('tenant.feedback.index'),
                'active' => request()->routeIs('tenant.feedback.*'),
                'can' => 'feedback.view',
            ],
        ]],
        ['label' => __('Administration'), 'collapsed' => ! request()->routeIs('tenant.team.*') && ! request()->routeIs('tenant.settings.*'), 'items' => [
            [
                'label' => __('Users & roles'),
                'icon' => 'users',
                'href' => route('tenant.team.index'),
                'active' => request()->routeIs('tenant.team.*'),
                'can' => 'users.view',
            ],
            [
                'label' => __('Workspace settings'),
                'icon' => 'cog',
                'href' => route('tenant.settings.index'),
                'active' => request()->routeIs('tenant.settings.*'),
                'can' => 'settings.manage',
            ],
        ]],
    ])
        // Drop the items this user may not reach, then drop any group left
        // empty by that filtering — a heading over nothing is a dead end.
        ->map(fn (array $group): array => [
            ...$group,
            'items' => array_values(array_filter(
                $group['items'] ?? [],
                fn (array $item): bool => $permits($item['can'] ?? null),
            )),
        ])
        ->filter(fn (array $group): bool => $group['items'] !== [])
        ->values()
        ->all();
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
                <x-ui.brand :tenant="$tenant" :href="url('/')" :subtitle="$workspaceLabel" />

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

            {{--
                "Help & guidance" pointed at href="#" with no route behind it.
                Dropped until there is a help screen to reach; a nav item that
                does nothing is worse than one absent from the sidebar.
            --}}
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

                {{--
                    The bell was a <button> with no action and a red dot that
                    was always on, whether or not anything was unread. It is
                    now the real notification centre: live unread count, recent
                    items, mark-read, and a link through to the full page.
                --}}
                <livewire:shared.notification-bell />

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
