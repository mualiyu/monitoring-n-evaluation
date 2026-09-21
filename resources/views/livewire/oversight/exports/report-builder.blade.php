{{--
    The ad-hoc report builder (App\Livewire\Oversight\Exports\ReportBuilder).

    Ask a question → see twenty-five rows → take the answer away. Every figure
    on this screen comes from the same Actions the dashboards call, so a
    spreadsheet exported here can never disagree with the board it came from.

    Filter bar → summary → preview table → generate, the same shape as every
    other data screen on this surface.
--}}
@php
    $dataset = $this->chosenDataset();
    $labels = $dataset->columns();
    $unreadable = $this->unreadable();
    $rows = $this->preview();
    $grouped = $this->groupedPreview();
    $total = $this->rowCount();

    $headings = [];
    foreach ($columns as $column) {
        $headings[] = [
            'label' => $labels[$column] ?? $column,
            'align' => in_array($column, $dataset->numericColumns(), true) ? 'right' : 'left',
        ];
    }
@endphp

<div>
    <x-ui.page-header
        :title="__('Report builder')"
        :description="__('Point it at a dataset, choose the columns and the filters, and take the answer away as CSV, Excel or PDF. Every figure is read through the same source as the dashboard that publishes it.')"
    >
        <x-slot:actions>
            <x-ui.button
                size="sm"
                variant="secondary"
                icon="arrow-down-tray"
                :href="route('oversight.exports.index')"
            >{{ __('Export register') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice)
        <x-ui.alert variant="info" class="mb-4" dismissible>{{ $notice }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-4" :title="__('That export could not be produced')">{{ $failure }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Dataset + filters                                                 --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" :title="__('The question')">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.form.group
                name="dataset"
                :label="__('Dataset')"
                :hint="$dataset->description()"
                required
            >
                <x-ui.form.select
                    name="dataset"
                    has-hint
                    :options="$this->datasetOptions"
                    wire:model.live="dataset"
                />
            </x-ui.form.group>

            <x-ui.form.group name="tenant" :label="__('Entity')">
                <x-ui.form.select
                    name="tenant"
                    :options="$this->tenantOptions"
                    :placeholder="__('Every entity')"
                    wire:model.live="tenant"
                />
            </x-ui.form.group>

            @if ($dataset !== App\Enums\ReportDataset::Projects)
                <x-ui.form.group
                    name="period"
                    :label="__('Reporting window')"
                    :hint="$dataset->requiresPeriod() ? __('Without one, the most recent window that opened is used.') : null"
                >
                    <x-ui.form.select
                        name="period"
                        :has-hint="$dataset->requiresPeriod()"
                        :options="$this->periodOptions"
                        :placeholder="__('Most recent window')"
                        wire:model.live="period"
                    />
                </x-ui.form.group>
            @endif

            @if (in_array($dataset, [App\Enums\ReportDataset::Projects, App\Enums\ReportDataset::Reports], true))
                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :options="$this->statusOptions"
                        :placeholder="__('Any status')"
                        wire:model.live="status"
                    />
                </x-ui.form.group>
            @endif

            @if ($dataset === App\Enums\ReportDataset::Reports)
                <x-ui.form.group name="lateness" :label="__('Timeliness')">
                    <x-ui.form.select
                        name="lateness"
                        :options="$this->latenessOptions()"
                        :placeholder="__('Filed at any time')"
                        wire:model.live="lateness"
                    />
                </x-ui.form.group>
            @endif

            @if ($dataset === App\Enums\ReportDataset::Indicators)
                <x-ui.form.group name="band" :label="__('Achievement band')">
                    <x-ui.form.select
                        name="band"
                        :options="$this->bandOptions()"
                        :placeholder="__('Any band')"
                        wire:model.live="band"
                    />
                </x-ui.form.group>
            @endif

            @if ($dataset !== App\Enums\ReportDataset::Compliance)
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Title, reference…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>
            @endif

            @if ($this->groupOptions() !== [])
                <x-ui.form.group
                    name="groupBy"
                    :label="__('Group by')"
                    :hint="__('Presentation only — grouping buckets the rows, it never changes a figure.')"
                >
                    <x-ui.form.select
                        name="groupBy"
                        has-hint
                        :options="$this->groupOptions()"
                        :placeholder="__('No grouping')"
                        wire:model.live="groupBy"
                    />
                </x-ui.form.group>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-4">
            @if ($dataset === App\Enums\ReportDataset::Projects)
                <x-ui.form.checkbox
                    name="overdue"
                    :label="__('Only projects past their delivery date')"
                    wire:model.live="overdue"
                />
            @endif

            @if ($this->hasFilters())
                <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                    {{ __('Clear filters') }}
                </x-ui.button>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Columns                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card
        class="mb-4"
        :title="__('Columns')"
        :subtitle="__('What each row prints, in this order.')"
    >
        <x-slot:actions>
            <x-ui.button size="sm" variant="ghost" icon="arrow-path" wire:click="resetColumns">
                {{ __('Reset to the usual set') }}
            </x-ui.button>
        </x-slot:actions>

        <fieldset>
            <legend class="sr-only">{{ __('Columns to print') }}</legend>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($labels as $key => $label)
                    {{-- wire:model on the array property, not a click handler:
                         a native checkbox toggled by re-render is the classic
                         morphdom desync, and the print order is canonical
                         anyway (ExportDefinition intersects with the dataset's
                         own column order). --}}
                    <x-ui.form.checkbox
                        :name="'column-'.$key"
                        :label="$label"
                        :value="$key"
                        wire:key="column-{{ $dataset->value }}-{{ $key }}"
                        wire:model.live="columns"
                    />
                @endforeach
            </div>
        </fieldset>

        <x-ui.form.error name="columns" id="columns-error" />
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Rows selected')"
            :value="number_format($total)"
            :icon="$dataset->icon()"
            :hint="$this->willQueue() ? __('Large — generation runs on a worker') : __('Generated on the spot')"
        />
        <x-ui.stat
            :label="__('Columns chosen')"
            :value="number_format(count($columns))"
            icon="squares"
            :hint="__('of :total available', ['total' => count($labels)])"
        />
        <x-ui.stat
            :label="__('Previewing')"
            :value="number_format(count($rows))"
            icon="eye"
            :hint="__('First 25 rows')"
        />
        <x-ui.stat
            :label="__('Grouping')"
            :value="$groupBy === '' ? __('None') : ($labels[$groupBy] ?? $groupBy)"
            icon="funnel"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Preview                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush :title="__('Preview')" :subtitle="__('The first twenty-five rows, exactly as the file will print them.')">
        <div
            wire:loading.delay.class.remove="hidden"
            wire:target="dataset,tenant,period,status,search,band,lateness,overdue,groupBy,columns,resetColumns,clearFilters"
            class="hidden p-4"
        >
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div
            wire:loading.delay.class="hidden"
            wire:target="dataset,tenant,period,status,search,band,lateness,overdue,groupBy,columns,resetColumns,clearFilters"
        >
            @if ($unreadable)
                <x-ui.empty-state
                    variant="error"
                    icon="shield-check"
                    :title="__('Not your dataset')"
                    :description="$unreadable"
                />
            @elseif ($columns === [])
                <x-ui.empty-state
                    variant="filtered"
                    icon="squares"
                    :title="__('No columns chosen')"
                    :description="__('A spreadsheet with no columns is not an export. Choose at least one above.')"
                >
                    <x-slot:actions>
                        <x-ui.button variant="secondary" icon="arrow-path" wire:click="resetColumns">
                            {{ __('Reset to the usual set') }}
                        </x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @elseif ($rows === [])
                <x-ui.empty-state
                    :variant="$this->hasFilters() ? 'filtered' : 'empty'"
                    :icon="$dataset->icon()"
                    :title="__('No records matched')"
                    :description="__('The question was asked and the answer is none. That is a finding in itself — you can still export it, and the filters go on the record with the file.')"
                >
                    @if ($this->hasFilters())
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty-state>
            @else
                @foreach ($grouped as $bucket => $bucketRows)
                    @if ($bucket !== '')
                        <h3 class="border-b border-line bg-surface-sunken px-4 py-2 text-sm font-semibold text-ink">
                            {{ $bucket }}
                            <span class="font-normal text-ink-muted">
                                {{ trans_choice('{1} :count row|[2,*] :count rows', count($bucketRows), ['count' => count($bucketRows)]) }}
                            </span>
                        </h3>
                    @endif

                    <x-ui.table
                        :caption="__(':dataset preview', ['dataset' => $dataset->label()])"
                        class="p-4 sm:p-0"
                        :headings="$headings"
                        wire:key="bucket-{{ $loop->index }}"
                    >
                        @foreach ($bucketRows as $index => $row)
                            <x-ui.table.row wire:key="row-{{ $loop->parent->index }}-{{ $index }}">
                                @foreach ($columns as $column)
                                    <x-ui.table.cell
                                        :label="$labels[$column] ?? $column"
                                        :numeric="in_array($column, $dataset->numericColumns(), true)"
                                        :primary="$loop->first"
                                    >
                                        @php $value = $row[$column] ?? null; @endphp

                                        @if ($value === null || $value === '')
                                            <span class="text-ink-subtle">—</span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </x-ui.table.cell>
                                @endforeach
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endforeach
            @endif
        </div>

        <x-slot:footer>
            <p class="text-xs text-ink-muted">
                {{ __('Every figure here is read through the same source as the dashboard that publishes it. A builder with its own query would eventually print a number the board disagrees with.') }}
            </p>
        </x-slot:footer>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Generate                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mt-4" :title="__('Take it away')">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.group
                name="title"
                :label="__('Title')"
                :hint="__('Printed on the document and stored on the register row.')"
                required
            >
                <x-ui.form.input name="title" has-hint maxlength="180" wire:model.blur="title" />
            </x-ui.form.group>

            <div class="flex flex-col justify-end gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                <x-ui.button
                    variant="secondary"
                    icon="document-text"
                    wire:click="generate('csv')"
                    loading="generate"
                    :disabled="(bool) $unreadable"
                >{{ __('CSV') }}</x-ui.button>

                <x-ui.button
                    variant="secondary"
                    icon="squares"
                    wire:click="generate('xlsx')"
                    loading="generate"
                    :disabled="(bool) $unreadable"
                >{{ __('Excel') }}</x-ui.button>

                <x-ui.button
                    icon="arrow-down-tray"
                    wire:click="generate('pdf')"
                    loading="generate"
                    :disabled="(bool) $unreadable"
                >{{ __('PDF') }}</x-ui.button>
            </div>
        </div>

        <p class="mt-3 text-xs text-ink-muted">
            {{ __('Every file is recorded in the export register with your name and the filters above, stored privately, and handed over through a signed link that expires.') }}
            @if ($this->willQueue())
                <span class="text-warning-ink">
                    {{ __('This selection is large, so it will be built on a worker and appear in the register when it is ready.') }}
                </span>
            @endif
        </p>
    </x-ui.card>
</div>
