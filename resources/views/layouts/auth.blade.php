{{--
    Authentication shell — centred card, no sidebar, works on all three surfaces.

    On a tenant subdomain the brand lockup shows WHICH workspace you are signing
    into (a user may hold accounts in several MDAs); elsewhere it falls back to the
    instance identity. Never names a state — everything comes from $tenant/config.

    Two ways in (same dual-mode as the other shells):
      Livewire   #[Layout('layouts::auth')]  → fills $slot
      Blade      @@extends('layouts.auth') + @@section('content')

    Variables (all optional):
      $title       document title
      $heading     <h1> inside the card
      $subheading  supporting line under the heading
      $tenant      resolved tenant (shared by ResolveTenant on tenant subdomains)

    Shared behaviour provided here so the four auth screens stay thin:
      - session('status') success banner (Fortify sets this after reset links etc.)
      - error summary listing every validation message, each linking to its field
        (ids match <x-ui.form.*>, which derives them from `name`)
--}}
@php
    $tenant = $tenant ?? null;

    $tenantType = data_get($tenant, 'type');
    $surfaceLabel = is_object($tenantType) && method_exists($tenantType, 'label')
        ? __(':type workspace', ['type' => $tenantType->label()])
        : __('Secure sign-in');

    $errorBag = $errors ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-dvh bg-surface font-sans text-ink">
    <x-ui.skip-link />

    <div class="flex min-h-dvh flex-col">
        <header class="mx-auto flex w-full max-w-lg items-center justify-between gap-3 px-4 pt-6 sm:pt-10">
            <x-ui.brand :tenant="$tenant" :subtitle="$surfaceLabel" />
            <x-ui.theme-toggle size="sm" />
        </header>

        <main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center px-4 py-8 focus:outline-none">
            <div class="space-y-4">
                @if (session('status'))
                    <x-ui.alert variant="positive" :title="__('Done')">{{ session('status') }}</x-ui.alert>
                @endif

                @if ($errorBag?->any())
                    <x-ui.alert
                        variant="critical"
                        :title="trans_choice('There is a problem with your submission|There are :count problems with your submission', $errorBag->count(), ['count' => $errorBag->count()])"
                    >
                        <ul class="mt-1 space-y-1">
                            @foreach ($errorBag->keys() as $field)
                                <li>
                                    <a
                                        href="#{{ trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $field), '-') }}"
                                        class="rounded font-medium underline underline-offset-2 hover:no-underline"
                                    >{{ $errorBag->first($field) }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif

                <x-ui.card>
                    @if (! empty($heading))
                        <h1 class="text-xl font-semibold tracking-tight text-ink">{{ $heading }}</h1>
                    @endif
                    @if (! empty($subheading))
                        <p class="mt-1 mb-5 text-sm text-ink-muted">{{ $subheading }}</p>
                    @else
                        <div class="mb-5"></div>
                    @endif

                    {{ $slot ?? '' }}
                    @yield('content')
                </x-ui.card>

                @hasSection('below-card')
                    <div class="text-center text-sm text-ink-muted">@yield('below-card')</div>
                @endif
            </div>
        </main>

        <footer class="mx-auto w-full max-w-lg px-4 pb-8 text-center">
            <p class="inline-flex items-start gap-1.5 text-xs text-ink-muted">
                <x-ui.icon name="shield-check" class="mt-0.5 size-3.5 shrink-0" />
                <span>{{ __('Authorised users only. Sign-in attempts and all activity on this platform are logged for audit.') }}</span>
            </p>
        </footer>
    </div>

    @livewireScripts
</body>
</html>
