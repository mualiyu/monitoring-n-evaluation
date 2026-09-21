{{--
    Topbar notification bell (App\Livewire\Shared\NotificationBell).
    Drop into either app shell's <header>, beside <x-ui.theme-toggle />:
        <livewire:shared.notification-bell />
--}}
<div wire:poll.visible.60s="$refresh" class="relative">
    <x-ui.dropdown align="right" width="w-80 sm:w-96">
        <x-slot:trigger>
            <button
                type="button"
                class="relative rounded-lg p-2 text-ink-muted transition-colors hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <x-ui.icon name="bell" class="size-5" />

                @if ($this->unreadCount > 0)
                    {{-- Icon + number + screen-reader text: never colour alone. --}}
                    <span class="absolute -top-0.5 -right-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-critical px-1 text-[10px] leading-4 font-semibold text-on-critical ring-2 ring-surface">
                        {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
                    </span>
                @endif

                <span class="sr-only">
                    {{ $this->unreadCount > 0
                        ? __('Notifications — :count unread', ['count' => $this->unreadCount])
                        : __('Notifications — none unread') }}
                </span>
            </button>
        </x-slot:trigger>

        <div class="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
            <p class="text-sm font-semibold text-ink">{{ __('Notifications') }}</p>

            @if ($this->unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllRead"
                    class="rounded text-xs font-medium text-ink-muted underline-offset-2 hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >{{ __('Mark all read') }}</button>
            @endif
        </div>

        @if ($this->recent->isEmpty())
            <p class="px-3 py-6 text-center text-sm text-ink-muted">{{ __('Nothing here yet.') }}</p>
        @else
            <ul class="max-h-96 divide-y divide-line overflow-y-auto">
                @foreach ($this->recent as $notification)
                    @php($link = $this->linkFor($notification))
                    <li wire:key="bell-{{ $notification->id }}" class="px-3 py-2.5 {{ $notification->read_at ? '' : 'bg-brand-soft/40' }}">
                        <div class="flex items-start gap-2">
                            <x-ui.icon
                                :name="$notification->read_at ? 'inbox' : 'bell'"
                                class="mt-0.5 size-4 shrink-0 text-ink-subtle"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink">{{ $this->headlineFor($notification) }}</p>
                                <p class="truncate text-xs text-ink-muted">{{ $this->summaryFor($notification) }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-subtle">
                                    <span>{{ $notification->created_at?->diffForHumans() }}</span>
                                    @if ($link)
                                        <a href="{{ $link }}" class="rounded font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ __('Open') }}</a>
                                    @endif
                                    @unless ($notification->read_at)
                                        <button
                                            type="button"
                                            wire:click="markRead('{{ $notification->id }}')"
                                            class="rounded font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        >{{ __('Mark read') }}</button>
                                    @endunless
                                </p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($this->centreUrl())
            <div class="border-t border-line px-3 py-2">
                <a
                    href="{{ $this->centreUrl() }}"
                    class="flex items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium text-ink-muted hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    {{ __('See all notifications') }}
                    <x-ui.icon name="arrow-right" class="size-4" />
                </a>
            </div>
        @endif
    </x-ui.dropdown>
</div>
