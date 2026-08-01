{{--
    <x-ui.user-menu /> — topbar account menu (placeholder targets until auth routes
    land; the sign-out item is a button so it can be wired to a POST form later).

    Props
      user   defaults to the authenticated user; falls back to a "Signed out" state
      role   role/label shown under the name, e.g. "M&E Officer"
--}}
@props([
    'user' => null,
    'role' => null,
])

@php
    $account = $user ?? auth()->user();
    $name = data_get($account, 'name') ?? __('Signed out');
    $email = data_get($account, 'email');
    $initials = collect(explode(' ', (string) $name))
        ->filter()
        ->take(2)
        ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
        ->implode('');
@endphp

<x-ui.dropdown align="right" :label="__('Account menu')" {{ $attributes }}>
    <x-slot:trigger>
        <button
            type="button"
            class="flex items-center gap-2 rounded-lg p-1 pr-2 transition-colors hover:bg-neutral-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink"
                aria-hidden="true"
            >{{ $initials ?: '—' }}</span>

            <span class="hidden min-w-0 text-left sm:block">
                <span class="block max-w-36 truncate text-sm font-medium text-ink">{{ $name }}</span>
                @if ($role)
                    <span class="block max-w-36 truncate text-xs text-ink-muted">{{ $role }}</span>
                @endif
            </span>

            <x-ui.icon name="chevron-down" class="size-4 text-ink-subtle" />
            <span class="sr-only">{{ __('Open account menu') }}</span>
        </button>
    </x-slot:trigger>

    <div class="border-b border-line px-3 py-2">
        <p class="truncate text-sm font-medium text-ink">{{ $name }}</p>
        @if ($email)
            <p class="truncate text-xs text-ink-muted">{{ $email }}</p>
        @endif
    </div>

    <div class="pt-1">
        <x-ui.dropdown.item icon="user-circle" href="#">{{ __('My profile') }}</x-ui.dropdown.item>
        <x-ui.dropdown.item icon="bell" href="#">{{ __('Notification preferences') }}</x-ui.dropdown.item>
        <x-ui.dropdown.item icon="shield-check" href="#">{{ __('Security & 2FA') }}</x-ui.dropdown.item>
        <x-ui.dropdown.item icon="logout" destructive>{{ __('Sign out') }}</x-ui.dropdown.item>
    </div>
</x-ui.dropdown>
