{{--
    The secretariat's consolidation desk
    (App\Livewire\Oversight\Consolidation\ConsolidationWorkspace).

    One row per roll-up the state has opened. The column that matters is
    COVERAGE: how much of the state actually answered, as a fraction of who was
    expected to. A consolidation covering 6 of 40 entities is a finding, not a
    report, and the list says so on its face rather than burying it inside.

    Nothing is computed in this file. Opening a consolidation goes through
    App\Actions\Consolidation\OpenConsolidation, which owns the one-per-window
    rule and the cadence guard; a refusal comes back as a field error.
--}}
@php
    $stats = $this->stats;
    $reports = $this->reports;
@endphp

<div>
    <x-ui.page-header
        :title="__('State consolidations')"
        :description="__('Every roll-up the state has opened: the window it covers, how much of the state answered, and where it has reached in the chain.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                :href="route('oversight.exports.index')"
            >{{ __('Generated artifacts') }}</x-ui.button>

            @can('create', App\Models\ConsolidatedReport::class)
                <x-ui.button icon="plus" size="sm" wire:click="startOpening" loading="startOpening">
                    {{ __('Open a consolidation') }}
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Open a roll-up for a window                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($opening)
        <x-ui.card
            class="mb-4"
            :title="__('Open a consolidation')"
            :subtitle="__('A window and a type. The narrative skeleton for that type is created empty — a chapter may be left blank, but it cannot be quietly dropped.')"
        >
            <form wire:submit="open" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group
                        name="newPeriod"
                        :label="__('Reporting window')"
                        :hint="__('The window sets the denominator — who owed a return.')"
                        required
                    >
                        <x-ui.form.select
                            name="newPeriod"
                            has-hint
                            :options="$this->periodOptions()"
                            :placeholder="__('Choose a window…')"
                            wire:model="newPeriod"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group name="newType" :label="__('Report type')" required>
                        <x-ui.form.select
                            name="newType"
                            :options="$this->typeOptions()"
                            wire:model.live="newType"
                        />
                    </x-ui.form.group>
                </div>

                @php $chosenType = App\Enums\ConsolidatedReportType::tryFrom($newType); @endphp

                @if ($chosenType)
                    <x-ui.alert variant="neutral" :icon="$chosenType->icon()">
                        {{ $chosenType->description() }}
                    </x-ui.alert>
                @endif

                <x-ui.form.group
                    name="newTitle"
                    :label="__('Title')"
                    :hint="__('Leave blank to take the type and the window, e.g. “Annual Performance Report — 2026”.')"
                    optional
                >
                    <x-ui.form.input name="newTitle" has-hint maxlength="180" wire:model="newTitle" />
                </x-ui.form.group>

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button variant="secondary" wire:click="cancelOpening" type="button">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" icon="plus" loading="open">
                        {{ __('Open it') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <x-ui.form.group name="status" :label="__('Chain state')" class="w-full sm:w-52">
                <x-ui.form.select
                    name="status"
                    :options="$this->statusOptions()"
                    :placeholder="__('Any state')"
                    wire:model.live="status"
                />
            </x-ui.form.group>

            <x-ui.form.group name="type" :label="__('Report type')" class="w-full sm:w-60">
                <x-ui.form.select
                    name="type"
                    :options="$this->typeOptions()"
                    :placeholder="__('Any type')"
                    wire:model.live="type"
                />
            </x-ui.form.group>

            @if ($this->hasFilters())
                <div class="sm:pb-1">
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('On the desk')"
            :value="number_format($stats['open'])"
            icon="pencil-square"
            :hint="__('Draft or compiling')"
        />
        <x-ui.stat
            :label="__('Awaiting signature')"
            :value="number_format($stats['in_review'])"
            icon="eye"
        />
        <x-ui.stat
            :label="__('Approved')"
            :value="number_format($stats['approved'])"
            icon="check-circle"
            :hint="__('Figures frozen')"
        />
        <x-ui.stat
            :label="__('Published')"
            :value="number_format($stats['published'])"
            icon="globe"
            :hint="trans_choice('{1} :count entity on the instance|[2,*] :count entities on the instance', $stats['entities'], ['count' => number_format($stats['entities'])])"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The register                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.class.remove="hidden" wire:target="status,type,gotoPage,nextPage,previousPage" class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.class="hidden" wire:target="status,type,gotoPage,nextPage,previousPage">
            @if ($reports->total() === 0)
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :title="__('No consolidation matches those filters')"
                        :description="__('The state has opened roll-ups, but none in that state or of that type.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="flag"
                        :title="__('No consolidation has been opened yet')"
                        :description="__('A consolidation rolls every entity’s returns for one window into the single artifact the state is judged on. Open one against a window that has already closed.')"
                    >
                        <x-slot:actions>
                            @can('create', App\Models\ConsolidatedReport::class)
                                <x-ui.button icon="plus" wire:click="startOpening">
                                    {{ __('Open a consolidation') }}
                                </x-ui.button>
                            @endcan
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('State consolidations, newest first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Consolidation'),
                        __('Window'),
                        __('State'),
                        ['label' => __('Coverage'), 'align' => 'left'],
                        __('Chain'),
                    ]"
                >
                    @foreach ($reports as $report)
                        @php
                            $coverage = $report->coverageRate();
                            $thin = $coverage !== null && $coverage < 60;
                        @endphp

                        <x-ui.table.row wire:key="consolidation-{{ $report->ulid }}">
                            <x-ui.table.cell :label="__('Consolidation')" primary>
                                <a
                                    href="{{ route('oversight.consolidation.show', ['consolidatedReport' => $report]) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    wire:navigate
                                >{{ $report->title }}</a>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs font-normal text-ink-muted">
                                    <span class="font-mono">{{ $report->reference }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $report->type->label() }}</span>
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Window')">
                                {{ $report->reportingPeriod->label }}
                                <span class="block text-xs text-ink-muted">{{ $report->reportingPeriod->cadence->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('State')">
                                {{-- Icon + text, never colour alone. --}}
                                <x-ui.badge :status="$report->status->badge()" :label="$report->status->label()" :icon="$report->status->icon()" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Coverage')">
                                @if ($coverage === null)
                                    <span class="inline-flex items-center gap-1.5 text-sm text-ink-subtle">
                                        <x-ui.icon name="minus" class="size-4" />
                                        {{ __('Not compiled') }}
                                    </span>
                                @else
                                    <x-ui.progress
                                        :value="$coverage"
                                        :intent="$thin ? 'warning' : 'auto'"
                                        :label="__('Entities reporting into :reference', ['reference' => $report->reference])"
                                        size="sm"
                                        class="sm:w-40"
                                    />
                                    <span class="block text-xs text-ink-muted tabular-nums">
                                        {{ __(':count of :total entities', [
                                            'count' => number_format($report->entity_count),
                                            'total' => number_format($report->denominator),
                                        ]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Chain')">
                                <span class="block text-xs text-ink-muted">
                                    @if ($report->approved_at)
                                        {{ __('Signed :date by :user', [
                                            'date' => $report->approved_at->translatedFormat('j M Y'),
                                            'user' => $report->approvedBy?->name ?? __('an officer since removed'),
                                        ]) }}
                                    @elseif ($report->compiled_at)
                                        {{ __('Compiled :date by :user', [
                                            'date' => $report->compiled_at->translatedFormat('j M Y'),
                                            'user' => $report->compiledBy?->name ?? __('an officer since removed'),
                                        ]) }}
                                    @else
                                        {{ __('Opened :date', ['date' => $report->created_at?->translatedFormat('j M Y')]) }}
                                    @endif
                                </span>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-slot:footer>
            <x-ui.pagination :paginator="$reports" :label="__('Consolidation pages')" />
        </x-slot:footer>
    </x-ui.card>
</div>
