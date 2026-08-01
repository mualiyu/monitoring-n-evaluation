{{--
    Choose a new password (Fortify: POST /reset-password).

    Fortify passes $request (with the signed token) into this view. The email is
    carried in a readonly field so the user can see which account they are resetting
    without being able to point the reset at another address.
--}}
@php
    $title = __('Choose a new password');
    $heading = __('Choose a new password');
    $subheading = __('This link can only be used once. Choose a password you have not used on this platform before.');

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));

    $resetToken = $token ?? request()->route('token') ?? request()->input('token');
    $resetEmail = old('email', request()->input('email'));
@endphp

@extends('layouts.auth')

@section('content')
    <form method="POST" action="{{ $path('reset-password') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $resetToken }}">

        <x-ui.form.group name="email" :label="__('Work email address')">
            <x-ui.form.input
                name="email"
                type="email"
                value="{{ $resetEmail }}"
                autocomplete="username"
                readonly
            />
        </x-ui.form.group>

        <x-ui.form.group
            name="password"
            :label="__('New password')"
            :hint="__('Use at least 12 characters. A short phrase of unrelated words is stronger than a single complex word.')"
            required
        >
            <x-ui.form.input
                name="password"
                type="password"
                autocomplete="new-password"
                has-hint
                required
                autofocus
            />
        </x-ui.form.group>

        <x-ui.form.group name="password_confirmation" :label="__('Confirm new password')" required>
            <x-ui.form.input
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                required
            />
        </x-ui.form.group>

        <x-ui.button type="submit" class="w-full" icon="check-circle">{{ __('Save new password') }}</x-ui.button>

        <div class="text-center">
            <a
                href="{{ $path('login') }}"
                class="inline-flex items-center gap-1.5 rounded text-sm font-medium text-brand-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <x-ui.icon name="arrow-left" class="size-4" />
                {{ __('Back to sign in') }}
            </a>
        </div>
    </form>
@endsection
