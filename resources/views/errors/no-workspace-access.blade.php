{{--
    Surface-level 403: informative BY POLICY (the workspace's existence is
    already public via DNS; a silent 404 for a signed-in civil servant only
    creates support tickets). Record-level denials elsewhere stay silent 404s.
--}}
@php
    $title = __('No access to this workspace');
@endphp

@extends('layouts.portal')

@section('content')
    <div class="mx-auto max-w-lg py-12">
        <x-ui.card>
            <h1 class="text-lg font-semibold text-ink">
                {{ __('You don\'t have access to :name', ['name' => $tenant->name]) }}
            </h1>
            <p class="mt-2 text-sm text-ink-muted">
                {{ __('Your account is signed in, but it is not a member of this workspace. If you believe this is a mistake, contact the workspace administrator.') }}
            </p>

            @if ($workspaces->isNotEmpty())
                <h2 class="mt-6 text-sm font-semibold text-ink">{{ __('Your workspaces') }}</h2>
                <ul class="mt-2 divide-y divide-line">
                    @foreach ($workspaces as $membership)
                        <li class="py-2">
                            <a href="{{ $membership->tenant->url() }}" class="text-sm font-medium text-brand-ink underline-offset-2 hover:underline">
                                {{ $membership->tenant->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="/logout" class="mt-6">
                @csrf
                <x-ui.button type="submit" variant="secondary">{{ __('Sign out') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
@endsection
