{{--
    The portal's only write.

    A plain <form method="POST">, deliberately not Livewire: it works with
    JavaScript off, the route's `throttle:` middleware genuinely applies to it
    (a Livewire update POST never travels through a portal route's middleware),
    and the whole write stays on one named, testable route.

    $project is either null or a PublicProjectPayload array — the route resolves
    the `?project=` ULID under the published predicate, so an unpublished or
    unknown ULID simply arrives as null and the form becomes a general comment.
    Nothing a visitor types decides which record this attaches to.

    THE HONEYPOT is the `website` field below. It is hidden from sight AND from
    assistive technology, and removed from the tab order, so only automation
    reaches it. A filled honeypot passes validation on purpose: the bot is
    answered with the ordinary thank-you page and told nothing, because a bot
    that learns which submissions were dropped is a bot that gets tuned.
--}}
@php
    $title = __('Give feedback');
    $submittedProject = $project['ulid'] ?? old('project');
@endphp

@extends('layouts.portal')

@section('content')
    <x-ui.page-header
        :title="__('Tell the monitoring team what you see')"
        :description="__('Report stalled, substandard or abandoned work, or tell the state that something has been delivered well. Every comment is read by the entity responsible and by the state monitoring secretariat.')"
        :breadcrumbs="array_values(array_filter([
            ['label' => __('Home'), 'href' => route('portal.home')],
            $project ? ['label' => __('Published projects'), 'href' => route('portal.projects.index')] : null,
            $project ? ['label' => $project['reference'], 'href' => route('portal.projects.show', ['ulid' => $project['ulid']])] : null,
            ['label' => __('Give feedback')],
        ]))"
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($errors->any())
                <x-ui.alert variant="critical" class="mb-4" :title="__('Your comment was not sent')">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            <x-ui.card>
                <form method="POST" action="{{ route('portal.feedback.store') }}" class="space-y-5">
                    @csrf

                    @if ($project)
                        <div class="rounded-lg border border-line bg-surface-sunken p-3">
                            <p class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('About this project') }}</p>
                            <p class="mt-1 text-sm font-semibold text-ink">{{ $project['title'] }}</p>
                            <p class="mt-0.5 font-mono text-xs text-ink-subtle">{{ $project['reference'] }}</p>
                        </div>
                    @endif

                    {{-- The PUBLIC ulid, never an id. The POST handler re-resolves
                         it under the published predicate; an unknown value simply
                         produces an unattached comment. --}}
                    <input type="hidden" name="project" value="{{ $submittedProject }}" />

                    <x-ui.form.group
                        name="subject"
                        :label="__('What is this about?')"
                        :hint="__('A short summary, e.g. “Work stopped at the Ward 3 clinic”.')"
                        required
                    >
                        <x-ui.form.input
                            name="subject"
                            type="text"
                            maxlength="180"
                            required
                            has-hint
                            :value="old('subject')"
                            autocomplete="off"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="body"
                        :label="__('What have you seen?')"
                        :hint="__('Dates, the exact location and what is actually on the ground help the monitoring officer more than anything else.')"
                        required
                    >
                        <x-ui.form.textarea
                            name="body"
                            rows="7"
                            maxlength="4000"
                            required
                            has-hint
                        >{{ old('body') }}</x-ui.form.textarea>
                    </x-ui.form.group>

                    <fieldset class="space-y-4 border-t border-line pt-5">
                        <legend class="text-sm font-semibold text-ink">{{ __('How can we reach you? (optional)') }}</legend>
                        <p class="text-sm text-ink-muted">
                            {{ __('You may leave all of this blank. Anonymous feedback is read the same way — contact details only let the entity reply to you directly.') }}
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.form.group name="name" :label="__('Name')" optional>
                                <x-ui.form.input name="name" type="text" maxlength="120" :value="old('name')" autocomplete="name" />
                            </x-ui.form.group>

                            <x-ui.form.group name="phone" :label="__('Telephone')" optional>
                                <x-ui.form.input name="phone" type="tel" maxlength="32" :value="old('phone')" autocomplete="tel" />
                            </x-ui.form.group>
                        </div>

                        <x-ui.form.group name="email" :label="__('Email address')" optional>
                            <x-ui.form.input name="email" type="email" maxlength="180" :value="old('email')" autocomplete="email" />
                        </x-ui.form.group>
                    </fieldset>

                    {{-- Honeypot. Hidden from sight (display:none), from assistive
                         technology (aria-hidden) and from the keyboard (tabindex
                         -1). A person never reaches it; automation fills it, and
                         App\Actions\Feedback\SubmitFeedback drops the submission
                         without saying so. --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="portal-website">{{ __('Leave this field empty') }}</label>
                        <input
                            type="text"
                            name="website"
                            id="portal-website"
                            value=""
                            tabindex="-1"
                            autocomplete="off"
                        />
                    </div>

                    <div class="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-ink-muted">
                            {{ __('Nothing you write appears on this website until a monitoring officer has reviewed it.') }}
                        </p>

                        <x-ui.button type="submit" icon="paper-airplane">{{ __('Send to the monitoring team') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <aside class="space-y-4">
            <x-ui.card :title="__('What happens next')">
                <ol class="space-y-4 text-sm">
                    <li class="flex items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">1</span>
                        <span class="text-ink-muted">{{ __('Your comment reaches the entity delivering the project and the state monitoring secretariat.') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">2</span>
                        <span class="text-ink-muted">{{ __('A monitoring officer reviews it. Nothing is published automatically.') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">3</span>
                        <span class="text-ink-muted">{{ __('If it is published, it appears under the project with any official response attached.') }}</span>
                    </li>
                </ol>
            </x-ui.card>

            <x-ui.card :title="__('Before you write')">
                <ul class="space-y-3 text-sm text-ink-muted">
                    <li class="flex items-start gap-2">
                        <x-ui.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        {{ __('Say exactly where. A ward, a landmark or a road name is enough.') }}
                    </li>
                    <li class="flex items-start gap-2">
                        <x-ui.icon name="calendar-days" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        {{ __('Say when you saw it. “Nothing since March” is a finding; “it is slow” is not.') }}
                    </li>
                    <li class="flex items-start gap-2">
                        <x-ui.icon name="shield-check" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        {{ __('Please do not name private individuals. Comments that do are not published.') }}
                    </li>
                </ul>
            </x-ui.card>

            @if ($project)
                <x-ui.card>
                    <x-ui.button
                        :href="route('portal.projects.show', ['ulid' => $project['ulid']])"
                        variant="secondary"
                        icon="arrow-left"
                        class="w-full"
                    >{{ __('Back to the project') }}</x-ui.button>
                </x-ui.card>
            @endif
        </aside>
    </div>
@endsection
