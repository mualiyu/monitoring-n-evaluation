{{--
    State-wide work-plan board (App\Livewire\Oversight\Workplans\WorkplanBoard).

    Read-only. filter bar → stat row → table → pagination, as every data-heavy
    screen does. The cross-MDA read happens inside the Oversight Action, which
    re-checks workplans.view in the GLOBAL permission team first.
--}}
<div>
    <x-ui.page-header
        :title="__('Annual work plans')"
        :description="__('What every entity has committed to deliver, whether it has been approved, and how it is tracking.')"
    />

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Plan title…')"
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

                <x-ui.form.group name="year" :label="__('Year')">
                    <x-ui.form.select
                        name="year"
                        :placeholder="__('Any year')"
                        :options="$this->yearOptions"
                        wire:model.live="year"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <x-ui.form.checkbox
                    name="unlinked"
                    :label="__('Only plans with activities missing an output indicator')"
                    :description="__('The M&E manual requires one output indicator per work-plan activity.')"
                    wire:model.live="unlinked"
                />

                @if ($this->hasFilters())
                    <div class="sm:ml-auto">
                        <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                            {{ __('Clear filters') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="search,tenantId,status,year,unlinked">
        <x-ui.stat
            :label="__('Plans matching')"
            :value="number_format($this->totals['plans'])"
            icon="calendar-days"
        />
        <x-ui.stat
            :label="__('Awaiting approval')"
            :value="number_format($this->totals['awaiting'])"
            icon="clock"
            :intent="$this->totals['awaiting'] > 0 ? 'warning' : 'neutral'"
            :hint="__('on this page')"
        />
        <x-ui.stat
            :label="__('Planned budget')"
            :value="\App\Support\Money::fromDecimalString($this->totals['budget'])->format()"
            icon="banknotes"
            :hint="__('on this page')"
        />
        <x-ui.stat
            :label="__('Activities without an indicator')"
            :value="number_format($this->totals['unlinked'])"
            icon="exclamation-triangle"
            :intent="$this->totals['unlinked'] > 0 ? 'critical' : 'positive'"
            :hint="$this->totals['unlinked'] > 0 ? __('breaches the manual rule') : __('every activity is linked')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Table                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantId,status,year,unlinked,gotoPage,previousPage,nextPage">
            @if ($this->workplans->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No work plans match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        :title="__('No entity has opened a work plan yet')"
                        :description="__('Annual work plans are opened by each entity in its own workspace. This board shows them as they arrive.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Annual work plans across every entity, with progress, budget and approval state')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Entity'),
                        __('Work plan'),
                        __('Year'),
                        __('Owner'),
                        __('Activities'),
                        __('Progress'),
                        ['label' => __('Budget'), 'align' => 'right'],
                        __('Status'),
                    ]"
                >
                    @foreach ($this->workplans as $plan)
                        @php $summary = $this->summary($plan); @endphp

                        <x-ui.table.row wire:key="board-{{ $plan->ulid }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                {{ $plan->tenant->name }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Work plan')">
                                {{ $plan->title }}
                                <span class="mt-0.5 block text-xs text-ink-muted">
                                    {{ $plan->period_start->translatedFormat('j M Y') }} – {{ $plan->period_end->translatedFormat('j M Y') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Year')">
                                <span class="tabular-nums text-ink-muted">{{ $plan->yearLabel() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Owner')">
                                <span class="text-ink-muted">{{ $plan->owner?->name ?? '—' }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Activities')">
                                <span class="tabular-nums">{{ number_format($summary['activities']) }}</span>
                                @if ($summary['unlinked'] > 0)
                                    <span class="mt-1 flex items-center gap-1 text-xs font-medium text-critical-ink">
                                        <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                        {{ trans_choice('{1}1 without an indicator|[2,*]:count without an indicator', $summary['unlinked'], ['count' => $summary['unlinked']]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Progress')">
                                <x-ui.progress
                                    :value="$summary['progress']"
                                    :label="__('Weighted progress for :plan', ['plan' => $plan->title])"
                                    size="sm"
                                    class="sm:w-28"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Budget')" numeric>
                                {{ $summary['budget']->format() }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$plan->status->badge()" :label="$plan->status->label()" />
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->workplans->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->workplans" :label="__('Work plan board pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
