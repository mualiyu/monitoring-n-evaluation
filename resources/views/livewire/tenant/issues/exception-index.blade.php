{{--
    Deviation board (App\Livewire\Tenant\Issues\ExceptionIndex).

    filter bar → stat summary → table (cards under sm:) → pagination.

    Almost every row here was written by the threshold engine. Each one carries
    the measurement and the tolerance it tripped, so the first response can be
    an explanation rather than an argument about whether it is real.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $reportUrl = fn ($report) => route('tenant.exceptions.show', [...$workspace, 'exceptionReport' => $report]);
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);
@endphp

<div>
    <x-ui.page-header
        :title="__('Exception reports')"
        :description="__('Projects that have deviated from their schedule, their spend profile or their statutory reporting — raised automatically against configured tolerances, and by anyone who witnesses a critical incident.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="folder"
                :href="route('tenant.issues.index', $workspace)"
            >{{ __('Challenges register') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Live deviations')"
            :value="number_format($this->stats['live'])"
            icon="exclamation-triangle"
            :hint="__('open or acknowledged')"
        />
        <x-ui.stat
            :label="__('Critical')"
            :value="number_format($this->stats['critical'])"
            icon="exclamation-circle"
            :intent="$this->stats['critical'] > 0 ? 'critical' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Never answered')"
            :value="number_format($this->stats['unanswered'])"
            icon="inbox"
            :intent="$this->stats['unanswered'] > 0 ? 'warning' : 'neutral'"
            :hint="__('raised and not yet acknowledged')"
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
                        :placeholder="__('Project, reference or narrative…')"
                        wire:model.live.debounce.300ms="search"
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

                <x-ui.form.group name="projectUlid" :label="__('Project')">
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('All projects')"
                        :options="$this->projectOptions"
                        wire:model.live="projectUlid"
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

        <div wire:loading.delay.long.remove wire:target="search,status,trigger,severity,projectUlid,liveOnly">
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
                        :title="__('Nothing has deviated')"
                        :description="__('Every project under delivery is measured nightly against this entity\'s tolerances for schedule slippage, expenditure variance and overdue reporting. Anything that trips one appears here with the figures that tripped it.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Exception reports raised against this workspace\'s projects, worst first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Trigger'),
                        __('Project'),
                        ['label' => __('Measured'), 'align' => 'right'],
                        __('Severity'),
                        __('Status'),
                        __('Raised'),
                        '',
                    ]"
                >
                    @foreach ($this->reports as $report)
                        <x-ui.table.row wire:key="exception-{{ $report->ulid }}">
                            <x-ui.table.cell :label="__('Trigger')" primary stacked>
                                <a
                                    href="{{ $reportUrl($report) }}"
                                    class="inline-flex items-center gap-1.5 rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >
                                    <x-ui.icon :name="$report->trigger->icon()" class="size-4 text-ink-subtle" />
                                    {{ $report->trigger->label() }}
                                </a>
                                @if ($report->isAutomatic())
                                    <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {{ __('raised by the threshold sweep') }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                <a
                                    href="{{ $projectUrl($report->project) }}"
                                    class="rounded text-ink-muted hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $report->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs text-ink-subtle">
                                    {{ $report->project->reference }}
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
                                    <span class="mt-1 block text-xs text-ink-muted">
                                        {{ __('issue raised') }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Raised')">
                                <span class="text-ink-muted">{{ $report->measured_at->translatedFormat('j M Y') }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    trailing-icon="chevron-right"
                                    :href="$reportUrl($report)"
                                >{{ __('Open') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->reports->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->reports" :label="__('Exception report pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
