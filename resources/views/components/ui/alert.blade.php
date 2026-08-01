{{--
    <x-ui.alert /> — banner for flash messages, validation summaries and system
    notices. Icon + text (never colour alone), and the right ARIA role per severity:
    critical/warning announce assertively, info/positive politely.

    Props
      variant      info | positive | warning | critical | neutral
      title        bold first line (optional)
      icon         override the default icon
      dismissible  adds a close button (plain Alpine)

        <x-ui.alert variant="critical" :title="__('We could not sign you in')">
            {{ __('Check the email address and password and try again.') }}
        </x-ui.alert>
--}}
@props([
    'variant' => 'info',
    'title' => null,
    'icon' => null,
    'dismissible' => false,
])

@php
    $config = match ($variant) {
        'positive' => ['icon' => 'check-circle', 'class' => 'bg-positive-soft text-positive-ink ring-positive/30', 'role' => 'status', 'live' => 'polite'],
        'warning' => ['icon' => 'exclamation-triangle', 'class' => 'bg-warning-soft text-warning-ink ring-warning/40', 'role' => 'alert', 'live' => 'assertive'],
        'critical' => ['icon' => 'exclamation-circle', 'class' => 'bg-critical-soft text-critical-ink ring-critical/30', 'role' => 'alert', 'live' => 'assertive'],
        'neutral' => ['icon' => 'information-circle', 'class' => 'bg-neutral-soft text-neutral-ink ring-line', 'role' => 'status', 'live' => 'polite'],
        default => ['icon' => 'information-circle', 'class' => 'bg-info-soft text-info-ink ring-info/30', 'role' => 'status', 'live' => 'polite'],
    };
@endphp

<div
    @if ($dismissible) x-data="{ shown: true }" x-show="shown" @endif
    role="{{ $config['role'] }}"
    aria-live="{{ $config['live'] }}"
    {{ $attributes->class(['flex items-start gap-3 rounded-xl px-4 py-3 text-sm ring-1 ring-inset', $config['class']]) }}
>
    <x-ui.icon :name="$icon ?? $config['icon']" class="mt-0.5 size-5 shrink-0" />

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        @if ($slot->isNotEmpty())
            <div @class(['mt-0.5' => (bool) $title])>{{ $slot }}</div>
        @endif
    </div>

    @if ($dismissible)
        <button
            type="button"
            x-on:click="shown = false"
            class="-m-1 shrink-0 rounded-lg p-1 transition-opacity hover:opacity-70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
            <x-ui.icon name="x-mark" class="size-4" />
            <span class="sr-only">{{ __('Dismiss') }}</span>
        </button>
    @endif
</div>
