{{--
    M&E reporting calendar (App\Livewire\Tenant\Reporting\ReportingCalendar).

    Two tables, one question: the statutory windows and how this workspace
    stands against each (top), then the per-project detail of whichever window
    is open (bottom). Deadlines and countdowns come from the models and the
    configured ladder — never from a literal in this file.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);
    $reportUrl = fn ($report) => route('tenant.reports.show', [...$workspace, 'report' => $report]);
    $fileUrl = fn ($obligation) => route('tenant.reports.create', [
        ...$workspace,
        'project' => $obligation->project->ulid,
        'period' => $obligation->reporting_period_id,
    ]);

    $stats = $this->stats;
    $period = $this->period;
    $ladder = $this->reminderLadder();
    $escalation = $this->escalationLadder();
    $canCreate = auth()->user()?->can('create', \App\Models\ProgressReport::class) ?? false;

    $windowBadge = [
        'upcoming' => ['status' => 'pending', 'label' => __('Not yet open')],
        'open' => ['status' => 'in_progress', 'label' => __('Open for filing')],
        'overdue' => ['status' => 'overdue', 'label' => __('Past deadline')],
        'closed' => ['status' => 'closed', 'label' => __('Closed')],
    ];
@endphp

<div>
    <x-ui.page-header
        :title="__('Reporting calendar')"
        :description="__('The statutory reporting windows and what this entity owes against each one. Deadlines are set state-wide — every entity is measured against the same calendar.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="inbox"
                :href="route('tenant.reports.inbox', $workspace)"
            >{{ __('My inbox') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="document-text"
                :href="route('tenant.reports.index', $workspace)"
            >{{ __('All returns') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Where this workspace stands                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Returns outstanding')"
            :value="number_format($stats['outstanding'])"
            icon="document-text"
            :hint="__('across every open window')"
        />
        <x-ui.stat
            :label="__('Due within :days days', ['days' => $this->leadDays()])"
            :value="number_format($stats['due_soon'])"
            icon="clock"
            :intent="$stats['due_soon'] > 0 ? 'warning' : 'neutral'"
            :hint="__('the point at which reminders begin')"
        />
        <x-ui.stat
            :label="__('Past their deadline')"
            :value="number_format($stats['overdue'])"
            icon="exclamation-triangle"
            :intent="$stats['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$stats['overdue'] > 0 ? __('recorded as late on the state board') : __('nothing overdue')"
        />
        <x-ui.stat
            :label="__('Waived')"
            :value="number_format($stats['waived'])"
            icon="pause-circle"
            :hint="__('excused, and excluded from the score')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.form.group name="cadence" :label="__('Reporting rhythm')">
                    <x-ui.form.select
                        name="cadence"
                        :placeholder="__('Every rhythm')"
                        :options="$this->cadenceOptions"
                        wire:model.live="cadence"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="projectUlid" :label="__('Project')" :hint="__('Narrows the window detail below')">
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('All projects')"
                        :options="$this->projectOptions"
                        has-hint
                        wire:model.live="projectUlid"
                    />
                </x-ui.form.group>
            </div>

            @if ($this->hasFilters())
                <div class="mt-3 flex justify-end">
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The calendar                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush :title="__('Statutory windows')" :subtitle="__('Newest first. Choose a window to see what each project owes against it.')">
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="cadence,projectUlid,showPeriod">
            @if ($this->periods->isEmpty())
                <x-ui.empty-state
                    icon="calendar-days"
                    :title="__('No reporting window has opened yet')"
                    :description="__('The statutory calendar is generated ahead of each year. Windows appear here as they open, together with what this entity owes against them.')"
                />
            @else
                <x-ui.table
                    :caption="__('Statutory reporting windows and this entity’s record against each')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Window'),
                        __('Deadline'),
                        __('Window state'),
                        ['label' => __('Owed'), 'align' => 'right'],
                        __('Record'),
                        '',
                    ]"
                >
                    @foreach ($this->periods as $window)
                        @php
                            $counts = $this->countsFor($window);
                            $state = $this->windowState($window);
                            $badge = $windowBadge[$state];
                            $isOpenWindow = $period && $period->id === $window->id;
                        @endphp

                        <x-ui.table.row wire:key="window-{{ $window->id }}" :muted="$counts['expected'] === 0">
                            <x-ui.table.cell :label="__('Window')" primary>
                                {{ $window->label }}
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $window->code }} · {{ $window->cadence->label() }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Deadline')">
                                <span @class(['font-medium text-critical-ink' => $state === 'overdue', 'text-ink' => $state !== 'overdue'])>
                                    {{ $window->due_at->translatedFormat('j M Y') }}
                                </span>
                                <span class="mt-0.5 block text-xs text-ink-muted">
                                    {{ __('covers :from – :to', [
                                        'from' => $window->period_start->translatedFormat('j M'),
                                        'to' => $window->period_end->translatedFormat('j M Y'),
                                    ]) }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Window state')">
                                <x-ui.badge :status="$badge['status']" :label="$badge['label']" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Owed')" numeric>
                                {{ number_format($counts['expected']) }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Record')" stacked>
                                @if ($counts['expected'] === 0)
                                    <span class="text-sm text-ink-subtle">{{ __('Nothing generated yet') }}</span>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @if ($counts['met'] > 0)
                                            <x-ui.badge status="fulfilled" size="sm" :label="__(':count filed', ['count' => $counts['met']])" />
                                        @endif
                                        @if ($counts['due'] > 0)
                                            <x-ui.badge status="pending" size="sm" :label="__(':count due', ['count' => $counts['due']])" />
                                        @endif
                                        @if ($counts['overdue'] > 0)
                                            <x-ui.badge status="overdue" size="sm" :label="__(':count overdue', ['count' => $counts['overdue']])" />
                                        @endif
                                        @if ($counts['missed'] > 0)
                                            <x-ui.badge status="missed" size="sm" :label="__(':count missed', ['count' => $counts['missed']])" />
                                        @endif
                                        @if ($counts['waived'] > 0)
                                            <x-ui.badge status="waived" size="sm" :label="__(':count waived', ['count' => $counts['waived']])" />
                                        @endif
                                    </div>
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
                                >{{ $isOpenWindow ? __('Showing') : __('Show detail') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Per-project detail for the chosen window                          --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($period)
        <x-ui.card
            flush
            :title="__('Detail for :window', ['window' => $period->label])"
            :subtitle="__('What each project under this entity owes against this window.')"
        >
            <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 sm:px-5">
                <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Move between windows') }}">
                    <x-ui.button
                        size="sm"
                        variant="secondary"
                        icon="arrow-left"
                        :disabled="$this->previousPeriod === null"
                        wire:click="showPeriod({{ $this->previousPeriod?->id ?? 0 }})"
                    >{{ $this->previousPeriod?->label ?? __('Earlier window') }}</x-ui.button>

                    <x-ui.button
                        size="sm"
                        variant="secondary"
                        trailing-icon="arrow-right"
                        :disabled="$this->nextPeriod === null"
                        wire:click="showPeriod({{ $this->nextPeriod?->id ?? 0 }})"
                    >{{ $this->nextPeriod?->label ?? __('Later window') }}</x-ui.button>
                </div>

                <p class="ml-auto text-sm text-ink-muted">
                    {{ __('Deadline :date', ['date' => $period->due_at->translatedFormat('j M Y')]) }}
                </p>
            </div>

            <div wire:loading.delay.long.flex class="hidden p-4">
                <x-ui.skeleton variant="table" :rows="4" />
            </div>

            <div wire:loading.delay.long.remove wire:target="cadence,projectUlid,showPeriod">
                @if ($this->obligations->isEmpty())
                    <x-ui.empty-state
                        icon="calendar-days"
                        :title="__('Nothing is owed for this window')"
                        :description="$this->projectUlid !== ''
                            ? __('The project you have filtered to owes no return for :window.', ['window' => $period->label])
                            : __('Obligations are generated nightly for every project under execution. They will appear here once this window applies to one.')"
                    />
                @else
                    <x-ui.table
                        :caption="__('What each project owes for :window', ['window' => $period->label])"
                        class="p-4 sm:p-0"
                        :headings="[
                            __('Project'),
                            __('Deadline'),
                            __('Status'),
                            '',
                        ]"
                    >
                        @foreach ($this->obligations as $obligation)
                            @php
                                $daysToDue = $obligation->daysToDue();
                                $isOverdue = $obligation->isOverdue();
                            @endphp

                            <x-ui.table.row wire:key="calendar-obligation-{{ $obligation->id }}">
                                <x-ui.table.cell :label="__('Project')" primary>
                                    @if ($obligation->project)
                                        <a
                                            href="{{ $projectUrl($obligation->project) }}"
                                            class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        >{{ $obligation->project->title }}</a>
                                        <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                            {{ $obligation->project->reference }}
                                        </span>
                                    @else
                                        {{ __('Entity-level return') }}
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Deadline')">
                                    <span @class(['font-medium text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                                        {{ $obligation->due_at->translatedFormat('j M Y') }}
                                    </span>

                                    {{-- The countdown, in words. Same definition
                                         the reminder ladder uses. --}}
                                    <span @class([
                                        'mt-0.5 block text-xs',
                                        'text-critical-ink' => $isOverdue,
                                        'text-warning-ink' => ! $isOverdue && $obligation->isOutstanding() && $daysToDue <= $this->leadDays(),
                                        'text-ink-muted' => ! $isOverdue && (! $obligation->isOutstanding() || $daysToDue > $this->leadDays()),
                                    ])>
                                        @if (! $obligation->isOutstanding())
                                            @if ($obligation->fulfilled_at)
                                                {{ __('filed :date', ['date' => $obligation->fulfilled_at->translatedFormat('j M')]) }}
                                            @else
                                                &mdash;
                                            @endif
                                        @elseif ($daysToDue < 0)
                                            {{ trans_choice('{1} :count day late|[2,*] :count days late', abs($daysToDue), ['count' => abs($daysToDue)]) }}
                                        @elseif ($daysToDue === 0)
                                            {{ __('due today') }}
                                        @else
                                            {{ trans_choice('{1} in :count day|[2,*] in :count days', $daysToDue, ['count' => $daysToDue]) }}
                                        @endif
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

                                <x-ui.table.cell align="right">
                                    @if ($obligation->progress_report_id !== null && $obligation->progressReport)
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            trailing-icon="chevron-right"
                                            :href="$reportUrl($obligation->progressReport)"
                                        >{{ __('Open return') }}</x-ui.button>
                                    @elseif ($canCreate && $obligation->project && $obligation->isOutstanding())
                                        <x-ui.button
                                            variant="secondary"
                                            size="sm"
                                            icon="pencil-square"
                                            :href="$fileUrl($obligation)"
                                        >{{ __('File it') }}</x-ui.button>
                                    @endif
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>

            @if ($this->obligations->isNotEmpty())
                <x-slot:footer>
                    <x-ui.pagination :paginator="$this->obligations" :label="__('Window detail pages')" />
                </x-slot:footer>
            @endif
        </x-ui.card>
    @endif

    <p class="mt-4 text-xs text-ink-muted">
        @if ($ladder === [])
            {{ __('This instance sends no advance reminders — the deadline on each window is the only notice.') }}
        @else
            {{ __('Reminders go out :days before each deadline.', ['days' => trans_choice('{1} :list day|[2,*] :list days', count($ladder), ['list' => implode(', ', $ladder)])]) }}
        @endif

        @if ($escalation !== [])
            {{ __('An unmet return escalates after :first day past due, and again after :second.', [
                'first' => $escalation[0],
                'second' => trans_choice('{1} :count day|[2,*] :count days', $escalation[1] ?? $escalation[0], ['count' => $escalation[1] ?? $escalation[0]]),
            ]) }}
        @endif
    </p>
</div>
