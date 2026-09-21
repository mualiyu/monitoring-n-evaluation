{{--
    Notification centre — rendered by BOTH
    App\Livewire\Tenant\Notifications\NotificationCentre and
    App\Livewire\Oversight\Notifications\NotificationCentre. One screen, so the
    two surfaces cannot drift; what differs is the shell and the feed.
--}}
<div>
    <x-ui.page-header
        :title="__('Notifications')"
        :description="$this->surfaceDescription()"
    >
        <x-slot:actions>
            @if ($this->unreadCount > 0)
                <x-ui.button variant="secondary" icon="check" wire:click="markAllRead" loading="markAllRead">
                    {{ __('Mark all read (:count)', ['count' => $this->unreadCount]) }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mb-4" flush>
        <div class="p-4 sm:max-w-xs">
            <x-ui.form.group name="filter" :label="__('Show')">
                <x-ui.form.select name="filter" :options="$this->filterOptions()" wire:model.live="filter" />
            </x-ui.form.group>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="filter,gotoPage,previousPage,nextPage,markAllRead">
            @if ($this->notifications->isEmpty())
                <x-ui.empty-state
                    :variant="$filter === 'unread' ? 'filtered' : 'empty'"
                    icon="bell"
                    :title="$filter === 'unread' ? __('Nothing unread') : __('Nothing here yet')"
                    :description="$filter === 'unread'
                        ? __('You are up to date. Switch to “Everything” to re-read what you have already seen.')
                        : __('When a return needs you, a project moves or a deadline approaches, it appears here.')"
                />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->notifications as $notification)
                        @php($link = $this->linkFor($notification))
                        <li
                            wire:key="notification-{{ $notification->id }}"
                            class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between {{ $notification->read_at ? '' : 'bg-brand-soft/40' }}"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <x-ui.icon
                                    :name="$notification->read_at ? 'inbox' : 'bell'"
                                    class="mt-0.5 size-5 shrink-0 text-ink-subtle"
                                />

                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                                        {{ $this->headlineFor($notification) }}

                                        @unless ($notification->read_at)
                                            {{-- Icon + text, never colour alone. --}}
                                            <span class="inline-flex items-center gap-1 rounded-md bg-neutral-soft px-1.5 py-0.5 text-[11px] font-semibold text-neutral-ink">
                                                <x-ui.icon name="bell" class="size-3" />
                                                {{ __('Unread') }}
                                            </span>
                                        @endunless
                                    </p>

                                    <p class="mt-0.5 text-sm text-ink-muted">{{ $this->summaryFor($notification) }}</p>

                                    <time
                                        class="mt-1 block text-xs text-ink-subtle"
                                        datetime="{{ $notification->created_at?->toIso8601String() }}"
                                        title="{{ $notification->created_at ? \App\Support\InstanceTime::local($notification->created_at)->format('j M Y, H:i') : '' }}"
                                    >
                                        {{ $notification->created_at?->diffForHumans() }}
                                    </time>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-1 sm:justify-end">
                                @if ($link)
                                    <x-ui.button variant="secondary" size="sm" icon="arrow-right" :href="$link">
                                        {{ __('Open the record') }}
                                    </x-ui.button>
                                @endif

                                @if ($notification->read_at)
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-uturn-left"
                                        wire:click="markUnread('{{ $notification->id }}')"
                                    >{{ __('Mark unread') }}</x-ui.button>
                                @else
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="check"
                                        wire:click="markRead('{{ $notification->id }}')"
                                    >{{ __('Mark read') }}</x-ui.button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($this->notifications->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->notifications" :label="__('Notification pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
