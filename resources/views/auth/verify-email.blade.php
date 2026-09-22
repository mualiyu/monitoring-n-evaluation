{{--
    Email verification notice (Fortify: GET /email/verify). Served on the app
    surfaces to a signed-in account whose address is not yet verified; the
    resend success message is rendered by layouts.auth from session('status').
--}}
@php
    $title = __('Verify your email address');
    $heading = __('Verify your email address');
    $subheading = __('We sent a verification link to :email. Open it on this device to confirm the address is yours.', [
        'email' => auth()->user()?->email ?? __('your email address'),
    ]);

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));
@endphp

@extends('layouts.auth')

@section('content')
    <div class="space-y-5">
        <p class="text-sm text-ink-muted">
            {{ __('The link expires after :minutes minutes. If it has expired, or the message never arrived, send a new one — check your spam folder first.', ['minutes' => config('auth.verification.expire', 60)]) }}
        </p>

        <form method="POST" action="{{ $path('email/verification-notification') }}">
            @csrf
            <x-ui.button type="submit" class="w-full" icon="paper-airplane">{{ __('Send the link again') }}</x-ui.button>
        </form>

        <form method="POST" action="{{ $path('logout') }}" class="text-center">
            @csrf
            <button type="submit" class="rounded text-sm font-medium text-ink-muted underline-offset-2 hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                {{ __('Sign out') }}
            </button>
        </form>
    </div>
@endsection
