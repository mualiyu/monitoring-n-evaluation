{{--
    Field-work desk (App\Livewire\Tenant\Inspections\InspectionIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination.
--}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $showUrl = fn ($inspection) => route('tenant.inspections.show', [...$workspace, 'inspection' => $inspection]);
    $conductUrl = fn ($inspection) => route('tenant.inspections.conduct', [...$workspace, 'inspection' => $inspection]);
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);

    $canSchedule = auth()->user()?->can('create', \App\Models\SiteInspection::class) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Site inspections')"
        :description="__('Every visit this entity has planned, made or written up. A visit produces a Field Trip Report, and the inspector who made it never signs it off.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export') }}</x-ui.button>

            @if ($canSchedule)
                <x-ui.button size="sm" icon="plus" :href="route('tenant.inspections.create', $workspace)">
                    {{ __('Schedule a visit') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — the state of the desk, deliberately NOT filtered    --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('In the diary')"
            :value="number_format($this->stats['scheduled'])"
            icon="calendar-days"
            :hint="__('visits not yet made')"
        />
        <x-ui.stat
            :label="__('Visits under way')"
            :value="number_format($this->stats['under_way'])"
            icon="map-pin"
            :intent="$this->stats['under_way'] > 0 ? 'warning' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Awaiting sign-off')"
            :value="number_format($this->stats['awaiting_review'])"
            icon="inbox"
            :intent="$this->stats['awaiting_review'] > 0 ? 'warning' : 'neutral'"
            :hint="__('an inspector cannot clear their own report')"
        />
        <x-ui.stat
            :label="__('Reports overdue')"
            :value="number_format($this->stats['reports_overdue'])"
            icon="exclamation-triangle"
            :intent="$this->stats['reports_overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$this->stats['reports_overdue'] > 0 ? __('field work not written up') : __('nothing outstanding')"
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
                    name="mineOnly"
                    :label="__('Only visits I am leading')"
                    wire:model.live="mineOnly"
                />

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The list                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,type,outcome,projectUlid,mineOnly">
            @if ($this->inspections->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No site inspections match the filters you have set.')"
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
                        :title="__('No site visits yet')"
                        :description="__('Schedule a visit, or wait for the routine sweep: it proposes one for every project that has gone past the state’s monitoring interval.')"
                    >
                        <x-slot:actions>
                            @if ($canSchedule)
                                <x-ui.button icon="plus" :href="route('tenant.inspections.create', $workspace)">
                                    {{ __('Schedule a visit') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Site inspections for this workspace, with their status and outcome')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Project'),
                        __('Visit'),
                        __('Date'),
                        __('Inspector'),
                        __('Status'),
                        __('Outcome'),
                        '',
                    ]"
                >
                    @foreach ($this->inspections as $inspection)
                        @php
                            $reportOverdue = $inspection->isReportOverdue();
                            $visitOverdue = $inspection->isVisitOverdue();
                        @endphp

                        <x-ui.table.row wire:key="inspection-{{ $inspection->ulid }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a
                                    href="{{ $projectUrl($inspection->project) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $inspection->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $inspection->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Visit')">
                                <span class="text-ink">{{ $inspection->type->label() }}</span>

                                @if ($inspection->isProposed())
                                    {{-- An MDA has to be able to tell what it
                                         committed to from what the platform
                                         suggested. --}}
                                    <span class="mt-1 block">
                                        <x-ui.badge
                                            status="pending"
                                            size="sm"
                                            icon="cog"
                                            :label="__('Proposed')"
                                        />
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Date')">
                                <span @class(['font-medium text-critical-ink' => $visitOverdue, 'text-ink' => ! $visitOverdue])>
                                    {{ $inspection->scheduled_date->translatedFormat('j M Y') }}
                                </span>

                                <span @class([
                                    'mt-0.5 block text-xs',
                                    'text-critical-ink' => $visitOverdue || $reportOverdue,
                                    'text-ink-muted' => ! $visitOverdue && ! $reportOverdue,
                                ])>
                                    @if ($reportOverdue)
                                        {{ __('report overdue') }}
                                    @elseif ($visitOverdue)
                                        {{ __('visit date passed') }}
                                    @elseif ($inspection->conducted_at)
                                        {{ __('visited :date', ['date' => $inspection->conducted_at->translatedFormat('j M')]) }}
                                    @else
                                        &mdash;
                                    @endif
                                </span>
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

                            <x-ui.table.cell align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @can('conduct', $inspection)
                                        @if ($inspection->status->isOpen())
                                            <x-ui.button
                                                variant="secondary"
                                                size="sm"
                                                icon="pencil-square"
                                                :href="$conductUrl($inspection)"
                                            >{{ $inspection->status === \App\Enums\InspectionStatus::InProgress ? __('Continue') : __('Conduct') }}</x-ui.button>
                                        @endif
                                    @endcan

                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        trailing-icon="chevron-right"
                                        :href="$showUrl($inspection)"
                                    >{{ __('Open') }}</x-ui.button>
                                </div>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->inspections->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->inspections" :label="__('Inspection list pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
