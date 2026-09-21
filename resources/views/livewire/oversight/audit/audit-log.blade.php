{{--
    State audit log (App\Livewire\Oversight\Audit\AuditLog).
    Read-only by construction: there is no edit and no delete path here.
--}}
<div>
    <x-ui.page-header
        :title="__('Audit log')"
        :description="__('Every recorded act across every entity, with who did it and what changed. The log is append-only — nothing on this screen can amend or remove an entry.')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-down-tray" wire:click="export" loading="export">
                {{ __('Export CSV') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mb-4" flush>
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-ui.form.group name="search" :label="__('Search')">
                <x-ui.form.input
                    name="search"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('What happened, e.g. “suspended”…')"
                    wire:model.live.debounce.300ms="search"
                />
            </x-ui.form.group>

            <x-ui.form.group name="actorId" :label="__('Actor')">
                <x-ui.form.select name="actorId" :placeholder="__('Anyone')" :options="$this->actorOptions" wire:model.live="actorId" />
            </x-ui.form.group>

            <x-ui.form.group name="tenant" :label="__('Entity')">
                <x-ui.form.select name="tenant" :placeholder="__('All entities')" :options="$this->tenantOptions" wire:model.live="tenant" />
            </x-ui.form.group>

            <x-ui.form.group name="log" :label="__('Area')">
                <x-ui.form.select name="log" :placeholder="__('All areas')" :options="$this->logOptions" wire:model.live="log" />
            </x-ui.form.group>

            <x-ui.form.group name="subjectType" :label="__('Record type')">
                <x-ui.form.select name="subjectType" :placeholder="__('All record types')" :options="$this->subjectTypeOptions" wire:model.live="subjectType" />
            </x-ui.form.group>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.form.group name="from" :label="__('From')">
                    <x-ui.form.input name="from" type="date" wire:model.live="from" />
                </x-ui.form.group>

                <x-ui.form.group name="to" :label="__('To')">
                    <x-ui.form.input name="to" type="date" wire:model.live="to" />
                </x-ui.form.group>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,actorId,tenant,log,subjectType,from,to,gotoPage,previousPage,nextPage">
            @if ($this->entries->isEmpty())
                <x-ui.empty-state
                    variant="filtered"
                    icon="shield-check"
                    :title="__('Nothing matches those filters')"
                    :description="__('Widen the dates, or clear the entity and area filters.')"
                />
            @else
                <x-ui.table
                    :caption="__('Recorded activity across every entity')"
                    class="p-4 sm:p-0"
                    :headings="[__('When'), __('What happened'), __('Actor'), __('Record'), __('Area'), '']"
                >
                    @foreach ($this->entries as $entry)
                        <x-ui.table.row wire:key="entry-{{ $entry->id }}">
                            <x-ui.table.cell :label="__('When')">
                                <time
                                    class="text-ink-muted"
                                    datetime="{{ $entry->created_at?->toIso8601String() }}"
                                    title="{{ $entry->created_at ? \App\Support\InstanceTime::local($entry->created_at)->format('j M Y, H:i') : '' }}"
                                >
                                    {{ $entry->created_at ? \App\Support\InstanceTime::local($entry->created_at)->format('j M Y, H:i') : '—' }}
                                </time>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('What happened')" primary>{{ $entry->description }}</x-ui.table.cell>

                            <x-ui.table.cell :label="__('Actor')">
                                <span class="text-ink-muted">{{ $this->causerName($entry) }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Record')">
                                <span class="font-mono text-xs text-ink-muted">{{ $this->subjectLabel($entry) }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Area')">
                                <span class="inline-flex items-center rounded-md bg-neutral-soft px-2 py-0.5 text-xs font-medium text-ink">
                                    {{ $entry->log_name ?? '—' }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    :icon="$inspecting === $entry->id ? 'chevron-up-down' : 'eye'"
                                    wire:click="inspect({{ $entry->id }})"
                                >{{ $inspecting === $entry->id ? __('Hide changes') : __('Changes') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>

                        @if ($inspecting === $entry->id)
                            <x-ui.table.row wire:key="entry-detail-{{ $entry->id }}" muted>
                                <x-ui.table.cell stacked :label="__('Before and after')">
                                    @php($changes = $this->changes($entry->id))

                                    @if ($changes === [])
                                        <p class="text-xs text-ink-muted">{{ __('This entry records an act rather than a field change — nothing was altered on the record itself.') }}</p>
                                    @else
                                        <dl class="space-y-1">
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
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endif
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->entries->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->entries" :label="__('Audit log pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
