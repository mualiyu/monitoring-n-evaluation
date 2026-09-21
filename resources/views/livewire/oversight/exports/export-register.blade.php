{{--
    The generated-artifact register
    (App\Livewire\Oversight\Exports\ExportRegister).

    Every file this platform has handed out, who asked for it, and under which
    filters. The filter set is printed on the row rather than hidden behind a
    click, because "3,218 projects" is not a figure until you know what was
    asked — and an export nobody can trace is data leaving a government
    platform unobserved.

    Re-download goes through the same signed, policy-checked route as a first
    download. There is no second path to a stored file.
--}}
@php
    $exports = $this->exports;
    $stats = $this->stats;
@endphp

<div>
    <x-ui.page-header
        :title="__('Export register')"
        :description="__('Every artifact this platform has produced: the question it answered, the officer who asked, and whether the file is still held.')"
    >
        <x-slot:actions>
            <x-ui.button size="sm" icon="plus" :href="route('oversight.reports.builder')">
                {{ __('Build a report') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($failure)
        <x-ui.alert variant="warning" class="mb-4" :title="__('That artifact could not be handed over')">{{ $failure }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <x-ui.form.group name="search" :label="__('Search')" class="w-full sm:w-64">
                <x-ui.form.input
                    name="search"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('Title of the artifact…')"
                    wire:model.live.debounce.300ms="search"
                />
            </x-ui.form.group>

            <x-ui.form.group name="dataset" :label="__('Dataset')" class="w-full sm:w-52">
                <x-ui.form.select
                    name="dataset"
                    :options="$this->datasetOptions()"
                    :placeholder="__('Any dataset')"
                    wire:model.live="dataset"
                />
            </x-ui.form.group>

            <x-ui.form.group name="format" :label="__('Format')" class="w-full sm:w-40">
                <x-ui.form.select
                    name="format"
                    :options="$this->formatOptions()"
                    :placeholder="__('Any format')"
                    wire:model.live="format"
                />
            </x-ui.form.group>

            <x-ui.form.group name="status" :label="__('Outcome')" class="w-full sm:w-44">
                <x-ui.form.select
                    name="status"
                    :options="$this->statusOptions()"
                    :placeholder="__('Any outcome')"
                    wire:model.live="status"
                />
            </x-ui.form.group>

            <div class="flex flex-wrap items-center gap-3 sm:pb-2">
                <x-ui.form.checkbox
                    name="mineOnly"
                    :label="__('Only mine')"
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
    {{-- Summary row                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Artifacts produced')"
            :value="number_format($stats['total'])"
            icon="arrow-down-tray"
            :hint="__('Since the register opened')"
        />
        <x-ui.stat
            :label="__('Ready')"
            :value="number_format($stats['ready'])"
            icon="check-circle"
        />
        <x-ui.stat
            :label="__('Generating')"
            :value="number_format($stats['pending'])"
            icon="clock"
            :hint="__('Large exports run on a worker')"
        />
        <x-ui.stat
            :label="__('Failed')"
            :value="number_format($stats['failed'])"
            icon="exclamation-triangle"
            :intent="$stats['failed'] > 0 ? 'critical' : 'neutral'"
            :hint="__('You asked for :count of all artifacts', ['count' => number_format($stats['mine'])])"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The register                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div
            wire:loading.delay.class.remove="hidden"
            wire:target="search,dataset,format,status,mineOnly,clearFilters,gotoPage,nextPage,previousPage"
            class="hidden p-4"
        >
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div
            wire:loading.delay.class="hidden"
            wire:target="search,dataset,format,status,mineOnly,clearFilters,gotoPage,nextPage,previousPage"
        >
            @if ($exports->total() === 0)
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :title="__('No artifact matches those filters')"
                        :description="__('The register holds files, but none answering that description.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="arrow-down-tray"
                        :title="__('Nothing has been exported yet')"
                        :description="__('Build a report or export a consolidation, and the file appears here with the question it answered and the officer who asked.')"
                    >
                        <x-slot:actions>
                            <x-ui.button icon="plus" :href="route('oversight.reports.builder')">
                                {{ __('Build a report') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Generated artifacts, newest first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Artifact'),
                        __('Question'),
                        __('Requested by'),
                        ['label' => __('Rows'), 'align' => 'right'],
                        __('Outcome'),
                        ['label' => __('Action'), 'align' => 'right'],
                    ]"
                >
                    @foreach ($exports as $export)
                        @php $outcome = $this->outcome($export); @endphp

                        <x-ui.table.row wire:key="export-{{ $export->ulid }}">
                            <x-ui.table.cell :label="__('Artifact')" primary>
                                <span class="flex items-center gap-1.5">
                                    <x-ui.icon :name="$export->format->icon()" class="size-4 shrink-0 text-ink-subtle" />
                                    {{ $export->title }}
                                </span>
                                <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                    {{ $export->format->label() }} · {{ $export->dataset->label() }}
                                    @if ($export->consolidatedReport)
                                        ·
                                        <a
                                            href="{{ route('oversight.consolidation.show', ['consolidatedReport' => $export->consolidatedReport]) }}"
                                            class="rounded font-mono hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        >{{ $export->consolidatedReport->reference }}</a>
                                    @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Question')" stacked>
                                @php
                                    $filters = is_array($export->filters) ? ($export->filters['filters'] ?? []) : [];
                                @endphp

                                @if (! is_array($filters) || $filters === [])
                                    <span class="text-sm text-ink-subtle">{{ __('No filters — everything the dataset holds') }}</span>
                                @else
                                    <ul class="flex flex-wrap gap-1.5">
                                        @foreach ($filters as $key => $value)
                                            <li class="rounded-md bg-neutral-soft px-1.5 py-0.5 text-xs text-neutral-ink">
                                                {{ Str::headline((string) $key) }}:
                                                <span class="font-medium">{{ is_bool($value) ? __('Yes') : $value }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($export->generatedFor)
                                    <span class="mt-1 block text-xs text-ink-muted">
                                        {{ __('Generated for :entity', ['entity' => $export->generatedFor->name]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Requested by')">
                                {{ $export->generatedBy?->name ?? __('an officer since removed') }}
                                <span class="block text-xs text-ink-muted">
                                    {{ $export->created_at?->translatedFormat('j M Y, H:i') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Rows')" numeric>
                                {{ $export->row_count === null ? '—' : number_format($export->row_count) }}
                                @if ($export->truncated)
                                    <span class="block text-xs text-warning-ink">{{ __('Truncated') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Outcome')">
                                {{-- Icon + text, never colour alone. --}}
                                <x-ui.badge
                                    :status="$outcome['status']"
                                    :label="$outcome['label']"
                                    :icon="$outcome['icon']"
                                />
                                @if ($export->hasFailed() && $export->error_message)
                                    <span class="mt-0.5 block text-xs text-ink-muted">{{ $export->error_message }}</span>
                                @elseif ($export->expires_at && $export->isReady())
                                    <span class="mt-0.5 block text-xs text-ink-muted">
                                        {{ __('Held until :date', ['date' => $export->expires_at->translatedFormat('j M Y')]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Action')" align="right">
                                @if ($export->isDownloadable())
                                    <x-ui.button
                                        size="sm"
                                        variant="secondary"
                                        icon="arrow-down-tray"
                                        wire:click="download('{{ $export->ulid }}')"
                                    >{{ __('Download') }}</x-ui.button>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs text-ink-muted">
                                        <x-ui.icon name="minus" class="size-3.5" />
                                        {{ __('Not available') }}
                                    </span>
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-slot:footer>
            <div class="flex flex-col gap-3">
                <x-ui.pagination :paginator="$exports" :label="__('Export register pages')" />
                <p class="text-xs text-ink-muted">
                    {{ __('Files are stored privately and pruned on a retention schedule; the row recording who took one is never deleted. Downloads go through a signed link that expires.') }}
                </p>
            </div>
        </x-slot:footer>
    </x-ui.card>
</div>
