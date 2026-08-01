{{--
    Grace-period banner for mandated two-factor enrolment.

    RequireTwoFactor flashes `two_factor_deadline` (a Carbon instance) on every
    request while the user is still inside their grace window. Rendered in the
    tenant and oversight shells; the portal never sees it.

    Defensive about the flash payload's type — a Carbon, a timestamp and a plain
    date string all format correctly, and anything unparseable degrades to a
    deadline-free message rather than throwing on a page the user must reach.
--}}
@php
    $deadlineValue = session('two_factor_deadline');

    $deadline = null;
    if ($deadlineValue instanceof \DateTimeInterface) {
        $deadline = \Illuminate\Support\Carbon::instance($deadlineValue);
    } elseif (is_numeric($deadlineValue)) {
        $deadline = \Illuminate\Support\Carbon::createFromTimestamp((int) $deadlineValue);
    } elseif (is_string($deadlineValue) && filled($deadlineValue)) {
        $deadline = rescue(fn () => \Illuminate\Support\Carbon::parse($deadlineValue), null, false);
    }

    $deadline = $deadline?->timezone(config('app.timezone'));
@endphp

@if ($deadlineValue)
    <x-ui.alert
        variant="warning"
        dismissible
        class="mb-5"
        :title="$deadline
            ? __('Two-factor setup required by :date', ['date' => $deadline->translatedFormat('j F Y')])
            : __('Two-factor setup required')"
    >
        <p>
            {{ __('Your role requires an authenticator app. You can keep working until the deadline, after which sign-in will be blocked until setup is complete.') }}
            @if ($deadline)
                <span class="font-medium">{{ __('That is :time.', ['time' => $deadline->diffForHumans(['parts' => 1])]) }}</span>
            @endif
        </p>

        <p class="mt-2">
            <a
                href="{{ url('/two-factor/setup') }}"
                class="inline-flex items-center gap-1 rounded font-semibold underline underline-offset-2 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                {{ __('Set up two-factor authentication now') }}
                <x-ui.icon name="chevron-right" class="size-4" />
            </a>
        </p>
    </x-ui.alert>
@endif
