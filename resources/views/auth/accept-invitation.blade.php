{{-- Invitation acceptance (stub styling — auth polish pass will refine). --}}
@php
    $title = __('Accept invitation');
@endphp

@extends('layouts.portal')

@section('content')
    <div class="mx-auto max-w-lg py-12">
        <x-ui.card>
            <h1 class="text-lg font-semibold text-ink">
                {{ __('Join :target', ['target' => $invitation->tenant?->name ?? __('the oversight workspace')]) }}
            </h1>
            <p class="mt-2 text-sm text-ink-muted">
                {{ __('You were invited as :role (:email).', ['role' => $invitation->role->label(), 'email' => $invitation->email]) }}
            </p>

            <form method="POST" action="{{ url()->current() }}" class="mt-6 space-y-4">
                @csrf

                @if ($needsAccount)
                    <x-ui.form.group :label="__('Full name')" name="name">
                        <x-ui.form.input name="name" required autofocus autocomplete="name" />
                    </x-ui.form.group>

                    <x-ui.form.group :label="__('Password')" name="password" :hint="__('At least 12 characters with letters, numbers and symbols.')">
                        <x-ui.form.input type="password" name="password" required autocomplete="new-password" />
                    </x-ui.form.group>

                    <x-ui.form.group :label="__('Confirm password')" name="password_confirmation">
                        <x-ui.form.input type="password" name="password_confirmation" required autocomplete="new-password" />
                    </x-ui.form.group>
                @else
                    <p class="text-sm text-ink-muted">
                        {{ __('This invitation is linked to your existing account — accepting adds the new workspace to it.') }}
                    </p>
                @endif

                <x-ui.button type="submit">{{ __('Accept invitation') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
@endsection
