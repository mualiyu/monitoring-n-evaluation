{{--
    One record's history (App\Livewire\Shared\ActivityTimeline).
    Append-only: this panel renders the audit trail and can never change it.
--}}
<x-ui.card
    :title="$heading ?? __('History')"
    :subtitle="__(':count recorded change(s)', ['count' => $this->total])"
    flush
>
    @if ($this->entries->isEmpty())
        <x-ui.empty-state
            variant="empty"
            compact
            icon="shield-check"
            :title="__('Nothing recorded yet')"
            :description="__('Every change to this record is logged here with who made it and when.')"
        />
    @else
        <ol class="divide-y divide-line">
            @foreach ($this->entries as $entry)
                <li class="px-4 py-3" wire:key="activity-{{ $entry->id }}">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-ink">
                            <x-ui.icon name="shield-check" class="size-3.5 text-ink-subtle" />
                            {{ $entry->description }}
                        </span>
                        <span class="text-xs text-ink-muted">
                            {{ __('by :who', ['who' => $this->causerName($entry)]) }}
                        </span>
                        <time
                            class="text-xs text-ink-subtle"
                            datetime="{{ $entry->created_at?->toIso8601String() }}"
                            title="{{ $entry->created_at ? \App\Support\InstanceTime::local($entry->created_at)->format('j M Y, H:i') : '' }}"
                        >
                            {{ $entry->created_at ? \App\Support\InstanceTime::local($entry->created_at)->diffForHumans() : '' }}
                        </time>
                    </div>

                    @php($changes = $this->changes($entry->id))

                    @if ($changes !== [])
                        <dl class="mt-2 space-y-1 border-l-2 border-line pl-3">
                            @foreach ($changes as $change)
                                <div class="flex flex-wrap items-baseline gap-x-2 text-xs">
                                    <dt class="text-ink-muted">{{ $change['attribute'] }}</dt>
                                    <dd class="flex items-baseline gap-1.5">
                                        <span class="text-ink-subtle line-through">{{ $change['from'] }}</span>
                                        <x-ui.icon name="arrow-right" class="size-3 text-ink-subtle" />
                                        <span class="font-medium text-ink">{{ $change['to'] }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ol>

        @if (! $expanded && $this->total > $this->entries->count())
            <x-slot:footer>
                <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray" wire:click="expand" loading="expand">
                    {{ __('Show the full history (:count)', ['count' => $this->total]) }}
                </x-ui.button>
            </x-slot:footer>
        @endif
    @endif
</x-ui.card>
