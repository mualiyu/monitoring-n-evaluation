{{--
    Password confirmation gate (Fortify: POST /user/confirm-password).

    Reached two ways:
      1. a browser navigation into a password-protected route (Laravel's
         RequirePassword middleware redirects here and remembers the intended URL);
      2. as the no-JS fallback for the in-page confirmation modal on the two-factor
         setup screen.

    Wired via Fortify::confirmPasswordView(). The user is already signed in here —
    this re-proves identity before a security-sensitive change, so the copy says
    exactly that instead of looking like a second login.
--}}
@php
    $title = __('Confirm your password');
    $heading = __('Confirm your password');
    $subheading = __('You are about to change a security setting. Re-enter your password to continue.');

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));

    $account = auth()->user();
@endphp

@extends('layouts.auth')

@section('content')
    @if ($account)
        <p class="mb-5 flex items-center gap-2 rounded-lg bg-surface-sunken px-3 py-2 text-sm text-ink-muted">
            <x-ui.icon name="user-circle" class="size-4 shrink-0" />
            <span class="min-w-0 truncate">{{ $account->email ?? $account->name }}</span>
        </p>
    @endif

    <form method="POST" action="{{ $path('user/confirm-password') }}" class="space-y-5">
        @csrf

        <x-ui.form.group name="password" :label="__('Password')" required>
            <x-ui.form.input
                name="password"
                type="password"
                autocomplete="current-password"
                required
                autofocus
            />
        </x-ui.form.group>

        <x-ui.button type="submit" class="w-full" icon="shield-check">{{ __('Confirm') }}</x-ui.button>
    </form>
@endsection

@section('below-card')
    {{ __('We ask for this once every few hours, and again before any change to sign-in security.') }}
@endsection
