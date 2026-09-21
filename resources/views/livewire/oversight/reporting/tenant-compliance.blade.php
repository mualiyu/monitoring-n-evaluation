{{--
    Entity compliance record (App\Livewire\Oversight\Reporting\TenantCompliance).

    The league table's drill-down. Top: window by window, what this entity owed
    and what it did about it. Bottom: the individual projects behind whichever
    window is open — because "the ministry was 40% compliant in March" is an
    accusation until you can name the three projects that never reported.
--}}
@php
    $detail = $this->detail;
    $totals = $detail['totals'];
    $period = $this->period;
    $row = $period ? $this->rowFor($period) : null;

    $windowBadge = [
        'upcoming' => ['status' => 'pending', 'label' => __('Not yet open')],
        'open' => ['status' => 'in_progress', 'label' => __('Open for filing')],
        'overdue' => ['status' => 'overdue', 'label' => __('Past deadline')],
        'closed' => ['status' => 'closed', 'label' => __('Closed')],
    ];

    $stateOf = function ($window) {
        return match (true) {
            $window->isUpcoming() => 'upcoming',
            $window->isClosed() => 'closed',
            $window->isOverdue() => 'overdue',
            default => 'open',
        };
    };
@endphp

<div>
    <x-ui.page-header
        :title="$detail['tenant']['name']"
        :description="__('Reporting record: what this entity owed against each statutory window, and what it filed. Measured at submission — approval quality is a separate question.')"
        :back="route('oversight.compliance.index')"
        :back-label="__('Back to the compliance board')"
        :breadcrumbs="[
            ['label' => __('Reporting compliance'), 'href' => route('oversight.compliance.index')],
            ['label' => $detail['tenant']['name']],
        ]"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="folder"
                :href="route('oversight.portfolio.tenant', ['tenant' => $detail['tenant']['slug']])"
            >{{ __('Portfolio') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="document-text"
                :href="route('oversight.reports.index', ['mda' => $detail['tenant']['id']])"
            >{{ __('Its returns') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The record as a whole                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Returns expected')"
            :value="number_format($totals['expected'])"
            icon="document-text"
            :hint="trans_choice('{1} across :count window|[2,*] across :count windows', count($detail['periods']), ['count' => count($detail['periods'])])"
        />
        <x-ui.stat
            :label="__('Filed')"
            :value="number_format($totals['submitted'])"
            icon="check-circle"
            :hint="$totals['late'] > 0 ? __(':count of them late', ['count' => number_format($totals['late'])]) : __('none late')"
        />
        <x-ui.stat
            :label="__('Filed on time')"
            :value="$totals['on_time_rate'] === null ? '—' : $totals['on_time_rate'].'%'"
            icon="clock"
            :intent="$totals['on_time_rate'] !== null && $totals['on_time_rate'] < 60 ? 'critical' : 'neutral'"
            :hint="__(':count of :base scored returns', [
                'count' => number_format($totals['on_time']),
                'base' => number_format($totals['expected'] - $totals['waived']),
            ])"
        />
        <x-ui.stat
            :label="__('Still outstanding')"
            :value="number_format($totals['pending'])"
            icon="exclamation-triangle"
            :intent="$totals['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$totals['overdue'] > 0
                ? __(':count already past deadline', ['count' => number_format($totals['overdue'])])
                : __('none past deadline')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Window by window                                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush :title="__('By reporting window')" :subtitle="__('Newest first. Choose a window to see the projects behind its figures.')">
        @if ($this->periods->isEmpty())
            <x-ui.empty-state
                icon="calendar-days"
                :title="__('Nothing has ever been owed here')"
                :description="__('This entity has no reporting obligation against any window yet. Obligations are generated nightly for every project under execution.')"
            />
        @else
            <x-ui.table
                :caption="__('Reporting record by window for :entity', ['entity' => $detail['tenant']['name']])"
                class="p-4 sm:p-0"
                :headings="[
                    __('Window'),
                    __('Deadline'),
                    ['label' => __('Expected'), 'align' => 'right'],
                    ['label' => __('Filed'), 'align' => 'right'],
                    ['label' => __('Missed'), 'align' => 'right'],
                    __('On-time rate'),
                    '',
                ]"
            >
                @foreach ($this->periods as $window)
                    @php
                        $counts = $this->rowFor($window);
                        $state = $stateOf($window);
                        $badge = $windowBadge[$state];
                        $isOpenWindow = $period && $period->id === $window->id;
                    @endphp

                    @if ($counts)
                        <x-ui.table.row wire:key="tenant-window-{{ $window->id }}">
                            <x-ui.table.cell :label="__('Window')" primary>
                                {{ $counts['label'] }}
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $counts['code'] }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Deadline')">
                                <span @class(['font-medium text-critical-ink' => $state === 'overdue', 'text-ink' => $state !== 'overdue'])>
                                    {{ $counts['due_at']->translatedFormat('j M Y') }}
                                </span>
                                <span class="mt-1 block">
                                    <x-ui.badge :status="$badge['status']" size="sm" :label="$badge['label']" />
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Expected')" numeric>
                                {{ number_format($counts['expected']) }}
                                @if ($counts['waived'] > 0)
                                    <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {{ __(':count waived', ['count' => $counts['waived']]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Filed')" numeric>
                                {{ number_format($counts['submitted']) }}
                                @if ($counts['late'] > 0)
                                    <span class="mt-0.5 block text-xs font-normal text-warning-ink">
                                        {{ __(':count late', ['count' => $counts['late']]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Missed')" numeric>
                                <span @class(['font-medium text-critical-ink' => $counts['missed'] > 0])>
                                    {{ number_format($counts['missed']) }}
                                </span>
                                @if ($counts['overdue'] > 0)
                                    <span class="mt-0.5 block text-xs font-normal text-critical-ink">
                                        {{ __(':count overdue', ['count' => $counts['overdue']]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('On-time rate')">
                                @if ($counts['on_time_rate'] === null)
                                    <span class="text-sm text-ink-subtle">{{ __('Nothing scored') }}</span>
                                @else
                                    {{-- The bar carries the rate; the counts beside
                                         it carry the weight. --}}
                                    <x-ui.progress
                                        :value="$counts['on_time_rate']"
                                        :label="__('On-time rate for :window', ['window' => $counts['label']])"
                                        size="sm"
                                        class="sm:w-40"
                                    />
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    size="sm"
                                    :variant="$isOpenWindow ? 'primary' : 'ghost'"
                                    trailing-icon="chevron-right"
                                    wire:click="showPeriod({{ $window->id }})"
                                    loading="showPeriod({{ $window->id }})"
                                    :aria-pressed="$isOpenWindow ? 'true' : 'false'"
                                >{{ $isOpenWindow ? __('Showing') : __('Show projects') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endif
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The projects behind one window                                    --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($period && $this->obligations)
        <x-ui.card
            flush
            :title="__('Projects in :window', ['window' => $row['label'] ?? $period->label])"
            :subtitle="__('Outstanding returns first — those are the rows that explain the score.')"
        >
            <div wire:loading.delay.long.flex class="hidden p-4">
                <x-ui.skeleton variant="table" :rows="4" />
            </div>

            <div wire:loading.delay.long.remove wire:target="showPeriod">
                @if ($this->obligations->isEmpty())
                    <x-ui.empty-state
                        icon="inbox"
                        :title="__('Nothing was owed for this window')"
                        :description="__('No obligation was generated for this entity against :window.', ['window' => $period->label])"
                    />
                @else
                    <x-ui.table
                        :caption="__('Project-level obligations for :window', ['window' => $period->label])"
                        class="p-4 sm:p-0"
                        :headings="[
                            __('Project'),
                            __('Deadline'),
                            __('Status'),
                            __('Filed'),
                        ]"
                    >
                        @foreach ($this->obligations as $obligation)
                            @php $isOverdue = $obligation->isOverdue(); @endphp

                            <x-ui.table.row wire:key="tenant-obligation-{{ $obligation->id }}">
                                <x-ui.table.cell :label="__('Project')" primary>
                                    {{ $obligation->project?->title ?? __('Entity-level return') }}
                                    @if ($obligation->project)
                                        <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                            {{ $obligation->project->reference }}
                                        </span>
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Deadline')">
                                    <span @class(['font-medium text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                                        {{ $obligation->due_at->translatedFormat('j M Y') }}
                                    </span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Status')">
                                    <x-ui.badge :status="$isOverdue ? 'overdue' : $obligation->status->value" />

                                    @if ($obligation->submitted_late)
                                        <span class="mt-1 block">
                                            <x-ui.badge status="overdue" size="sm" :label="__('Filed late')" />
                                        </span>
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Filed')">
                                    @if ($obligation->fulfilled_at)
                                        <span class="text-ink">{{ $obligation->fulfilled_at->translatedFormat('j M Y') }}</span>
                                        @if ($obligation->progressReport)
                                            <span class="mt-0.5 block text-xs text-ink-muted">
                                                {{ $obligation->progressReport->status->label() }}
                                            </span>
                                        @endif
                                    @elseif ($obligation->waiver_reason)
                                        <span class="text-sm text-ink-muted">{{ $obligation->waiver_reason }}</span>
                                    @else
                                        <span class="text-ink-muted">&mdash;</span>
                                    @endif
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>

            @if ($this->obligations->isNotEmpty())
                <x-slot:footer>
                    <x-ui.pagination :paginator="$this->obligations" :label="__('Window project pages')" />
                </x-slot:footer>
            @endif
        </x-ui.card>
    @endif

    <p class="mt-4 text-xs text-ink-muted">
        {{ __('An entity is credited for a return when it is FILED, not when it is approved. Waived obligations are excluded from the base, so an excused window neither helps nor harms the score.') }}
    </p>
</div>
