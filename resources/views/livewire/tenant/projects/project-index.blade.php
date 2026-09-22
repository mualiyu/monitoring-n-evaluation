{{--
    Project register (App\Livewire\Tenant\Projects\ProjectIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination.
--}}
@php
    $canCreate = auth()->user()?->can('create', \App\Models\Project::class) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Projects')"
        :description="__('Every project this entity is delivering. Figures are as last reported and verified — not contractor claims.')"
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
                <x-ui.button size="sm" icon="plus" :href="route('tenant.projects.create')">
                    {{ __('Register project') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

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

                <x-ui.form.group name="sector" :label="__('Sector')">
                    <x-ui.form.select
                        name="sector"
                        :placeholder="__('All sectors')"
                        :options="$this->sectors->pluck('name', 'id')->all()"
                        wire:model.live="sector"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <x-ui.form.group name="lga" :label="__('Location (LGA)')" class="w-full sm:w-56">
                    <x-ui.form.select
                        name="lga"
                        :placeholder="__('Anywhere')"
                        :options="$this->lgaOptions"
                        wire:model.live="lga"
                    />
                </x-ui.form.group>

                {{-- Whether a site carries a GPS fix — the GIS dashboard's
                     "on the map" / "not geotagged" tiles drill in through this. --}}
                <x-ui.form.group name="geotagged" :label="__('Location (GPS)')" class="w-full sm:w-56">
                    <x-ui.form.select
                        name="geotagged"
                        :placeholder="__('Any location')"
                        :options="['yes' => __('Geotagged'), 'no' => __('Not geotagged')]"
                        wire:model.live="geotagged"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="fundingSource" :label="__('Funding source')" class="w-full sm:w-56">
                    <x-ui.form.select
                        name="fundingSource"
                        :placeholder="__('Any funding source')"
                        :options="$this->fundingSources->pluck('name', 'id')->all()"
                        wire:model.live="fundingSource"
                    />
                </x-ui.form.group>

                <div class="pt-1 sm:pt-6">
                    <x-ui.form.checkbox
                        name="overdue"
                        :label="__('Past their delivery date only')"
                        :description="__('Uses the revised date where an extension was approved.')"
                        wire:model.live="overdue"
                    />
                </div>

                @if ($this->hasFilters())
                    <div class="sm:ml-auto sm:pt-6">
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
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60" wire:target="search,status,sector,fundingSource,lga,geotagged,overdue">
        <x-ui.stat
            :label="__('Projects matching')"
            :value="number_format($this->stats['count'])"
            icon="folder"
        />
        <x-ui.stat
            :label="__('Combined contract value')"
            :value="\App\Support\Money::fromDecimalString($this->stats['contract_value'])->format()"
            icon="banknotes"
            :hint="__('derived from awarded contracts')"
        />
        <x-ui.stat
            :label="__('In progress')"
            :value="number_format($this->stats['in_progress'])"
            icon="arrow-path"
        />
        <x-ui.stat
            :label="__('Past delivery date')"
            :value="number_format($this->stats['overdue'])"
            icon="exclamation-triangle"
            :intent="$this->stats['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$this->stats['overdue'] > 0 ? __('needs attention') : __('none outstanding')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Table                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        {{-- Loading skeleton: structure, not a white flash, on a 3G connection. --}}
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,sector,fundingSource,lga,geotagged,overdue,sortBy,gotoPage,previousPage,nextPage">
            @if ($this->projects->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No projects match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        :title="__('No projects registered yet')"
                        :description="__('Register the first project for this entity. You can add contracts, sites and indicators to it afterwards.')"
                    >
                        <x-slot:actions>
                            @if ($canCreate)
                                <x-ui.button icon="plus" :href="route('tenant.projects.create')">
                                    {{ __('Register project') }}
                                </x-ui.button>
                            @else
                                <p class="text-sm text-ink-muted">
                                    {{ __('You do not have permission to register projects. Ask your entity administrator.') }}
                                </p>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Projects in this workspace, with contract value, verified progress and status')"
                    class="p-4 sm:p-0"
                    :headings="[
                        ['label' => __('Project'), 'sortable' => true, 'sort' => $sort === 'title' ? $direction : null, 'click' => 'sortBy(\'title\')'],
                        __('Sector'),
                        ['label' => __('Contract value'), 'align' => 'right', 'sortable' => true, 'sort' => $sort === 'contract_value_total' ? $direction : null, 'click' => 'sortBy(\'contract_value_total\')'],
                        ['label' => __('Physical progress'), 'sortable' => true, 'sort' => $sort === 'physical_progress' ? $direction : null, 'click' => 'sortBy(\'physical_progress\')'],
                        ['label' => __('Status'), 'sortable' => true, 'sort' => $sort === 'status' ? $direction : null, 'click' => 'sortBy(\'status\')'],
                        ['label' => __('Delivery date'), 'align' => 'right', 'sortable' => true, 'sort' => $sort === 'expected_end_date' ? $direction : null, 'click' => 'sortBy(\'expected_end_date\')'],
                        '',
                    ]"
                >
                    @foreach ($this->projects as $project)
                        @php
                            $deliveryDate = $project->revised_end_date ?? $project->expected_end_date;
                            $isLate = $deliveryDate
                                && $deliveryDate->isPast()
                                && ! in_array($project->status->value, ['completed', 'certified', 'closed', 'cancelled'], true);
                            $detailUrl = route('tenant.projects.show', $project);
                        @endphp

                        <x-ui.table.row wire:key="project-{{ $project->ulid }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a href="{{ $detailUrl }}" class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                    {{ $project->title }}
                                </a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $project->reference }}
                                    @if ($project->primaryLocation?->lga)
                                        · {{ $project->primaryLocation->lga->name }}
                                    @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Sector')">
                                <span class="text-ink-muted">{{ $project->sector?->name ?? '—' }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contract value')" numeric>
                                {{ $project->contract_value_total?->format() ?? '—' }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Physical progress')">
                                <x-ui.progress
                                    :value="$project->physical_progress"
                                    :label="__('Physical progress for :project', ['project' => $project->title])"
                                    size="sm"
                                    class="sm:w-36"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$project->status->value" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Delivery date')" align="right">
                                @if ($deliveryDate)
                                    <span @class(['font-medium text-critical-ink' => $isLate, 'text-ink-muted' => ! $isLate])>
                                        {{ $deliveryDate->translatedFormat('j M Y') }}
                                    </span>
                                    @if ($project->revised_end_date)
                                        <span class="block text-xs text-ink-muted">{{ __('revised') }}</span>
                                    @endif
                                    @if ($isLate)
                                        <span class="mt-1 block sm:inline-block">
                                            <x-ui.badge status="overdue" size="sm" />
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-subtle">{{ __('Not set') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.dropdown align="right" :label="__('Actions for :project', ['project' => $project->title])">
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" icon-only>
                                            {{ __('Actions for :project', ['project' => $project->title]) }}
                                        </x-ui.button>
                                    </x-slot:trigger>

                                    <x-ui.dropdown.item icon="eye" :href="$detailUrl">{{ __('View project') }}</x-ui.dropdown.item>

                                    @can('update', $project)
                                        <x-ui.dropdown.item icon="pencil-square" :href="route('tenant.projects.edit', $project)">
                                            {{ __('Edit details') }}
                                        </x-ui.dropdown.item>
                                    @endcan

                                    @can('assign', $project)
                                        <x-ui.dropdown.item icon="users" :href="$detailUrl.'#team'">
                                            {{ __('Manage team') }}
                                        </x-ui.dropdown.item>
                                    @endcan
                                </x-ui.dropdown>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->projects->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->projects" :label="__('Project list pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
