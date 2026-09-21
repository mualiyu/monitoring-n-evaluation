{{--
    Indicator register (App\Livewire\Tenant\Indicators\IndicatorIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination.

    NOTHING on this page computes an achievement percentage or a band. Both
    come from App\Support\IndicatorAchievement, which is the single definition
    the export, the detail screen and the oversight queue also read.
--}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $indicatorUrl = fn ($indicator) => route('tenant.indicators.show', [...$workspace, 'indicator' => $indicator]);
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);
@endphp

<div>
    <x-ui.page-header
        :title="__('Indicators')"
        :description="__('Every measure this entity reports against, with where each one stands against its agreed target. Standing is computed from the baseline, the target and the latest figure that has cleared data-quality review.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export register') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — the state of the register, deliberately NOT filtered --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('On track')"
            :value="number_format($this->stats['on_track'])"
            icon="check-circle"
            :intent="$this->stats['on_track'] > 0 ? 'positive' : 'neutral'"
            :hint="__('of :total active measures', ['total' => number_format($this->stats['total'])])"
        />
        <x-ui.stat
            :label="__('At risk')"
            :value="number_format($this->stats['at_risk'])"
            icon="exclamation-circle"
            :intent="$this->stats['at_risk'] > 0 ? 'warning' : 'neutral'"
            :hint="__('short of target, not yet failing')"
        />
        <x-ui.stat
            :label="__('Off track')"
            :value="number_format($this->stats['off_track'])"
            icon="exclamation-triangle"
            :intent="$this->stats['off_track'] > 0 ? 'critical' : 'neutral'"
            :hint="__('materially behind the agreed target')"
        />
        <x-ui.stat
            :label="__('No data')"
            :value="number_format($this->stats['no_data'])"
            icon="question-mark-circle"
            :hint="__('no target set, or no validated figure yet')"
        />
    </div>

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
                        :placeholder="__('Indicator name or definition…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="projectUlid" :label="__('Project')">
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('All projects')"
                        :options="$this->projectOptions"
                        wire:model.live="projectUlid"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="tier" :label="__('Tier')">
                    <x-ui.form.select
                        name="tier"
                        :placeholder="__('Any tier')"
                        :options="$this->tierOptions"
                        wire:model.live="tier"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="band" :label="__('Standing')">
                    <x-ui.form.select
                        name="band"
                        :placeholder="__('Any standing')"
                        :options="\App\Support\IndicatorAchievement::bands()"
                        wire:model.live="band"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div class="w-full sm:w-64">
                    <x-ui.form.group name="activation" :label="__('State')">
                        <x-ui.form.select
                            name="activation"
                            :placeholder="__('Active and draft')"
                            :options="['active' => __('Active only'), 'draft' => __('Awaiting a baseline')]"
                            wire:model.live="activation"
                        />
                    </x-ui.form.group>
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
    {{-- Register                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,projectUlid,tier,band,activation">
            @if ($this->indicators->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No indicators match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="chart-bar"
                        :title="__('No indicators yet')"
                        :description="__('Indicators are defined on a project’s results framework — impact, outcome and output statements, each measured by the figures this entity agrees to report. Open a project and build its framework to begin.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Indicators for this entity, with baseline, target, latest figure and standing')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Indicator'),
                        __('Project'),
                        ['label' => __('Baseline'), 'align' => 'right'],
                        ['label' => __('Target'), 'align' => 'right'],
                        ['label' => __('Latest'), 'align' => 'right'],
                        __('Standing'),
                        '',
                    ]"
                >
                    @foreach ($this->indicators as $indicator)
                        @php $achievement = $indicator->achievement(); @endphp

                        <x-ui.table.row wire:key="indicator-{{ $indicator->ulid }}">
                            <x-ui.table.cell :label="__('Indicator')" primary>
                                <a
                                    href="{{ $indicatorUrl($indicator) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $indicator->name }}</a>

                                <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @if ($indicator->tier)
                                        <x-ui.badge
                                            status="tier"
                                            size="sm"
                                            icon="label"
                                            :label="$indicator->tier->label()"
                                        />
                                    @endif

                                    @unless ($indicator->is_active)
                                        <x-ui.badge
                                            status="draft"
                                            size="sm"
                                            :label="__('Awaiting baseline')"
                                        />
                                    @endunless
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                @if ($indicator->project)
                                    <a
                                        href="{{ $projectUrl($indicator->project) }}"
                                        class="rounded text-ink-muted hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $indicator->project->title }}</a>
                                @else
                                    <span class="text-ink-muted">{{ __('Entity programme') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Baseline')" numeric>
                                {{ $indicator->baseline_value === null ? '—' : $indicator->baseline_value.$indicator->unit->suffix() }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Target')" numeric>
                                {{ $indicator->latestTarget?->target_value === null ? '—' : $indicator->latestTarget->target_value.$indicator->unit->suffix() }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Latest')" numeric>
                                {{ $indicator->latestCountableReading?->actual_value === null ? '—' : $indicator->latestCountableReading->actual_value.$indicator->unit->suffix() }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Standing')">
                                {{-- Icon + text + number. Never colour alone:
                                     board packs get printed in greyscale. --}}
                                <x-ui.badge
                                    :status="$achievement->badgeStatus()"
                                    :icon="$achievement->icon()"
                                    :label="$achievement->label()"
                                />
                                <span class="mt-0.5 block text-xs text-ink-muted tabular-nums">
                                    {{ $achievement->percentLabel() }} {{ __('of target') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    trailing-icon="chevron-right"
                                    :href="$indicatorUrl($indicator)"
                                >{{ __('Open') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->indicators->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->indicators" :label="__('Indicator register pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
