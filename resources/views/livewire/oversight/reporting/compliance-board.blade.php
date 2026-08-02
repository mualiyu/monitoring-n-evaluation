{{--
    Compliance league table (App\Livewire\Oversight\Reporting\ComplianceBoard).

    The manual's rewards-and-sanctions instrument. The ranking IS the sanction,
    so the ordering is part of the meaning — and every rate carries its raw
    counts beside it, because "50%" of two obligations is not the same finding
    as "50%" of two hundred.
--}}
@php
    $board = $this->board;
    $period = $this->period;
    $previous = $this->previousPeriod;
    $stateRate = $this->stateOnTimeRate();
@endphp

<div>
    <x-ui.page-header
        :title="__('Reporting compliance')"
        :description="__('Which entities filed their statutory returns, and which filed them on time. Measured at submission — approval quality is a separate question.')"
    />

    @if ($board === null || $period === null)
        <x-ui.card>
            <x-ui.empty-state
                icon="calendar-days"
                :title="__('No reporting window has opened yet')"
                :description="__('The statutory calendar is generated ahead of each year. Once a window opens, compliance against it is measured here.')"
            />
        </x-ui.card>
    @else
        {{-- ------------------------------------------------------------ --}}
        {{-- Window toggle                                                 --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card class="mb-4" flush>
            <div class="flex flex-wrap items-end gap-3 p-4">
                <x-ui.form.group name="periodId" :label="__('Reporting window')" class="w-full sm:w-72">
                    <x-ui.form.select
                        name="periodId"
                        :options="$this->periods->pluck('label', 'id')->all()"
                        wire:model.live="periodId"
                    />
                </x-ui.form.group>

                @if ($previous)
                    <div class="pb-1">
                        <x-ui.button
                            variant="secondary"
                            size="sm"
                            icon="arrow-left"
                            wire:click="showPeriod({{ $previous->id }})"
                        >{{ __('Previous: :window', ['window' => $previous->label]) }}</x-ui.button>
                    </div>
                @endif

                <p class="ml-auto pb-1 text-sm text-ink-muted">
                    {{ __('Deadline :date', ['date' => $period->due_at->translatedFormat('j M Y')]) }}
                </p>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- State summary                                                 --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Returns expected')"
                :value="number_format($board['totals']['expected'])"
                icon="document-text"
                :hint="__(':count entities reporting', ['count' => count($board['tenants'])])"
            />
            <x-ui.stat
                :label="__('Filed')"
                :value="number_format($board['totals']['submitted'])"
                icon="check-circle"
            />
            <x-ui.stat
                :label="__('Filed on time')"
                :value="$stateRate === null ? '—' : $stateRate.'%'"
                icon="clock"
                :intent="$stateRate !== null && $stateRate < 60 ? 'critical' : 'neutral'"
                :hint="__(':count of :base returns', [
                    'count' => number_format($board['totals']['on_time']),
                    'base' => number_format($board['totals']['expected'] - $board['totals']['waived']),
                ])"
            />
            <x-ui.stat
                :label="__('Missed outright')"
                :value="number_format($board['totals']['missed'])"
                icon="exclamation-triangle"
                :intent="$board['totals']['missed'] > 0 ? 'critical' : 'neutral'"
            />
        </div>

        {{-- ------------------------------------------------------------ --}}
        {{-- League table                                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card flush>
            @if ($board['tenants'] === [])
                <x-ui.empty-state
                    icon="inbox"
                    :title="__('Nothing was owed for this window')"
                    :description="__('No entity had a reporting obligation generated against :window.', ['window' => $period->label])"
                />
            @else
                <x-ui.table
                    :caption="__('Reporting compliance by entity for :window', ['window' => $period->label])"
                    class="p-4 sm:p-0"
                    :headings="[
                        ['label' => __('Entity'), 'sortable' => true, 'sort' => $sort === 'name' ? $direction : null, 'click' => 'sortBy(\'name\')'],
                        ['label' => __('Expected'), 'align' => 'right', 'sortable' => true, 'sort' => $sort === 'expected' ? $direction : null, 'click' => 'sortBy(\'expected\')'],
                        ['label' => __('Filed'), 'align' => 'right', 'sortable' => true, 'sort' => $sort === 'submitted' ? $direction : null, 'click' => 'sortBy(\'submitted\')'],
                        ['label' => __('On time'), 'align' => 'right', 'sortable' => true, 'sort' => $sort === 'on_time' ? $direction : null, 'click' => 'sortBy(\'on_time\')'],
                        ['label' => __('Missed'), 'align' => 'right', 'sortable' => true, 'sort' => $sort === 'missed' ? $direction : null, 'click' => 'sortBy(\'missed\')'],
                        ['label' => __('On-time rate'), 'sortable' => true, 'sort' => $sort === 'on_time_rate' ? $direction : null, 'click' => 'sortBy(\'on_time_rate\')'],
                    ]"
                >
                    @foreach ($board['tenants'] as $row)
                        <x-ui.table.row wire:key="compliance-{{ $row['tenant_id'] }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                @if ($row['slug'])
                                    <a
                                        href="{{ url('/portfolio/'.$row['slug']) }}"
                                        class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] ?? __('Unknown entity') }}
                                @endif

                                @if ($row['waived'] > 0)
                                    <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {{ trans_choice('{1} :count waived obligation|[2,*] :count waived obligations', $row['waived'], ['count' => $row['waived']]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Expected')" numeric>{{ number_format($row['expected']) }}</x-ui.table.cell>
                            <x-ui.table.cell :label="__('Filed')" numeric>{{ number_format($row['submitted']) }}</x-ui.table.cell>
                            <x-ui.table.cell :label="__('On time')" numeric>{{ number_format($row['on_time']) }}</x-ui.table.cell>

                            <x-ui.table.cell :label="__('Missed')" numeric>
                                <span @class(['font-medium text-critical-ink' => $row['missed'] > 0])>
                                    {{ number_format($row['missed']) }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('On-time rate')">
                                @if ($row['on_time_rate'] === null)
                                    <span class="text-sm text-ink-subtle">{{ __('Nothing scored') }}</span>
                                @else
                                    {{-- The bar carries the rate; the count beside
                                         it carries the weight. 50% of two is not
                                         the finding 50% of two hundred is. --}}
                                    <x-ui.progress
                                        :value="$row['on_time_rate']"
                                        :label="__('On-time rate for :entity', ['entity' => $row['name'] ?? __('entity')])"
                                        size="sm"
                                        class="sm:w-40"
                                    />
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif

            <x-slot:footer>
                <p class="text-xs text-ink-muted">
                    {{ __('An entity is credited for a return when it is FILED, not when it is approved — the sanction is for not reporting, never for a director’s inaction. Waived obligations are excluded from the base.') }}
                </p>
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
