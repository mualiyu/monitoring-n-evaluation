{{--
    State field-work board (App\Livewire\Oversight\Inspections\InspectionBoard).

    Read-only by construction: the state observes MDA field work, it does not
    conduct it here. Every row is somebody else's workspace, so the MDA is a
    first-class column rather than an afterthought.
--}}
<div>
    <x-ui.page-header
        :title="__('Site inspections')"
        :description="__('Every entity’s field work on one board. What was visited, what was found, and whose reports have not arrived.')"
    />

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — state-wide, deliberately NOT filtered               --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat
            :label="__('Inspections recorded')"
            :value="number_format($this->stats['total'])"
            icon="map-pin"
            :hint="__('across every entity')"
        />
        <x-ui.stat
            :label="__('Escalated outcomes')"
            :value="number_format($this->stats['escalated'])"
            icon="exclamation-triangle"
            :intent="$this->stats['escalated'] > 0 ? 'critical' : 'neutral'"
            :hint="__('major issues or work stopped')"
        />
        <x-ui.stat
            :label="__('Reports overdue')"
            :value="number_format($this->stats['reports_overdue'])"
            icon="clock"
            :intent="$this->stats['reports_overdue'] > 0 ? 'warning' : 'neutral'"
            :hint="__('visits made, nothing written up')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
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

                <x-ui.form.group name="tenantId" :label="__('Entity')">
                    <x-ui.form.select
                        name="tenantId"
                        :placeholder="__('All entities')"
                        :options="$this->tenants->pluck('name', 'id')->all()"
                        wire:model.live="tenantId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->statusOptions"
                        wire:model.live="status"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="type" :label="__('Visit type')">
                    <x-ui.form.select
                        name="type"
                        :placeholder="__('Any type')"
                        :options="$this->typeOptions"
                        wire:model.live="type"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="outcome" :label="__('Outcome')">
                    <x-ui.form.select
                        name="outcome"
                        :placeholder="__('Any outcome')"
                        :options="$this->outcomeOptions"
                        wire:model.live="outcome"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-4">
                    <x-ui.form.checkbox
                        name="escalated"
                        :label="__('Only escalated outcomes')"
                        wire:model.live="escalated"
                    />
                    <x-ui.form.checkbox
                        name="overdue"
                        :label="__('Only overdue reports')"
                        wire:model.live="overdue"
                    />
                </div>

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The board                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantId,status,type,outcome,overdue,escalated">
            @if ($this->inspections->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No inspections match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="map-pin"
                        :title="__('No field work recorded yet')"
                        :description="__('Entities schedule and conduct their own site visits. Everything they file appears here.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Site inspections across every entity, with their outcome')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Entity'),
                        __('Project'),
                        __('Visit'),
                        __('Date'),
                        __('Inspector'),
                        __('Status'),
                        __('Outcome'),
                    ]"
                >
                    @foreach ($this->inspections as $inspection)
                        @php $reportOverdue = $inspection->isReportOverdue(); @endphp

                        <x-ui.table.row wire:key="oversight-inspection-{{ $inspection->ulid }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                <a
                                    href="{{ route('oversight.portfolio.tenant', ['tenant' => $inspection->tenant->slug]) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $inspection->tenant->name }}</a>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                <a
                                    href="{{ route('oversight.projects.show', ['ulid' => $inspection->project->ulid]) }}"
                                    class="rounded text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $inspection->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs text-ink-muted">
                                    {{ $inspection->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Visit')">
                                <span class="text-ink-muted">{{ $inspection->type->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Date')">
                                <span class="text-ink">
                                    {{ ($inspection->conducted_at ?? $inspection->scheduled_date)->translatedFormat('j M Y') }}
                                </span>

                                @if ($reportOverdue)
                                    <span class="mt-0.5 block text-xs text-critical-ink">{{ __('report overdue') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Inspector')">
                                <span class="text-ink-muted">{{ $inspection->leadInspector->name }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$inspection->status->badge()" :label="$inspection->status->label()" />

                                @if ($inspection->report_late)
                                    <span class="mt-1 block">
                                        <x-ui.badge status="overdue" size="sm" :label="__('Filed late')" />
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Outcome')">
                                @if ($inspection->outcome)
                                    <x-ui.badge
                                        :status="$inspection->outcome->badge()"
                                        :label="$inspection->outcome->label()"
                                    />

                                    @if (($inspection->findings_count ?? 0) > 0)
                                        <span class="mt-0.5 block text-xs text-ink-muted">
                                            {{ trans_choice('{1} :count finding|[2,*] :count findings', $inspection->findings_count, ['count' => $inspection->findings_count]) }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-subtle">&mdash;</span>
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->inspections->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->inspections" :label="__('Inspection board pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
