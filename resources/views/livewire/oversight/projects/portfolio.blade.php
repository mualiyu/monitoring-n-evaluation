{{--
    State portfolio (App\Livewire\Oversight\Projects\Portfolio).
    Cross-MDA list: filter bar → portfolio stats → league table → project table.
--}}
@php
    $summary = $this->summary;
    $totals = $summary['totals'];
    $byStatus = $summary['by_status'];
@endphp

<div>
    <x-ui.page-header
        :title="__('State portfolio')"
        :description="__('Every project on this instance, across all entities. Figures are the last verified position, not contractor claims.')"
    />

    {{-- Portfolio totals --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Projects state-wide')"
            :value="number_format($totals['project_count'])"
            icon="folder"
        />
        <x-ui.stat
            :label="__('Contract value')"
            :value="$totals['contract_value_total']->format()"
            icon="banknotes"
            :hint="__('sum of awarded contracts')"
        />
        <x-ui.stat
            :label="__('Paid to date')"
            :value="$totals['expenditure_total']->format()"
            icon="chart-bar"
            :hint="__('across all entities')"
        />
        <x-ui.stat
            :label="__('In progress')"
            :value="number_format($byStatus['in_progress'] ?? 0)"
            icon="arrow-path"
            :hint="__(':completed completed · :certified certified', [
                'completed' => number_format($byStatus['completed'] ?? 0),
                'certified' => number_format($byStatus['certified'] ?? 0),
            ])"
        />
    </div>

    {{-- Entity league table --}}
    <x-ui.card class="mb-4" flush :title="__('By entity')" :subtitle="__('Select an entity to drill into its portfolio')">
        @if (empty($summary['tenants']))
            <x-ui.empty-state
                compact
                icon="building-office"
                :title="__('No entity is reporting yet')"
                :description="__('Rankings appear once workspaces have registered projects.')"
            />
        @else
            <x-ui.table
                :caption="__('Project portfolio by entity')"
                class="p-4 sm:p-0"
                :headings="[
                    __('Entity'),
                    ['label' => __('Projects'), 'align' => 'right'],
                    ['label' => __('Contract value'), 'align' => 'right'],
                    ['label' => __('Paid to date'), 'align' => 'right'],
                    __('Delivery mix'),
                    '',
                ]"
            >
                @foreach ($summary['tenants'] as $row)
                    <x-ui.table.row wire:key="portfolio-tenant-{{ $row['tenant_id'] }}">
                        <x-ui.table.cell :label="__('Entity')" primary>
                            {{-- The drill-down route binds {tenant:slug}, so it must be the
                                 slug here and not the id — an id 404s. Guarded because
                                 BuildPortfolioSummary leaves slug null when the tenant row
                                 has gone (same guard as the compliance board). --}}
                            @if ($row['slug'])
                                <a href="{{ url('/portfolio/'.$row['slug']) }}" class="rounded hover:underline">
                                    {{ $row['name'] ?? __('Unnamed entity') }}
                                </a>
                            @else
                                {{ $row['name'] ?? __('Unnamed entity') }}
                            @endif
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Projects')" numeric>{{ number_format($row['project_count']) }}</x-ui.table.cell>
                        <x-ui.table.cell :label="__('Contract value')" numeric>{{ $row['contract_value_total']->format() }}</x-ui.table.cell>
                        <x-ui.table.cell :label="__('Paid to date')" numeric>{{ $row['expenditure_total']->format() }}</x-ui.table.cell>

                        <x-ui.table.cell :label="__('Delivery mix')">
                            <div class="flex flex-wrap gap-1">
                                @foreach (['in_progress', 'completed', 'certified', 'suspended'] as $key)
                                    @if (($row['by_status'][$key] ?? 0) > 0)
                                        <x-ui.badge :status="$key" size="sm" :label="$row['by_status'][$key].' '.\App\Enums\ProjectStatus::from($key)->label()" />
                                    @endif
                                @endforeach
                            </div>
                        </x-ui.table.cell>

                        <x-ui.table.cell align="right">
                            @if ($row['slug'])
                                <x-ui.button variant="ghost" size="sm" trailing-icon="chevron-right" :href="url('/portfolio/'.$row['slug'])">
                                    {{ __('Drill down') }}
                                </x-ui.button>
                            @endif
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- Filter bar --}}
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
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <x-ui.form.group name="sector" :label="__('Sector')" class="w-full sm:w-56">
                    <x-ui.form.select
                        name="sector"
                        :placeholder="__('All sectors')"
                        :options="$this->sectors->pluck('name', 'id')->all()"
                        wire:model.live="sector"
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
                        :label="__('Past delivery date only')"
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

    {{-- Project table --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantId,status,sector,fundingSource,overdue,gotoPage,previousPage,nextPage">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state
                    :variant="$this->hasFilters() ? 'filtered' : 'empty'"
                    :title="$this->hasFilters() ? null : __('No projects registered on this instance')"
                    :description="$this->hasFilters()
                        ? __('No projects match these filters across any entity.')
                        : __('Projects appear here as soon as an entity registers them in its workspace.')"
                >
                    @if ($this->hasFilters())
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.table
                    :caption="__('Projects across all entities')"
                    class="p-4 sm:p-0"
                    :headings="[__('Project'), __('Entity'), ['label' => __('Contract value'), 'align' => 'right'], __('Progress'), __('Status'), ['label' => __('Delivery date'), 'align' => 'right']]"
                >
                    @foreach ($this->projects as $project)
                        @php
                            $deliveryDate = $project->revised_end_date ?? $project->expected_end_date;
                            $isLate = $deliveryDate && $deliveryDate->isPast()
                                && ! in_array($project->status->value, ['completed', 'certified', 'closed', 'cancelled'], true);
                        @endphp

                        <x-ui.table.row wire:key="portfolio-project-{{ $project->ulid }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a href="{{ url('/projects/'.$project->ulid) }}" class="rounded hover:underline">{{ $project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $project->reference }}
                                    @if ($project->primaryLocation?->lga)
                                        · {{ $project->primaryLocation->lga->name }}
                                    @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Entity')">
                                <span class="text-ink-muted">{{ $project->tenant?->name }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contract value')" numeric>
                                {{ $project->contract_value_total?->format() ?? '—' }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Progress')">
                                <x-ui.progress :value="$project->physical_progress" size="sm" class="sm:w-32"
                                    :label="__('Physical progress for :project', ['project' => $project->title])" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$project->status->value" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Delivery date')" align="right">
                                @if ($deliveryDate)
                                    <span @class(['font-medium text-critical-ink' => $isLate, 'text-ink-muted' => ! $isLate])>
                                        {{ $deliveryDate->translatedFormat('j M Y') }}
                                    </span>
                                @else
                                    <span class="text-ink-subtle">{{ __('Not set') }}</span>
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->projects->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->projects" :label="__('Portfolio pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
