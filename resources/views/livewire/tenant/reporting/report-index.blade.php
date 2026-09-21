{{--
    Reporting desk (App\Livewire\Tenant\Reporting\ReportIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination —
    with a two-way switch at the top, because "what do we owe" and "what did we
    file" are the two questions an M&E officer asks in the same minute.
--}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $reportUrl = fn ($report) => route('tenant.reports.show', [...$workspace, 'report' => $report]);
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);
    $createUrl = route('tenant.reports.create', $workspace);
    $fileUrl = fn ($obligation) => route('tenant.reports.create', [
        ...$workspace,
        'project' => $obligation->project->ulid,
        'period' => $obligation->reportingPeriod->id,
    ]);

    $canCreate = auth()->user()?->can('create', \App\Models\ProgressReport::class) ?? false;
    // Whether the waiver affordance exists on this screen at all. The per-row
    // check below is the precise one (it matches the obligation's workspace);
    // this one keeps the modal, and its wire targets, out of the DOM entirely
    // for the roles that can never open it.
    $canWaive = auth()->user()?->can('reports.waive') ?? false;
    $showingObligations = $this->showingObligations();
@endphp

<div>
    <x-ui.page-header
        :title="__('Progress reports')"
        :description="__('What this entity owes against the state reporting calendar, and everything filed so far. Deadlines are statutory — a late return is recorded as late.')"
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
                icon="calendar-days"
                :href="route('tenant.reports.calendar', $workspace)"
            >{{ __('Calendar') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ $showingObligations ? __('Export obligations') : __('Export returns') }}</x-ui.button>

            @if ($canCreate)
                <x-ui.button size="sm" icon="plus" :href="$createUrl">
                    {{ __('Start a report') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — the state of the desk, deliberately NOT filtered    --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Returns outstanding')"
            :value="number_format($this->stats['outstanding'])"
            icon="document-text"
            :hint="__('across all open windows')"
        />
        <x-ui.stat
            :label="__('Due within 7 days')"
            :value="number_format($this->stats['due_soon'])"
            icon="clock"
            :intent="$this->stats['due_soon'] > 0 ? 'warning' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Past their deadline')"
            :value="number_format($this->stats['overdue'])"
            icon="exclamation-triangle"
            :intent="$this->stats['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$this->stats['overdue'] > 0 ? __('recorded as late on the state board') : __('nothing overdue')"
        />
        <x-ui.stat
            :label="__('Waiting on you')"
            :value="number_format($this->stats['awaiting_action'])"
            icon="inbox"
            :intent="$this->stats['awaiting_action'] > 0 ? 'warning' : 'neutral'"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- List switch                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Choose a list') }}">
        <x-ui.button
            size="sm"
            :variant="$showingObligations ? 'primary' : 'secondary'"
            icon="calendar-days"
            wire:click="$set('view', 'obligations')"
            :aria-pressed="$showingObligations ? 'true' : 'false'"
        >{{ __('Outstanding & upcoming') }}</x-ui.button>

        <x-ui.button
            size="sm"
            :variant="$showingObligations ? 'secondary' : 'primary'"
            icon="document-text"
            wire:click="$set('view', 'reports')"
            :aria-pressed="$showingObligations ? 'false' : 'true'"
        >{{ __('Filed returns') }}</x-ui.button>
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

                <x-ui.form.group name="periodId" :label="__('Reporting window')">
                    <x-ui.form.select
                        name="periodId"
                        :placeholder="__('Any window')"
                        :options="$this->periods->pluck('label', 'id')->all()"
                        wire:model.live="periodId"
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
    {{-- Obligations                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($showingObligations)
        <x-ui.card flush>
            <div wire:loading.delay.long.flex class="hidden p-4">
                <x-ui.skeleton variant="table" :rows="6" />
            </div>

            <div wire:loading.delay.long.remove wire:target="search,periodId,status,projectUlid,view">
                @if ($this->obligations->isEmpty())
                    @if ($this->hasFilters())
                        <x-ui.empty-state
                            variant="filtered"
                            :description="__('No reporting obligations match the filters you have set.')"
                        >
                            <x-slot:actions>
                                <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                    {{ __('Clear filters') }}
                                </x-ui.button>
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state
                            icon="calendar-days"
                            :title="__('Nothing is owed right now')"
                            :description="__('Reporting obligations are generated nightly from the state calendar for every project under execution. They will appear here as each window opens.')"
                        />
                    @endif
                @else
                    <x-ui.table
                        :caption="__('Reporting obligations for this workspace, with deadline and current status')"
                        class="p-4 sm:p-0"
                        :headings="[
                            __('Project'),
                            __('Window'),
                            __('Deadline'),
                            __('Status'),
                            '',
                        ]"
                    >
                        @foreach ($this->obligations as $obligation)
                            @php
                                $daysToDue = $obligation->daysToDue();
                                $isOverdue = $obligation->isOverdue();
                                $filed = $obligation->progress_report_id !== null;
                            @endphp

                            <x-ui.table.row wire:key="obligation-{{ $obligation->id }}">
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

                                <x-ui.table.cell :label="__('Window')">
                                    <span class="text-ink-muted">{{ $obligation->reportingPeriod->label }}</span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Deadline')">
                                    <span @class(['font-medium text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                                        {{ $obligation->due_at->translatedFormat('j M Y') }}
                                    </span>

                                    {{-- The countdown, in words. Same definition the
                                         reminder ladder uses, so the screen and the
                                         email can never disagree. --}}
                                    <span @class([
                                        'mt-0.5 block text-xs',
                                        'text-critical-ink' => $isOverdue,
                                        'text-warning-ink' => ! $isOverdue && $obligation->isOutstanding() && $daysToDue <= 7,
                                        'text-ink-muted' => ! $isOverdue && ($daysToDue > 7 || ! $obligation->isOutstanding()),
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
                                    {{-- Overdue is the more urgent truth about a
                                         pending row, so it wins the badge. --}}
                                    <x-ui.badge :status="$isOverdue ? 'overdue' : $obligation->status->value" />

                                    @if ($obligation->submitted_late)
                                        <span class="mt-1 block">
                                            <x-ui.badge status="overdue" size="sm" :label="__('Filed late')" />
                                        </span>
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell align="right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @if ($filed)
                                            <x-ui.button
                                                variant="ghost"
                                                size="sm"
                                                trailing-icon="chevron-right"
                                                :href="$reportUrl($obligation->progressReport)"
                                            >{{ __('Open return') }}</x-ui.button>
                                        @elseif ($canCreate && $obligation->project)
                                            <x-ui.button
                                                variant="secondary"
                                                size="sm"
                                                icon="pencil-square"
                                                :href="$fileUrl($obligation)"
                                            >{{ __('File it') }}</x-ui.button>
                                        @endif

                                        {{-- A window nobody can report against — a site
                                             under water, a suspended contract — is excused
                                             on the record rather than fabricated or left as
                                             a permanent black mark. Only `reports.waive`
                                             holders, and only while it is still outstanding. --}}
                                        @can('waive', $obligation)
                                            @if ($obligation->isOutstanding())
                                                <x-ui.button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="pause-circle"
                                                    wire:click="startWaive({{ $obligation->id }})"
                                                    loading="startWaive({{ $obligation->id }})"
                                                >{{ __('Waive') }}</x-ui.button>
                                            @endif
                                        @endcan
                                    </div>
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>

            @if ($this->obligations->isNotEmpty())
                <x-slot:footer>
                    <x-ui.pagination :paginator="$this->obligations" :label="__('Obligation list pages')" />
                </x-slot:footer>
            @endif
        </x-ui.card>
    @else
        {{-- ------------------------------------------------------------ --}}
        {{-- Filed returns                                                 --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card flush>
            <div wire:loading.delay.long.flex class="hidden p-4">
                <x-ui.skeleton variant="table" :rows="6" />
            </div>

            <div wire:loading.delay.long.remove wire:target="search,periodId,status,projectUlid,view">
                @if ($this->reports->isEmpty())
                    @if ($this->hasFilters())
                        <x-ui.empty-state
                            variant="filtered"
                            :description="__('No returns match the filters you have set.')"
                        >
                            <x-slot:actions>
                                <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                    {{ __('Clear filters') }}
                                </x-ui.button>
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state
                            icon="document-text"
                            :title="__('No returns filed yet')"
                            :description="__('Progress returns filed against the state calendar appear here, with who reviewed and approved each one.')"
                        >
                            <x-slot:actions>
                                @if ($canCreate)
                                    <x-ui.button icon="plus" :href="$createUrl">
                                        {{ __('Start a report') }}
                                    </x-ui.button>
                                @endif
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @endif
                @else
                    <x-ui.table
                        :caption="__('Progress returns filed by this workspace, with their approval status')"
                        class="p-4 sm:p-0"
                        :headings="[
                            __('Project'),
                            __('Window'),
                            ['label' => __('Progress claimed'), 'align' => 'right'],
                            ['label' => __('Period spend'), 'align' => 'right'],
                            __('Status'),
                            '',
                        ]"
                    >
                        @foreach ($this->reports as $report)
                            <x-ui.table.row wire:key="report-{{ $report->ulid }}">
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
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Progress claimed')" numeric>
                                    {{ $report->physical_progress_claimed }}%
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Period spend')" numeric>
                                    {{ $report->period_expenditure->format() }}
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Status')">
                                    <x-ui.badge :status="$report->status->value" />

                                    @if ($report->submitted_late)
                                        <span class="mt-1 block">
                                            <x-ui.badge status="overdue" size="sm" :label="__('Filed late')" />
                                        </span>
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell align="right">
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        trailing-icon="chevron-right"
                                        :href="$reportUrl($report)"
                                    >{{ __('Open') }}</x-ui.button>
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>

            @if ($this->reports->isNotEmpty())
                <x-slot:footer>
                    <x-ui.pagination :paginator="$this->reports" :label="__('Return list pages')" />
                </x-slot:footer>
            @endif
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Waive an obligation                                               --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canWaive)
        <x-ui.modal
            name="waive-obligation"
            :title="__('Waive this reporting obligation?')"
            :description="__('The window stops being chased and is excluded from this entity’s compliance score. The waiver is permanent, attributed to you, and visible to state oversight.')"
            max-width="md"
        >
            <x-ui.form.group
                name="waiverReason"
                :label="__('Reason')"
                :hint="__('Required. Kept on the compliance record and read by anyone auditing this entity’s returns.')"
                required
            >
                <x-ui.form.textarea
                    name="waiverReason"
                    rows="3"
                    maxlength="1000"
                    has-hint
                    :placeholder="__('e.g. Site inaccessible for the whole period following the flooding of the access road.')"
                    wire:model="waiverReason"
                />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelWaive">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button
                    wire:click="confirmWaive"
                    loading="confirmWaive"
                    icon="pause-circle"
                >{{ __('Waive obligation') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
