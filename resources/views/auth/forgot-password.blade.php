{{--
    Forgot password (Fortify: POST /forgot-password).
    The success message is rendered by layouts.auth from session('status').
--}}
@php
    $title = __('Reset your password');
    $heading = __('Reset your password');
    $subheading = __('We will email you a secure link to choose a new password. The link expires in 60 minutes.');

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));
@endphp

@extends('layouts.auth')

@section('content')
    <form method="POST" action="{{ $path('forgot-password') }}" class="space-y-5">
        @csrf

        <x-ui.form.group
            name="email"
            :label="__('Work email address')"
            :hint="__('Use the address your account was created with.')"
            required
        >
            <x-ui.form.input
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                inputmode="email"
                autocapitalize="none"
                spellcheck="false"
                has-hint
                required
                autofocus
            />
        </x-ui.form.group>

        <x-ui.button type="submit" class="w-full" icon="paper-airplane">{{ __('Email password reset link') }}</x-ui.button>

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

@section('below-card')
    {{ __('For security, we send the same confirmation whether or not the address is registered.') }}
@endsection
