{{--
    Reporting inbox (App\Livewire\Tenant\Reporting\ReportInbox).

    A queue, not a register: every row is something this user is being asked to
    do. The data-heavy pattern still applies — filter bar → stat row → table
    (cards under sm:) → pagination — with an empty state that reads as good
    news rather than as a broken screen.
--}}
@php
    use App\Enums\ProgressReportStatus;

    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link here carries the workspace explicitly. A hand-built string
    // would 404 silently the day a path changes; route() fails loudly.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $reportUrl = fn ($report) => route('tenant.reports.show', [...$workspace, 'report' => $report]);
    $editUrl = fn ($report) => route('tenant.reports.edit', [...$workspace, 'report' => $report]);

    $stats = $this->stats;
@endphp

<div>
    <x-ui.page-header
        :title="__('My reporting inbox')"
        :description="__('Returns waiting on you — to review, to approve, or to correct and refile. A return you filed yourself never appears here as yours to clear.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="calendar-days"
                :href="route('tenant.reports.calendar', $workspace)"
            >{{ __('Reporting calendar') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="document-text"
                :href="route('tenant.reports.index', $workspace)"
            >{{ __('All returns') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — the queue itself, deliberately NOT filtered         --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Waiting on you')"
            :value="number_format($stats['total'])"
            icon="inbox"
            :intent="$stats['total'] > 0 ? 'warning' : 'neutral'"
            :hint="$stats['total'] === 0 ? __('nothing outstanding') : __('across every step you own')"
        />

        @if ($this->canReviewQueue())
            <x-ui.stat
                :label="__('To review')"
                :value="number_format($stats['review'])"
                icon="clipboard-check"
                :hint="__('filed by someone else')"
            />
        @endif

        @if ($this->canApproveQueue())
            <x-ui.stat
                :label="__('To approve')"
                :value="number_format($stats['approve'])"
                icon="check-circle"
                :hint="$this->separateApproverRequired() ? __('excluding returns you reviewed') : __('reviewed and awaiting sign-off')"
            />
        @endif

        <x-ui.stat
            :label="__('Past the deadline')"
            :value="number_format($stats['overdue'])"
            icon="exclamation-triangle"
            :intent="$stats['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$stats['overdue'] > 0 ? __('the chain is what is holding these up') : __('none overdue')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Project title or reference…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="queue" :label="__('Step')">
                    <x-ui.form.select
                        name="queue"
                        :placeholder="__('Everything waiting on me')"
                        :options="$this->queueOptions"
                        wire:model.live="queue"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="periodId" :label="__('Reporting window')">
                    <x-ui.form.select
                        name="periodId"
                        :placeholder="__('Any window')"
                        :options="$this->periods->pluck('label', 'id')->all()"
                        wire:model.live="periodId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="projectUlid" :label="__('Project')">
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('All projects')"
                        :options="$this->projectOptions"
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
    {{-- The queue                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,queue,periodId,projectUlid">
            @if ($this->reports->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('Nothing in your inbox matches the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="check-circle"
                        :title="__('Your inbox is clear')"
                        :description="__('Nothing is waiting on you. Returns arrive here when they are filed for your review, sent to you for approval, or returned to you for correction.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="calendar-days" :href="route('tenant.reports.calendar', $workspace)">
                                {{ __('See what is due') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Progress returns waiting on you, oldest first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Project'),
                        __('Window'),
                        __('Filed'),
                        __('Waiting for'),
                        '',
                    ]"
                >
                    @foreach ($this->reports as $report)
                        @php
                            $isOverdue = $report->due_at->isPast();
                            $isMine = $report->status === ProgressReportStatus::Returned;
                        @endphp

                        <x-ui.table.row wire:key="inbox-{{ $report->ulid }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a
                                    href="{{ $reportUrl($report) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $report->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $report->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Window')">
                                <span class="text-ink-muted">{{ $report->reportingPeriod->label }}</span>
                                <span @class([
                                    'mt-0.5 block text-xs',
                                    'text-critical-ink' => $isOverdue,
                                    'text-ink-muted' => ! $isOverdue,
                                ])>
                                    {{ __('due :date', ['date' => $report->due_at->translatedFormat('j M Y')]) }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Filed')">
                                @if ($report->submitted_at)
                                    <span class="text-ink">{{ $report->submitted_at->translatedFormat('j M Y') }}</span>
                                    <span class="mt-0.5 block text-xs text-ink-muted">
                                        {{ $report->submittedBy?->name ?? __('Unknown') }}
                                    </span>
                                @else
                                    <span class="text-ink-muted">&mdash;</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Waiting for')">
                                {{-- Icon + text, never colour alone. --}}
                                <x-ui.badge :status="$report->status->value" />

                                @if ($report->status === ProgressReportStatus::Reviewed && $report->reviewedBy)
                                    <span class="mt-1 block text-xs text-ink-muted">
                                        {{ __('reviewed by :name', ['name' => $report->reviewedBy->name]) }}
                                    </span>
                                @endif

                                @if ($report->submitted_late)
                                    <span class="mt-1 block">
                                        <x-ui.badge status="overdue" size="sm" :label="__('Filed late')" />
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    size="sm"
                                    :variant="$isMine ? 'secondary' : 'primary'"
                                    trailing-icon="chevron-right"
                                    :href="$isMine ? $editUrl($report) : $reportUrl($report)"
                                >{{ $this->actionFor($report) }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->reports->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->reports" :label="__('Inbox pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-ink-muted">
        {{ __('You cannot clear a return you filed yourself, and — where the instance requires a separate approver — you cannot approve one you reviewed. Those returns sit with a colleague, not with you.') }}
    </p>
</div>
