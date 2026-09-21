{{--
    Work-plan builder (App\Livewire\Tenant\Workplans\WorkplanBuilder).

    Header + approval controls → warning banner (the manual's indicator rule)
    → stat row → activity table with inline add/edit/progress → timeline.
--}}
@php
    $plan = $workplan;
    $summary = $this->summary;
    $health = $this->health;
    $frozen = $plan->isFrozen();
    $user = auth()->user();
    $canManage = $user?->can('update', $plan) ?? false;
    $canDecide = $user?->can('approve', $plan) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="$plan->title"
        :description="__(':start to :end · owner: :owner', [
            'start' => $plan->period_start->translatedFormat('j M Y'),
            'end' => $plan->period_end->translatedFormat('j M Y'),
            'owner' => $plan->owner?->name ?? __('unassigned'),
        ])"
        :breadcrumbs="[
            ['label' => __('Work plans'), 'href' => route('tenant.workplans.index')],
            ['label' => $plan->yearLabel()],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge :status="$plan->status->badge()" :label="$plan->status->label()" />

            <x-ui.button variant="secondary" size="sm" icon="chart-bar" :href="route('tenant.workplans.gantt', $plan)">
                {{ __('Timeline') }}
            </x-ui.button>

            @if ($canManage && in_array($plan->status, [\App\Enums\WorkplanStatus::Draft, \App\Enums\WorkplanStatus::Rejected], true))
                <x-ui.button size="sm" icon="paper-airplane" wire:click="submit" loading="submit">
                    {{ __('Submit for approval') }}
                </x-ui.button>
            @endif

            @if ($canDecide && $plan->status === \App\Enums\WorkplanStatus::Submitted)
                <x-ui.button size="sm" icon="check-circle" wire:click="approve" loading="approve">
                    {{ __('Approve') }}
                </x-ui.button>
            @endif

            @if ($canDecide && $plan->status === \App\Enums\WorkplanStatus::Approved && $plan->hasStarted())
                <x-ui.button size="sm" icon="flag" wire:click="activate" loading="activate">
                    {{ __('Mark active') }}
                </x-ui.button>
            @endif

            @if ($canDecide && in_array($plan->status, [\App\Enums\WorkplanStatus::Approved, \App\Enums\WorkplanStatus::Active], true))
                <x-ui.button variant="secondary" size="sm" icon="check" wire:click="close" loading="close">
                    {{ __('Close the year') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    @if (session('error'))
        <x-ui.alert variant="critical" class="mb-4" :title="__('That change was refused')">{{ session('error') }}</x-ui.alert>
    @endif

    {{-- THE MANUAL'S RULE, made visible and countable. A warning, never a
         block: an activity with a fabricated indicator is worse data than an
         activity with none and a flag on it. --}}
    @if ($summary['unlinked'] > 0)
        <x-ui.alert variant="warning" class="mb-4" :title="__('Activities without an output indicator')">
            {{ trans_choice(
                '{1}1 activity in this plan carries no output indicator. The M&E manual requires one per work-plan activity — link it so the activity can be reported on.|[2,*]:count activities in this plan carry no output indicator. The M&E manual requires one per work-plan activity — link them so they can be reported on.',
                $summary['unlinked'],
                ['count' => $summary['unlinked']],
            ) }}
        </x-ui.alert>
    @endif

    @if ($plan->status === \App\Enums\WorkplanStatus::Rejected && $plan->rejection_reason)
        <x-ui.alert variant="warning" class="mb-4" :title="__('Sent back on :date', ['date' => $plan->rejected_at?->translatedFormat('j M Y')])">
            {{ $plan->rejection_reason }}
        </x-ui.alert>
    @endif

    @if ($frozen)
        <x-ui.alert variant="neutral" class="mb-4" :title="__('Activities are frozen')">
            {{ __('This plan has been approved, so what the activities ARE — their scope, schedule and budget — no longer changes. Recording progress and expenditure against them continues as normal.') }}
        </x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Weighted progress')"
            :value="$summary['progress'] === null ? '—' : $summary['progress'].'%'"
            icon="arrow-trending-up"
            :hint="__(':done of :count activities complete', ['done' => $summary['completed'], 'count' => $summary['counted']])"
        />
        <x-ui.stat
            :label="__('Year elapsed')"
            :value="$health['elapsed'].'%'"
            icon="clock"
            :intent="$health['slippage'] !== null && $health['slippage'] < -10 ? 'critical' : 'neutral'"
            :hint="$health['slippage'] === null
                ? __('the plan period has not begun')
                : ($health['slippage'] < 0
                    ? __(':points points behind the calendar', ['points' => abs($health['slippage'])])
                    : __(':points points ahead of the calendar', ['points' => $health['slippage']]))"
        />
        <x-ui.stat
            :label="__('Planned budget')"
            :value="$summary['budget']->format()"
            icon="banknotes"
            :hint="__('across :count budget lines', ['count' => $summary['counted']])"
        />
        <x-ui.stat
            :label="__('Spent to date')"
            :value="$summary['expenditure']->format()"
            icon="arrow-down-tray"
            :hint="$summary['financial_progress'] === null
                ? __('no budget recorded')
                : __(':percent% of the planned budget', ['percent' => $summary['financial_progress']])"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Reject form (approver only, while awaiting decision)              --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canDecide && $plan->status === \App\Enums\WorkplanStatus::Submitted)
        <x-ui.card class="mb-4" :title="__('Send it back instead')">
            <div class="space-y-3">
                <x-ui.form.group
                    name="decisionReason"
                    :label="__('Reason')"
                    required
                    :hint="__('The owner has to know what to fix. At least 10 characters.')"
                >
                    <x-ui.form.textarea name="decisionReason" rows="3" maxlength="2000" has-hint wire:model="decisionReason" />
                </x-ui.form.group>

                <x-ui.button variant="destructive" size="sm" icon="arrow-uturn-left" wire:click="reject" loading="reject">
                    {{ __('Send back for revision') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Activity form                                                     --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($showActivityForm && $canManage && ! $frozen)
        <x-ui.card class="mb-4" :title="$editingUlid ? __('Edit activity') : __('Add an activity')">
            <form wire:submit="saveActivity" class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="title" :label="__('Activity')" required class="sm:col-span-2">
                    <x-ui.form.input name="title" wire:model="title" autocomplete="off" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="indicatorId"
                    :label="__('Output indicator')"
                    :hint="__('The M&E manual requires one per activity. Leaving it empty raises a warning on every screen.')"
                    class="sm:col-span-2"
                >
                    <x-ui.form.select
                        name="indicatorId"
                        :placeholder="__('None yet — this will be flagged')"
                        :options="$this->indicatorOptions"
                        has-hint
                        wire:model="indicatorId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="activityOwnerId" :label="__('Owner')" optional>
                    <x-ui.form.select
                        name="activityOwnerId"
                        :placeholder="__('Unassigned')"
                        :options="$this->memberOptions"
                        wire:model="activityOwnerId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="responsibleUnit" :label="__('Responsible unit')" optional>
                    <x-ui.form.input name="responsibleUnit" wire:model="responsibleUnit" autocomplete="off" />
                </x-ui.form.group>

                <x-ui.form.group name="granularity" :label="__('Schedule granularity')" required>
                    <x-ui.form.select name="granularity" :options="$this->granularityOptions" wire:model="granularity" />
                </x-ui.form.group>

                <x-ui.form.group name="projectId" :label="__('Related project')" optional>
                    <x-ui.form.select
                        name="projectId"
                        :placeholder="__('Not project-specific')"
                        :options="$this->projectOptions"
                        wire:model="projectId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="plannedStart" :label="__('Planned start')" required>
                    <x-ui.form.input name="plannedStart" type="date" wire:model="plannedStart" />
                </x-ui.form.group>

                <x-ui.form.group name="plannedEnd" :label="__('Planned end')" required>
                    <x-ui.form.input name="plannedEnd" type="date" wire:model="plannedEnd" />
                </x-ui.form.group>

                <x-ui.form.group name="budgetLine" :label="__('Budget line')" optional>
                    <x-ui.form.input name="budgetLine" wire:model="budgetLine" autocomplete="off" />
                </x-ui.form.group>

                <x-ui.form.group name="budgetAmount" :label="__('Budget amount')" required>
                    <x-ui.form.input name="budgetAmount" type="text" inputmode="decimal" prefix="₦" wire:model="budgetAmount" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="weight"
                    :label="__('Weight')"
                    required
                    :hint="__('How much this activity counts in the plan roll-up. Leave at 1 unless it genuinely carries more of the year.')"
                >
                    <x-ui.form.input name="weight" type="number" min="1" max="1000" has-hint wire:model="weight" />
                </x-ui.form.group>

                <x-ui.form.group name="dependsOnId" :label="__('Starts after')" optional>
                    <x-ui.form.select
                        name="dependsOnId"
                        :placeholder="__('No dependency')"
                        :options="$this->dependencyOptions"
                        wire:model="dependsOnId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="description" :label="__('Description')" optional class="sm:col-span-2">
                    <x-ui.form.textarea name="description" rows="3" maxlength="2000" wire:model="description" />
                </x-ui.form.group>

                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <x-ui.button type="submit" icon="check" loading="saveActivity">{{ __('Save activity') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="cancelActivityForm" type="button">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Activities                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush :title="__('Activities')" :subtitle="__('Each line delivers one output indicator, to a schedule and a budget.')">
        <x-slot:actions>
            @if ($canManage && ! $frozen)
                <x-ui.button size="sm" icon="plus" wire:click="startAdding">{{ __('Add activity') }}</x-ui.button>
            @endif
        </x-slot:actions>

        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="4" />
        </div>

        <div wire:loading.delay.long.remove wire:target="saveActivity,removeActivity,saveProgress">
            @if ($this->activities->isEmpty())
                <x-ui.empty-state
                    :title="__('No activities yet')"
                    :description="__('An annual work plan is its activities: what will be done, by whom, when, at what cost, and against which output indicator.')"
                >
                    <x-slot:actions>
                        @if ($canManage && ! $frozen)
                            <x-ui.button icon="plus" wire:click="startAdding">{{ __('Add the first activity') }}</x-ui.button>
                        @else
                            <p class="text-sm text-ink-muted">
                                {{ $frozen
                                    ? __('This plan has been approved, so its activities can no longer be changed.')
                                    : __('You do not have permission to edit this plan.') }}
                            </p>
                        @endif
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.table
                    :caption="__('Activities in this work plan, with owner, schedule, budget, output indicator and progress')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('#'),
                        __('Activity'),
                        __('Owner'),
                        __('Schedule'),
                        ['label' => __('Budget'), 'align' => 'right'],
                        __('Progress'),
                        __('Status'),
                        '',
                    ]"
                >
                    @foreach ($this->activities as $activity)
                        <x-ui.table.row wire:key="activity-{{ $activity->ulid }}">
                            <x-ui.table.cell :label="__('#')">
                                <span class="tabular-nums text-ink-muted">{{ $loop->iteration }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Activity')" primary>
                                {{ $activity->title }}

                                @if ($activity->indicator)
                                    <span class="mt-0.5 flex items-center gap-1 text-xs font-normal text-ink-muted">
                                        <x-ui.icon name="chart-bar" class="size-3.5 shrink-0" />
                                        {{ $activity->indicator->name }}
                                    </span>
                                @else
                                    <span class="mt-0.5 flex items-center gap-1 text-xs font-medium text-critical-ink">
                                        <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                        {{ __('No output indicator') }}
                                    </span>
                                @endif

                                @if ($activity->dependsOn)
                                    <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {{ __('Starts after: :title', ['title' => $activity->dependsOn->title]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Owner')">
                                <span class="text-ink-muted">{{ $activity->owner?->name ?? '—' }}</span>
                                @if ($activity->responsible_unit)
                                    <span class="block text-xs text-ink-subtle">{{ $activity->responsible_unit }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Schedule')">
                                <span class="text-ink-muted">
                                    {{ $activity->planned_start->translatedFormat('j M') }} – {{ $activity->planned_end->translatedFormat('j M Y') }}
                                </span>
                                @if ($activity->isOverdue())
                                    <span class="mt-1 block sm:inline-block">
                                        <x-ui.badge status="overdue" size="sm" />
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Budget')" numeric>
                                {{ $activity->budget_amount->format() }}
                                <span class="block text-xs text-ink-subtle">
                                    {{ __('spent :amount', ['amount' => $activity->expenditure_to_date->format()]) }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Progress')">
                                <x-ui.progress
                                    :value="$activity->progress_percent"
                                    :label="__('Progress for :activity', ['activity' => $activity->title])"
                                    size="sm"
                                    class="sm:w-28"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge
                                    :status="$activity->status->badge()"
                                    :label="$activity->status->label()"
                                    :icon="$activity->status->icon()"
                                    size="sm"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.dropdown align="right" :label="__('Actions for :activity', ['activity' => $activity->title])">
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" icon-only>
                                            {{ __('Actions for :activity', ['activity' => $activity->title]) }}
                                        </x-ui.button>
                                    </x-slot:trigger>

                                    @can('recordProgress', $activity)
                                        <x-ui.dropdown.item icon="arrow-trending-up" wire:click="startRecording('{{ $activity->ulid }}')">
                                            {{ __('Record progress') }}
                                        </x-ui.dropdown.item>
                                    @endcan

                                    @if ($canManage && ! $frozen)
                                        <x-ui.dropdown.item icon="pencil-square" wire:click="editActivity('{{ $activity->ulid }}')">
                                            {{ __('Edit activity') }}
                                        </x-ui.dropdown.item>
                                        <x-ui.dropdown.item icon="trash" destructive wire:click="removeActivity('{{ $activity->ulid }}')">
                                            {{ __('Remove activity') }}
                                        </x-ui.dropdown.item>
                                    @endif
                                </x-ui.dropdown>
                            </x-ui.table.cell>
                        </x-ui.table.row>

                        @if ($progressUlid === $activity->ulid)
                            <x-ui.table.row wire:key="progress-{{ $activity->ulid }}">
                                <x-ui.table.cell :label="__('Record progress')" stacked>
                                    <form wire:submit="saveProgress" class="grid gap-3 py-2 sm:grid-cols-3">
                                        <x-ui.form.group name="progressPercent" :label="__('Progress (%)')" required>
                                            <x-ui.form.input name="progressPercent" type="number" min="0" max="100" suffix="%" wire:model="progressPercent" />
                                        </x-ui.form.group>

                                        <x-ui.form.group name="progressExpenditure" :label="__('Expenditure to date')" required>
                                            <x-ui.form.input name="progressExpenditure" type="text" inputmode="decimal" prefix="₦" wire:model="progressExpenditure" />
                                        </x-ui.form.group>

                                        <div class="flex items-end gap-2">
                                            <x-ui.button type="submit" size="sm" icon="check" loading="saveProgress">{{ __('Save') }}</x-ui.button>
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelProgress">{{ __('Cancel') }}</x-ui.button>
                                        </div>
                                    </form>
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endif
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Approval timeline (the append-only ledger)                        --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($this->timeline->isNotEmpty())
        <x-ui.card class="mt-4" :title="__('Approval history')" :subtitle="__('Append-only: a step is corrected by recording another one.')">
            <ol class="space-y-3">
                @foreach ($this->timeline as $event)
                    <li class="flex items-start gap-3">
                        <x-ui.icon
                            :name="$event->isCreation() ? 'plus' : 'arrow-right'"
                            class="mt-0.5 size-4 shrink-0 text-ink-subtle"
                        />
                        <div class="min-w-0">
                            <p class="text-sm text-ink">
                                @if ($event->isCreation())
                                    {{ __('Plan opened by :actor', ['actor' => $event->actor?->name ?? __('a removed user')]) }}
                                @else
                                    {{ __(':from → :to by :actor', [
                                        'from' => $event->from_status?->label(),
                                        'to' => $event->to_status->label(),
                                        'actor' => $event->actor?->name ?? __('a removed user'),
                                    ]) }}
                                @endif
                            </p>
                            <p class="text-xs text-ink-muted">{{ $event->occurred_at->translatedFormat('j M Y, H:i') }}</p>
                            @if ($event->reason)
                                <p class="mt-1 text-sm text-ink-muted">{{ $event->reason }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    @endif
</div>
