{{--
    State reports desk (App\Livewire\Oversight\Reporting\ReportDesk).

    Every return the state has been sent, across every entity — read-only. The
    data-heavy pattern: filter bar → summary strip → table (cards under sm:) →
    pagination. The only outbound links go to the state's own screens; an
    oversight user cannot reach an entity's workspace, so a link into it would
    be a dead end wearing a hyperlink.
--}}
@php
    $stats = $this->stats;
@endphp

<div>
    <x-ui.page-header
        :title="__('Progress returns')"
        :description="__('Every return filed by every entity, with who filed it and whether it met its deadline. Read-only — the approval chain belongs to the entity that owns the record.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export CSV') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="chart-bar"
                :href="route('oversight.compliance.index')"
            >{{ __('Compliance board') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary — describes the list under the current filters            --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Returns matching')"
            :value="number_format($stats['total'])"
            icon="document-text"
            :hint="$this->hasFilters() ? __('under the filters below') : __('filed state-wide')"
        />
        <x-ui.stat
            :label="__('Entities reporting')"
            :value="number_format($stats['entities'])"
            icon="building-office"
        />
        <x-ui.stat
            :label="__('Filed on time')"
            :value="number_format($stats['on_time'])"
            icon="check-circle"
        />
        <x-ui.stat
            :label="__('Filed late')"
            :value="number_format($stats['late'])"
            icon="exclamation-triangle"
            :intent="$stats['late'] > 0 ? 'critical' : 'neutral'"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <x-ui.form.group name="search" :label="__('Project')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Title or reference…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="tenantId" :label="__('Entity')">
                    <x-ui.form.select
                        name="tenantId"
                        :placeholder="__('All entities')"
                        :options="$this->tenants->pluck('name', 'id')->all()"
                        wire:model.live="tenantId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="periodId" :label="__('Reporting window')">
                    <x-ui.form.select
                        name="periodId"
                        :placeholder="__('Any window')"
                        :options="$this->periods->pluck('label', 'id')->all()"
                        wire:model.live="periodId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Chain status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->statusOptions"
                        wire:model.live="status"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="lateness" :label="__('Timeliness')">
                    <x-ui.form.select
                        name="lateness"
                        :placeholder="__('On time or late')"
                        :options="$this->latenessOptions"
                        wire:model.live="lateness"
                    />
                </x-ui.form.group>
            </div>

            @if ($this->hasFilters())
                <div class="mt-3 flex justify-end">
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The list                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantId,periodId,status,lateness">
            @if ($this->reports->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No filed return matches the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="document-text"
                        :title="__('No returns have been filed yet')"
                        :description="__('Progress returns appear here the moment an entity files one. Drafts and returns sent back for correction stay with the entity until they are filed.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="chart-bar" :href="route('oversight.compliance.index')">
                                {{ __('See who owes a return') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Progress returns filed across every entity, newest first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Entity'),
                        __('Project'),
                        __('Window'),
                        ['label' => __('Progress claimed'), 'align' => 'right'],
                        ['label' => __('Period spend'), 'align' => 'right'],
                        __('Status'),
                    ]"
                >
                    @foreach ($this->reports as $report)
                        <x-ui.table.row wire:key="state-report-{{ $report->ulid }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                @if ($report->tenant?->slug)
                                    <a
                                        href="{{ route('oversight.compliance.tenant', ['tenant' => $report->tenant->slug]) }}"
                                        class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $report->tenant->name }}</a>
                                @else
                                    {{ $report->tenant?->name ?? __('Unknown entity') }}
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                <span class="text-ink">{{ $report->project->title }}</span>
                                <span class="mt-0.5 block font-mono text-xs text-ink-muted">
                                    {{ $report->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Window')">
                                <span class="text-ink-muted">{{ $report->reportingPeriod->label }}</span>
                                <span class="mt-0.5 block text-xs text-ink-muted">
                                    @if ($report->submitted_at)
                                        {{ __('filed :date by :name', [
                                            'date' => $report->submitted_at->translatedFormat('j M Y'),
                                            'name' => $report->submittedBy?->name ?? __('unknown'),
                                        ]) }}
                                    @else
                                        {{ __('deadline :date', ['date' => $report->due_at->translatedFormat('j M Y')]) }}
                                    @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Progress claimed')" numeric>
                                {{ $report->physical_progress_claimed }}%
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Period spend')" numeric>
                                {{ $report->period_expenditure->format() }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$report->status->value" />

                                @if ($report->submitted_late)
                                    <span class="mt-1 block">
                                        <x-ui.badge status="overdue" size="sm" :label="__('Filed late')" />
                                    </span>
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->reports->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->reports" :label="__('State reports desk pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-ink-muted">
        {{ __('Drafts and returns sent back for correction are not shown: they are the entity’s unfinished work, and they reappear here the moment they are filed.') }}
    </p>
</div>
