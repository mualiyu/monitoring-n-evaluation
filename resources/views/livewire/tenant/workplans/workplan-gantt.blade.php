{{--
    Implementation timeline (App\Livewire\Tenant\Workplans\WorkplanGantt) —
    the manual's Appendix A, `no | activity | owner | month × week`.

    Plain HTML + CSS grid, NOT a charting library: it renders without
    JavaScript, it prints, and it works on the Android WebViews field monitors
    carry. Positions come from the component as integers and are applied as
    inline grid-column values — Tailwind cannot compile a class it has never
    seen, and dynamic spans are exactly that case.

    Accessibility contract for every bar:
      · a visible text label (the percentage),
      · an aria-label stating title, status, percentage and both dates,
      · a status badge with icon + text in the row's sticky name column.
    Status is therefore never conveyed by colour or position alone, and the
    table view below carries the identical data as rows.
--}}
@php
    $plan = $workplan;
    $columns = $this->columns;
    $rows = $this->rows;
    $summary = $this->summary;
    $todayColumn = $this->todayColumn;
    $columnCount = max(1, count($columns));
@endphp

<div>
    <x-ui.page-header
        :title="__('Timeline — :plan', ['plan' => $plan->title])"
        :description="__('When each activity is scheduled across the plan period, and how far it has got.')"
        :back="route('tenant.workplans.show', $plan)"
        :backLabel="__('Back to the plan')"
        :breadcrumbs="[
            ['label' => __('Work plans'), 'href' => route('tenant.workplans.index')],
            ['label' => $plan->yearLabel(), 'href' => route('tenant.workplans.show', $plan)],
            ['label' => __('Timeline')],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge :status="$plan->status->badge()" :label="$plan->status->label()" />
        </x-slot:actions>
    </x-ui.page-header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- View controls                                                     --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
            <fieldset class="flex items-center gap-2">
                <legend class="sr-only">{{ __('Timeline scale') }}</legend>
                <span class="text-sm font-medium text-ink">{{ __('Scale') }}</span>
                <x-ui.button
                    size="sm"
                    :variant="$scale === 'month' ? 'primary' : 'secondary'"
                    wire:click="setScale('month')"
                    :aria-pressed="$scale === 'month' ? 'true' : 'false'"
                >{{ __('Months') }}</x-ui.button>
                <x-ui.button
                    size="sm"
                    :variant="$scale === 'week' ? 'primary' : 'secondary'"
                    wire:click="setScale('week')"
                    :aria-pressed="$scale === 'week' ? 'true' : 'false'"
                >{{ __('Weeks') }}</x-ui.button>
            </fieldset>

            <x-ui.form.checkbox
                name="asTable"
                :label="__('Show as a table instead')"
                :description="__('Identical data as rows — useful for screen readers, printing and copying into a report.')"
                wire:model.live="asTable"
            />
        </div>
    </x-ui.card>

    @if (empty($rows))
        <x-ui.card flush>
            <x-ui.empty-state
                :title="__('Nothing to draw yet')"
                :description="__('The timeline shows this plan\'s activities across its period. Add activities to the plan and they will appear here.')"
            >
                <x-slot:actions>
                    <x-ui.button icon="arrow-left" :href="route('tenant.workplans.show', $plan)">
                        {{ __('Back to the plan') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @elseif ($asTable)
        {{-- ------------------------------------------------------------ --}}
        {{-- Table fallback — the same data, as rows                       --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card flush>
            <x-ui.table
                :caption="__('Every activity of this work plan with its planned dates, status and progress')"
                class="p-4 sm:p-0"
                :headings="[
                    __('#'),
                    __('Activity'),
                    __('Owner'),
                    __('Planned start'),
                    __('Planned end'),
                    __('Starts after'),
                    __('Status'),
                    __('Progress'),
                ]"
            >
                @foreach ($rows as $row)
                    @php $activity = $row['activity']; @endphp
                    <x-ui.table.row wire:key="gantt-row-{{ $activity->ulid }}">
                        <x-ui.table.cell :label="__('#')">
                            <span class="tabular-nums text-ink-muted">{{ $loop->iteration }}</span>
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Activity')" primary>
                            {{ $activity->title }}
                            @if ($row['unlinked'])
                                <span class="mt-0.5 flex items-center gap-1 text-xs font-medium text-critical-ink">
                                    <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                    {{ __('No output indicator') }}
                                </span>
                            @endif
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Owner')">
                            <span class="text-ink-muted">{{ $activity->owner?->name ?? '—' }}</span>
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Planned start')">
                            {{ $activity->planned_start->translatedFormat('j M Y') }}
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Planned end')">
                            {{ $activity->planned_end->translatedFormat('j M Y') }}
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Starts after')">
                            <span class="text-ink-muted">
                                {{ $row['dependency'] ? __('activity :number', ['number' => $row['dependency']]) : '—' }}
                            </span>
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Status')">
                            <x-ui.badge
                                :status="$activity->status->badge()"
                                :label="$activity->status->label()"
                                :icon="$activity->status->icon()"
                                size="sm"
                            />
                        </x-ui.table.cell>
                        <x-ui.table.cell :label="__('Progress')">
                            <x-ui.progress
                                :value="$activity->progress_percent"
                                :label="__('Progress for :activity', ['activity' => $activity->title])"
                                size="sm"
                                class="sm:w-28"
                            />
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @else
        {{-- ------------------------------------------------------------ --}}
        {{-- The grid                                                      --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card flush>
            {{-- Horizontal scroll with a sticky activity-name column: the
                 360px contract. The name never leaves the screen, so a bar is
                 always attributable to a row. --}}
            <div class="overflow-x-auto" tabindex="0" role="group" aria-label="{{ __('Work plan timeline, scrollable') }}">
                <div class="min-w-[44rem]">
                    {{-- Column headings --}}
                    <div class="flex border-b border-line bg-surface-sunken">
                        <div class="sticky left-0 z-20 w-44 shrink-0 border-r border-line bg-surface-sunken px-3 py-2 text-xs font-semibold text-ink sm:w-64">
                            {{ __('Activity') }}
                        </div>
                        <div
                            class="grid flex-1"
                            style="grid-template-columns: repeat({{ $columnCount }}, minmax(3rem, 1fr));"
                        >
                            @foreach ($columns as $column)
                                <div class="border-r border-line px-1 py-2 text-center last:border-r-0">
                                    <span class="block text-xs font-semibold text-ink">{{ $column['label'] }}</span>
                                    <span class="block text-[0.65rem] text-ink-subtle">{{ $column['sublabel'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Rows --}}
                    @foreach ($rows as $row)
                        @php $activity = $row['activity']; @endphp
                        <div class="flex border-b border-line last:border-b-0" wire:key="gantt-bar-{{ $activity->ulid }}">
                            <div class="sticky left-0 z-10 w-44 shrink-0 border-r border-line bg-surface-raised px-3 py-3 sm:w-64">
                                <p class="text-sm font-medium text-ink">
                                    <span class="text-ink-subtle tabular-nums">{{ $loop->iteration }}.</span>
                                    {{ $activity->title }}
                                </p>
                                <p class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <x-ui.badge
                                        :status="$activity->status->badge()"
                                        :label="$activity->status->label()"
                                        :icon="$activity->status->icon()"
                                        size="sm"
                                    />
                                    @if ($row['unlinked'])
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-critical-ink">
                                            <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                            {{ __('no indicator') }}
                                        </span>
                                    @endif
                                </p>
                                @if ($row['dependency'])
                                    <p class="mt-0.5 text-xs text-ink-muted">
                                        {{ __('starts after activity :number', ['number' => $row['dependency']]) }}
                                    </p>
                                @endif
                            </div>

                            <div
                                class="relative grid flex-1 items-center py-3"
                                style="grid-template-columns: repeat({{ $columnCount }}, minmax(3rem, 1fr));"
                            >
                                {{-- Column guides. Decorative: the dates live
                                     in the headings and in each bar's label. --}}
                                @foreach ($columns as $guide)
                                    <div
                                        class="pointer-events-none absolute inset-y-0 border-r border-line/60 last:border-r-0"
                                        style="grid-column: {{ $loop->iteration }} / span 1;"
                                        aria-hidden="true"
                                    ></div>
                                @endforeach

                                @if ($todayColumn)
                                    <div
                                        class="pointer-events-none absolute inset-y-0 w-0.5 bg-brand"
                                        style="grid-column: {{ $todayColumn }} / span 1;"
                                        aria-hidden="true"
                                    ></div>
                                @endif

                                {{-- The bar. A text label inside, a full
                                     description on the element, and the fill
                                     is progress — never the status. --}}
                                <div
                                    class="relative z-[1] mx-1 flex h-7 items-center overflow-hidden rounded-md border border-line-strong bg-neutral-soft"
                                    style="grid-column: {{ $row['start'] }} / span {{ $row['span'] }};"
                                    role="img"
                                    aria-label="{{ $row['aria'] }}"
                                >
                                    <div
                                        class="absolute inset-y-0 left-0 bg-brand-soft"
                                        style="width: {{ $row['progress'] }}%;"
                                        aria-hidden="true"
                                    ></div>
                                    <span class="relative px-2 text-xs font-semibold whitespace-nowrap text-ink">
                                        {{ $row['label'] }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-ink-muted">
                    <span>{{ __('Bars span the planned dates; the shaded portion is progress recorded.') }}</span>
                    @if ($todayColumn)
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-3 w-0.5 bg-brand" aria-hidden="true"></span>
                            {{ __('Today falls in :column', ['column' => $columns[$todayColumn - 1]['label'].' '.$columns[$todayColumn - 1]['sublabel']]) }}
                        </span>
                    @endif
                    <span>
                        {{ __(':done of :count activities complete', [
                            'done' => $summary['completed'],
                            'count' => $summary['counted'],
                        ]) }}
                    </span>
                </div>
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
