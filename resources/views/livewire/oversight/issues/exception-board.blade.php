{{--
    State deviation board (App\Livewire\Oversight\Issues\ExceptionBoard).

    READ-ONLY. The state watches deviations; the MDA answers for them. Every
    row links into the owning workspace, where the record lives and where
    somebody can actually act on it.
--}}
<div>
    <x-ui.page-header
        :title="__('Exception reports')"
        :description="__('Every entity\'s deviations on one board, worst first. Raised automatically when a project trips a configured tolerance, and by anyone who witnesses a critical incident.')"
    />

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Live deviations')"
            :value="number_format($this->stats['live'])"
            icon="exclamation-triangle"
            :hint="__('open or acknowledged, state-wide')"
        />
        <x-ui.stat
            :label="__('Critical')"
            :value="number_format($this->stats['critical'])"
            icon="exclamation-circle"
            :intent="$this->stats['critical'] > 0 ? 'critical' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Entities affected')"
            :value="number_format($this->stats['entities'])"
            icon="building-office"
        />
        <x-ui.stat
            :label="__('Raised automatically')"
            :value="number_format($this->stats['automatic'])"
            icon="arrow-path"
            :hint="__('by the nightly threshold sweep')"
        />
    </div>

    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Project title or reference…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="tenantSlug" :label="__('Entity')">
                    <x-ui.form.select
                        name="tenantSlug"
                        :placeholder="__('All entities')"
                        :options="$this->tenantOptions"
                        wire:model.live="tenantSlug"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="trigger" :label="__('Trigger')">
                    <x-ui.form.select
                        name="trigger"
                        :placeholder="__('Any trigger')"
                        :options="$this->triggerOptions()"
                        wire:model.live="trigger"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="severity" :label="__('Severity')">
                    <x-ui.form.select
                        name="severity"
                        :placeholder="__('Any severity')"
                        :options="$this->severityOptions()"
                        wire:model.live="severity"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->statusOptions()"
                        wire:model.live="status"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <x-ui.form.checkbox
                    name="liveOnly"
                    :label="__('Live deviations only')"
                    wire:model.live="liveOnly"
                />

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantSlug,status,trigger,severity,liveOnly">
            @if ($this->reports->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No exception reports match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="check-circle"
                        :title="__('No entity has a live deviation')"
                        :description="__('Every project under delivery is measured nightly against its entity\'s tolerances. Anything that trips one appears here, with the figures that tripped it.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Exception reports across every entity, worst first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Entity'),
                        __('Project'),
                        __('Trigger'),
                        ['label' => __('Measured'), 'align' => 'right'],
                        __('Severity'),
                        __('Status'),
                        __('Raised'),
                    ]"
                >
                    @foreach ($this->reports as $report)
                        <x-ui.table.row wire:key="board-exception-{{ $report->ulid }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                {{-- Into the owning workspace, where the record
                                     lives and where somebody can act on it. --}}
                                <a
                                    href="{{ route('tenant.exceptions.show', ['tenant' => $report->tenant->slug, 'exceptionReport' => $report->ulid]) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $report->tenant->name }}</a>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                <span class="text-ink-muted">{{ $report->project->title }}</span>
                                <span class="mt-0.5 block font-mono text-xs text-ink-subtle">
                                    {{ $report->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Trigger')">
                                <span class="inline-flex items-center gap-1.5 text-ink">
                                    <x-ui.icon :name="$report->trigger->icon()" class="size-4 text-ink-subtle" />
                                    {{ $report->trigger->label() }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Measured')" numeric>
                                @if ($report->measured_value !== null)
                                    {{ $report->measured_value }}
                                    <span class="block text-xs font-normal text-ink-muted">
                                        {{ __('tolerance :threshold', ['threshold' => $report->threshold_value]) }}
                                    </span>
                                @else
                                    <span class="text-ink-subtle">&mdash;</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Severity')">
                                <x-ui.badge
                                    :status="$report->severity->badgeStatus()"
                                    :label="$report->severity->label()"
                                    :icon="$report->severity->icon()"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge
                                    :status="$report->status->badgeStatus()"
                                    :label="$report->status->label()"
                                    :icon="$report->status->icon()"
                                />
                                @if ($report->issue)
                                    <span class="mt-1 block text-xs text-ink-muted">{{ __('issue raised') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Raised')">
                                <span class="text-ink-muted">{{ $report->measured_at->translatedFormat('j M Y') }}</span>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->reports->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->reports" :label="__('Deviation board pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
