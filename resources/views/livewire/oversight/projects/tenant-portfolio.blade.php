{{--
    Entity drill-down (App\Livewire\Oversight\Projects\TenantPortfolio).
--}}
@php $summary = $this->summary; @endphp

<div>
    <x-ui.page-header
        :title="$tenant->name"
        :description="__('This entity’s full portfolio, as recorded in its workspace.')"
        :back="url('/portfolio')"
        :back-label="__('All entities')"
        :breadcrumbs="[
            ['label' => __('State portfolio'), 'href' => url('/portfolio')],
            ['label' => $tenant->name],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge status="approved" :label="$tenant->type?->label() ?? __('Entity')" icon="building-office" />
        </x-slot:actions>
    </x-ui.page-header>

    @if ($summary)
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat :label="__('Projects')" :value="number_format($summary['project_count'])" icon="folder" />
            <x-ui.stat :label="__('Contract value')" :value="$summary['contract_value_total']->format()" icon="banknotes" />
            <x-ui.stat :label="__('Paid to date')" :value="$summary['expenditure_total']->format()" icon="chart-bar" />
            <x-ui.stat
                :label="__('In progress')"
                :value="number_format($summary['by_status']['in_progress'] ?? 0)"
                icon="arrow-path"
                :hint="__(':n suspended', ['n' => number_format($summary['by_status']['suspended'] ?? 0)])"
            />
        </div>
    @endif

    {{-- Filters --}}
    <x-ui.card class="mb-4" flush>
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
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
                <x-ui.form.select name="status" :placeholder="__('Any status')" :options="$this->statusOptions" wire:model.live="status" />
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

        <div class="flex flex-wrap items-center gap-4 border-t border-line px-4 py-3">
            <x-ui.form.checkbox name="overdue" :label="__('Past delivery date only')" wire:model.live="overdue" />

            @if ($this->hasFilters())
                <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters" class="ml-auto">
                    {{ __('Clear filters') }}
                </x-ui.button>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,sector,overdue,gotoPage,previousPage,nextPage">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state
                    :variant="$this->hasFilters() ? 'filtered' : 'empty'"
                    :title="$this->hasFilters() ? null : __('This entity has no projects yet')"
                    :description="$this->hasFilters()
                        ? __('No projects in this entity match the filters.')
                        : __('Projects appear here once the entity registers them in its own workspace.')"
                >
                    @if ($this->hasFilters())
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.table
                    :caption="__('Projects delivered by :entity', ['entity' => $tenant->name])"
                    class="p-4 sm:p-0"
                    :headings="[__('Project'), __('Sector'), ['label' => __('Contract value'), 'align' => 'right'], __('Progress'), __('Status'), '']"
                >
                    @foreach ($this->projects as $project)
                        <x-ui.table.row wire:key="tenant-project-{{ $project->ulid }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a href="{{ url('/projects/'.$project->ulid) }}" class="rounded hover:underline">{{ $project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">{{ $project->reference }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Sector')">
                                <span class="text-ink-muted">{{ $project->sector?->name ?? '—' }}</span>
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

                            <x-ui.table.cell align="right">
                                <x-ui.button variant="ghost" size="sm" trailing-icon="chevron-right" :href="url('/projects/'.$project->ulid)">
                                    {{ __('Open') }}
                                </x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->projects->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->projects" :label="__('Entity portfolio pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
