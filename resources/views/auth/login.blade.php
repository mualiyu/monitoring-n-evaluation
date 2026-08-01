{{--
    Sign in (Fortify: POST /login).

    Routes are not wired yet, so form targets are built from Fortify's configured
    prefix rather than route() — url() cannot fail on an unregistered name.

    Optional view data:
      $throttleSeconds  int — renders the lockout state and disables submit while
                        the countdown runs (Fortify's rate limiter supplies this).
--}}
@php
    $title = __('Sign in');
    $heading = __('Sign in');
    $subheading = __('Use the official work email address your administrator registered.');

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));

    $throttleSeconds = $throttleSeconds ?? null;

    // The workspace switcher deep-links here as /login?email=… so a user moving
    // between MDA hosts does not retype it (auth-surfaces.md §1.5, mitigation 3).
    // Display-only, escaped by Blade — it never pre-authenticates anything.
    $prefilledEmail = old('email', request()->query('email'));
    $passkeysEnabled = class_exists(\Laravel\Fortify\Features::class)
        && \Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::passkeys());
@endphp

@extends('layouts.auth')

@section('content')
    @if ($throttleSeconds)
        {{-- Rate-limit lockout: the countdown is presentational; the server is the
             one enforcing it. Submit stays disabled until it reaches zero. --}}
        <div x-data="{ remaining: {{ (int) $throttleSeconds }} }" x-init="setInterval(() => remaining > 0 && remaining--, 1000)" class="mb-5">
            <x-ui.alert variant="warning" :title="__('Too many sign-in attempts')">
                <p x-show="remaining > 0">
                    {{ __('For security, this account is locked for') }}
                    <span class="font-semibold tabular-nums" x-text="remaining"></span>
                    {{ __('more seconds.') }}
                </p>
                <p x-show="remaining === 0" x-cloak>{{ __('You can try again now.') }}</p>
                <p class="mt-1">{{ __('If this was not you, contact your platform administrator.') }}</p>
            </x-ui.alert>
        </div>
    @endif

    <form method="POST" action="{{ $path('login') }}" class="space-y-5">
        @csrf

        <x-ui.form.group name="email" :label="__('Work email address')" required>
            <x-ui.form.input
                name="email"
                type="email"
                value="{{ $prefilledEmail }}"
                autocomplete="username"
                inputmode="email"
                autocapitalize="none"
                spellcheck="false"
                required
                :autofocus="blank($prefilledEmail)"
            />
        </x-ui.form.group>

        <x-ui.form.group name="password" :label="__('Password')" required>
            {{-- Arriving from the workspace switcher with the email filled in:
                 put the caret where the user actually has to type. --}}
            <x-ui.form.input
                name="password"
                type="password"
                autocomplete="current-password"
                required
                :autofocus="filled($prefilledEmail)"
            />
        </x-ui.form.group>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <x-ui.form.checkbox
                name="remember"
                :label="__('Keep me signed in')"
                :description="__('Do not use on a shared or public device.')"
                :checked="(bool) old('remember')"
            />

            <a
                href="{{ $path('forgot-password') }}"
                class="rounded text-sm font-medium text-brand-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >{{ __('Forgot your password?') }}</a>
        </div>

        <x-ui.button type="submit" class="w-full" icon="logout" :disabled="(bool) $throttleSeconds">
            {{ __('Sign in') }}
        </x-ui.button>
    </form>

    @if ($passkeysEnabled)
        {{-- Passkey sign-in is enabled in config/fortify.php. The WebAuthn ceremony
             still needs its JS wiring; the button is intentionally inert until then. --}}
        <div class="mt-6 space-y-4">
            <div class="flex items-center gap-3">
                <span class="h-px flex-1 bg-line"></span>
                <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">{{ __('or') }}</span>
                <span class="h-px flex-1 bg-line"></span>
            </div>

            <x-ui.button variant="secondary" class="w-full" icon="shield-check" data-passkey-signin>
                {{ __('Sign in with a passkey') }}
            </x-ui.button>

            <p class="text-center text-xs text-ink-muted">
                {{ __('Recommended for oversight and administrator accounts.') }}
            </p>
        </div>
    @endif
@endsection

@section('below-card')
    {{ __('Need access? Your MDA administrator creates accounts for staff and consultants.') }}
@endsection
