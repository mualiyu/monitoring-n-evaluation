{{--
    <x-ui.form.error /> — inline validation message: icon + text, announced politely.

    Reads the shared $errors bag (works for both classic requests and Livewire
    validation). Pass :message to force one, e.g. a domain rule rejected in an Action.

    Props: name, id, message
      state  OPTIONAL Alpine expression holding a client-side error string (e.g.
             "codeError"). The message shows/hides reactively and uses identical
             markup, so fetch-driven forms don't hand-roll their own error styling.

        <x-ui.form.error name="code" state="codeError" />
--}}
@props([
    'name' => null,
    'id' => null,
    'message' => null,
    'state' => null,
])

@php
    // ($errors ?? null): the bag is only shared by the `web` middleware group.
    $bag = $errors ?? null;
    $resolved = $message ?: ($name && $bag?->has($name) ? $bag->first($name) : null);
    $errorId = $id ?? ($name ? trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-').'-error' : null);
@endphp

@if (filled($state))
    {{-- x-if (not x-show) so the node is inserted on error: role="alert" only
         announces reliably when the element enters the accessibility tree. --}}
    <template x-if="{{ $state }}">
        <p
            @if ($errorId) id="{{ $errorId }}" @endif
            role="alert"
            aria-live="polite"
            {{ $attributes->class('flex items-start gap-1.5 text-sm font-medium text-critical-ink') }}
        >
            <x-ui.icon name="exclamation-circle" class="mt-0.5 size-4 shrink-0" />
            <span x-text="{{ $state }}"></span>
        </p>
    </template>
@elseif ($resolved)
    <p
        @if ($errorId) id="{{ $errorId }}" @endif
        role="alert"
        aria-live="polite"
        {{ $attributes->class('flex items-start gap-1.5 text-sm font-medium text-critical-ink') }}
    >
        <x-ui.icon name="exclamation-circle" class="mt-0.5 size-4 shrink-0" />
        <span>{{ $resolved }}</span>
    </p>
@endif
