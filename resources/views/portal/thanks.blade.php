{{--
    The one answer this route gives.

    Every submission that reaches the POST handler ends here — the genuine one,
    and the one whose honeypot was filled. That is the point: the bot is told
    nothing it can learn from, and the person is told exactly what will happen
    next.
--}}
@php
    $title = __('Thank you');
@endphp

@extends('layouts.portal')

@section('content')
    <x-ui.card flush>
        <x-ui.empty-state
            icon="check-circle"
            :title="__('Thank you — your comment has been received')"
            :description="__('It has reached the entity delivering the project and the state monitoring secretariat. A monitoring officer reviews every comment; nothing appears on this website automatically.')"
        >
            <x-slot:actions>
                <x-ui.button :href="route('portal.projects.index')" icon="folder">
                    {{ __('Back to published projects') }}
                </x-ui.button>
                <x-ui.button :href="route('portal.home')" variant="secondary" icon="home">
                    {{ __('Portal home') }}
                </x-ui.button>
            </x-slot:actions>

            {{ __('If you left contact details, the entity may reply to you directly. If your comment is published, it will appear under the project with any official response attached.') }}
        </x-ui.empty-state>
    </x-ui.card>
@endsection
