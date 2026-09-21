{{--
    Work-plan register (App\Livewire\Tenant\Workplans\WorkplanIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination.
--}}
@php
    $canCreate = auth()->user()?->can('create', \App\Models\Workplan::class) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Annual work plans')"
        :description="__('What this entity has committed to deliver this year, activity by activity, against the output indicators each one reports on.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export CSV') }}</x-ui.button>

            @if ($canCreate)
                <x-ui.button size="sm" icon="plus" :href="route('tenant.workplans.create')">
                    {{ __('Open a work plan') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')" class="lg:col-span-2">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Plan title…')"
                        wire:model.live.debounce.300ms="search"
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
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="search,status,year,unlinked">
        <x-ui.stat
            :label="__('Plans matching')"
            :value="number_format($this->stats['count'])"
            icon="calendar-days"
        />
        <x-ui.stat
            :label="__('Planned budget')"
            :value="\App\Support\Money::fromDecimalString($this->stats['budget'])->format()"
            icon="banknotes"
            :hint="__('summed from activity budget lines')"
        />
        <x-ui.stat
            :label="__('Awaiting approval')"
            :value="number_format($this->stats['awaiting'])"
            icon="clock"
            :intent="$this->stats['awaiting'] > 0 ? 'warning' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Activities without an indicator')"
            :value="number_format($this->stats['unlinked'])"
            icon="exclamation-triangle"
            :intent="$this->stats['unlinked'] > 0 ? 'critical' : 'positive'"
            :hint="$this->stats['unlinked'] > 0 ? __('breaches the manual rule') : __('every activity is linked')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Table                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,year,unlinked,sortBy,gotoPage,previousPage,nextPage">
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
                        :title="__('No work plan opened yet')"
                        :description="__('An annual work plan lists what this entity will deliver this year — each activity with its owner, its schedule, its budget line and the output indicator it reports on.')"
                    >
                        <x-slot:actions>
                            @if ($canCreate)
                                <x-ui.button icon="plus" :href="route('tenant.workplans.create')">
                                    {{ __('Open a work plan') }}
                                </x-ui.button>
                            @else
                                <p class="text-sm text-ink-muted">
                                    {{ __('You do not have permission to open a work plan. Ask your entity administrator.') }}
                                </p>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Annual work plans for this workspace, with progress, budget and approval state')"
                    class="p-4 sm:p-0"
                    :headings="[
                        ['label' => __('Work plan'), 'sortable' => true, 'sort' => $sort === 'title' ? $direction : null, 'click' => 'sortBy(\'title\')'],
                        ['label' => __('Year'), 'sortable' => true, 'sort' => $sort === 'year' ? $direction : null, 'click' => 'sortBy(\'year\')'],
                        __('Owner'),
                        __('Activities'),
                        __('Progress'),
                        ['label' => __('Budget'), 'align' => 'right'],
                        ['label' => __('Status'), 'sortable' => true, 'sort' => $sort === 'status' ? $direction : null, 'click' => 'sortBy(\'status\')'],
                        '',
                    ]"
                >
                    @foreach ($this->workplans as $plan)
                        @php
                            $summary = $this->summary($plan);
                            $detailUrl = route('tenant.workplans.show', $plan);
                        @endphp

                        <x-ui.table.row wire:key="workplan-{{ $plan->ulid }}">
                            <x-ui.table.cell :label="__('Work plan')" primary>
                                <a href="{{ $detailUrl }}" class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                    {{ $plan->title }}
                                </a>
                                <span class="mt-0.5 block text-xs font-normal text-ink-muted">
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
                                        {{ trans_choice('{1}1 without an output indicator|[2,*]:count without an output indicator', $summary['unlinked'], ['count' => $summary['unlinked']]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Progress')">
                                <x-ui.progress
                                    :value="$summary['progress']"
                                    :label="__('Weighted progress for :plan', ['plan' => $plan->title])"
                                    size="sm"
                                    class="sm:w-32"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Budget')" numeric>
                                {{ $summary['budget']->format() }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$plan->status->badge()" :label="$plan->status->label()" />
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.dropdown align="right" :label="__('Actions for :plan', ['plan' => $plan->title])">
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" icon-only>
                                            {{ __('Actions for :plan', ['plan' => $plan->title]) }}
                                        </x-ui.button>
                                    </x-slot:trigger>

                                    <x-ui.dropdown.item icon="eye" :href="$detailUrl">{{ __('Open plan') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.item icon="chart-bar" :href="route('tenant.workplans.gantt', $plan)">
                                        {{ __('Timeline') }}
                                    </x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->workplans->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->workplans" :label="__('Work plan list pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
