{{--
    Two-factor challenge (Fortify: POST /two-factor-challenge).

    One form, two modes. The inactive input is disabled so the browser never submits
    an empty companion field, and focus moves to whichever input is showing.
--}}
@php
    $title = __('Two-factor authentication');
    $heading = __('Confirm it is you');
    $subheading = __('Two-factor authentication is required for this account.');

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));
@endphp

@extends('layouts.auth')

@section('content')
    <form
        method="POST"
        action="{{ $path('two-factor-challenge') }}"
        x-data="{
            recovery: false,
            toggle() {
                this.recovery = ! this.recovery
                $nextTick(() => (this.recovery ? $refs.recovery : $refs.code).focus())
            },
        }"
        class="space-y-5"
    >
        @csrf

        {{-- Authenticator code --}}
        <div x-show="! recovery">
            <x-ui.form.group
                name="code"
                :label="__('Authentication code')"
                :hint="__('Open your authenticator app and enter the current 6-digit code.')"
                required
            >
                <x-ui.form.input
                    name="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]*"
                    maxlength="6"
                    autocapitalize="none"
                    spellcheck="false"
                    has-hint
                    autofocus
                    x-ref="code"
                    x-bind:disabled="recovery"
                    class="text-center text-lg tracking-[0.5em] tabular-nums"
                />
            </x-ui.form.group>
        </div>

        {{-- Recovery code --}}
        <div x-show="recovery" x-cloak>
            <x-ui.form.group
                name="recovery_code"
                :label="__('Recovery code')"
                :hint="__('Use one of the one-time codes you saved when you set up two-factor authentication.')"
                required
            >
                <x-ui.form.input
                    name="recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    autocapitalize="none"
                    spellcheck="false"
                    has-hint
                    x-ref="recovery"
                    x-bind:disabled="! recovery"
                    class="font-mono"
                />
            </x-ui.form.group>
        </div>

        <x-ui.button type="submit" class="w-full" icon="shield-check">{{ __('Verify and continue') }}</x-ui.button>

        <div class="text-center">
            <button
                type="button"
                x-on:click="toggle()"
                class="rounded text-sm font-medium text-brand-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <span x-show="! recovery">{{ __('Lost your device? Use a recovery code') }}</span>
                <span x-show="recovery" x-cloak>{{ __('Use an authenticator code instead') }}</span>
            </button>
        </div>
    </form>
@endsection

@section('below-card')
    {{ __('Out of recovery codes? Your administrator can reset two-factor authentication after identity checks.') }}
@endsection
