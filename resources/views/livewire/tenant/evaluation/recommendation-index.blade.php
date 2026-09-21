{{--
    Follow-up register (App\Livewire\Tenant\Evaluation\RecommendationIndex).

    filter bar → stat summary → table (cards under sm:) → pagination, with the
    follow-up move done inline: a register that takes four clicks to update is
    a register that stops being true.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    // Whether the follow-up affordances exist on this screen at all. The
    // per-row @can below is the precise check; this one keeps the modal, and
    // its wire targets, out of the DOM entirely for the roles that can never
    // open it.
    $canManage = auth()->user()?->can('recommendations.manage') ?? false;
    $target = $this->targetStatus();
@endphp

<div>
    <x-ui.page-header
        :title="__('Follow-up register')"
        :description="__('What evaluations, returns and inspections told this entity to do — who owns it, what it costs, when it is due, and whether it happened. An outstanding recommendation is chased once its date passes.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="clipboard-check"
                :href="route('tenant.evaluations.index', $workspace)"
            >{{ __('Evaluations') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export') }}</x-ui.button>
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
    {{-- Summary row — the state of the register, deliberately NOT filtered --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Still outstanding')"
            :value="number_format($this->stats['outstanding'])"
            icon="inbox"
            :hint="__('open, accepted or being implemented')"
        />
        <x-ui.stat
            :label="__('Past their date')"
            :value="number_format($this->stats['overdue'])"
            icon="exclamation-triangle"
            :intent="$this->stats['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$this->stats['overdue'] > 0 ? __('the addressee has been notified') : __('nothing overdue')"
        />
        <x-ui.stat
            :label="__('Implemented')"
            :value="number_format($this->stats['implemented'])"
            icon="check-circle"
            :hint="__('with evidence on the record')"
        />
        <x-ui.stat
            :label="__('Closed without action')"
            :value="number_format($this->stats['closed'])"
            icon="arrows-right-left"
            :hint="__('declined or superseded, each with a reason')"
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
                        :placeholder="__('Recommendation or addressee…')"
                        wire:model.live.debounce.300ms="search"
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

                <x-ui.form.group name="priority" :label="__('Priority')">
                    <x-ui.form.select
                        name="priority"
                        :placeholder="__('Any priority')"
                        :options="$this->priorityOptions"
                        wire:model.live="priority"
                    />
                </x-ui.form.group>

                <div class="flex items-end pb-1">
                    <x-ui.form.checkbox
                        name="overdue"
                        :label="__('Overdue only')"
                        :description="__('Still owed and past its due date.')"
                        wire:model.live="overdue"
                    />
                </div>
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
    {{-- The register                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,priority,overdue">
            @if ($this->recommendations->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No recommendations match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="clipboard-check"
                        :title="__('Nothing on the register yet')"
                        :description="__('Recommendations arrive here from evaluations, progress returns and inspections. Each one is owned by someone, dated, and tracked until it is implemented or closed with a reason.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="clipboard-check" :href="route('tenant.evaluations.index', $workspace)">
                                {{ __('Go to evaluations') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Recommendations addressed to this entity, with their owner, deadline and implementation status')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Recommendation'),
                        __('Addressed to'),
                        __('Due'),
                        __('Priority'),
                        __('Status'),
                        '',
                    ]"
                >
                    @foreach ($this->recommendations as $recommendation)
                        @php
                            $isOverdue = $recommendation->isOverdue();
                            $daysToDue = $recommendation->daysToDue();
                        @endphp

                        <x-ui.table.row wire:key="recommendation-{{ $recommendation->ulid }}">
                            <x-ui.table.cell :label="__('Recommendation')" primary>
                                {{ $recommendation->title }}

                                <span class="mt-0.5 block max-w-prose text-xs font-normal text-ink-muted">
                                    {{ Str::limit($recommendation->body, 140) }}
                                </span>

                                <span class="mt-0.5 block text-xs font-normal text-ink-subtle">
                                    {{ __('From a :source', ['source' => mb_strtolower($recommendation->sourceLabel())]) }}
                                    @if ($recommendation->project)
                                        &middot;
                                        <a
                                            href="{{ route('tenant.projects.show', [...$workspace, 'project' => $recommendation->project]) }}"
                                            class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        >{{ $recommendation->project->title }}</a>
                                    @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Addressed to')">
                                <span class="text-ink-muted">{{ $recommendation->addresseeLabel() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Due')">
                                @if ($recommendation->due_on)
                                    <span @class(['font-medium text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                                        {{ $recommendation->due_on->translatedFormat('j M Y') }}
                                    </span>

                                    {{-- The countdown, in words. Same definition the
                                         nightly sweep uses, so the screen and the
                                         email can never disagree. --}}
                                    <span @class([
                                        'mt-0.5 block text-xs',
                                        'text-critical-ink' => $isOverdue,
                                        'text-warning-ink' => ! $isOverdue && $recommendation->isOutstanding() && $daysToDue !== null && $daysToDue <= 14,
                                        'text-ink-muted' => ! $isOverdue && ($daysToDue === null || $daysToDue > 14 || ! $recommendation->isOutstanding()),
                                    ])>
                                        @if (! $recommendation->isOutstanding())
                                            &mdash;
                                        @elseif ($daysToDue < 0)
                                            {{ trans_choice('{1} :count day late|[2,*] :count days late', abs($daysToDue), ['count' => abs($daysToDue)]) }}
                                        @elseif ($daysToDue === 0)
                                            {{ __('due today') }}
                                        @else
                                            {{ trans_choice('{1} in :count day|[2,*] in :count days', $daysToDue, ['count' => $daysToDue]) }}
                                        @endif
                                    </span>
                                @else
                                    <span class="text-ink-subtle">{{ __('No date set') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Priority')">
                                <x-ui.badge
                                    :status="$recommendation->priority->badge()"
                                    :label="$recommendation->priority->label()"
                                    :icon="$recommendation->priority->icon()"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge
                                    :status="$recommendation->status->badge()"
                                    :label="$recommendation->status->label()"
                                    :icon="$recommendation->status->icon()"
                                />

                                @if ($isOverdue)
                                    <span class="mt-1 block">
                                        <x-ui.badge status="overdue" size="sm" :label="__('Overdue')" />
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @can('transition', $recommendation)
                                        @foreach ($recommendation->status->allowedTransitions() as $next)
                                            <x-ui.button
                                                variant="ghost"
                                                size="sm"
                                                :icon="$next->icon()"
                                                wire:click="startMove({{ $recommendation->id }}, '{{ $next->value }}')"
                                                loading="startMove({{ $recommendation->id }}, '{{ $next->value }}')"
                                            >{{ $next->label() }}</x-ui.button>
                                        @endforeach
                                    @endcan
                                </div>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->recommendations->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->recommendations" :label="__('Follow-up register pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Record a follow-up                                                --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canManage)
        <x-ui.modal
            name="move-recommendation"
            :title="$target ? __('Record this as :status?', ['status' => mb_strtolower($target->label())]) : __('Record a follow-up')"
            :description="__('The move is attributed to you and kept on the permanent record of this recommendation.')"
            max-width="md"
        >
            @if ($target?->requiresReason())
                <x-ui.form.group
                    name="moveReason"
                    :label="__('Reason')"
                    :hint="__('Required. Read by anyone auditing what this entity did with its evaluations.')"
                    required
                >
                    <x-ui.form.textarea
                        name="moveReason"
                        rows="3"
                        maxlength="2000"
                        has-hint
                        :placeholder="__('e.g. No budget line exists for this within the current appropriation.')"
                        wire:model="moveReason"
                    />
                </x-ui.form.group>
            @endif

            @if ($target === \App\Enums\RecommendationStatus::Implemented)
                <x-ui.form.group
                    name="moveEvidence"
                    :label="__('Evidence of implementation')"
                    :hint="__('Required. What actually happened, and where it can be verified.')"
                    required
                >
                    <x-ui.form.textarea
                        name="moveEvidence"
                        rows="3"
                        maxlength="5000"
                        has-hint
                        :placeholder="__('e.g. Revised works programme issued 14 May; contractor instruction filed in the project vault.')"
                        wire:model="moveEvidence"
                    />
                </x-ui.form.group>
            @endif

            @if ($target === \App\Enums\RecommendationStatus::Superseded)
                <x-ui.form.group
                    name="supersededByUlid"
                    :label="__('Replaced by')"
                    :hint="__('The recommendation that takes this one’s place.')"
                    required
                >
                    <x-ui.form.select
                        name="supersededByUlid"
                        :placeholder="__('Choose a recommendation…')"
                        :options="$this->replacementOptions"
                        has-hint
                        wire:model="supersededByUlid"
                    />
                </x-ui.form.group>
            @endif

            @if (! $target?->requiresReason() && $target !== \App\Enums\RecommendationStatus::Implemented)
                <p class="text-sm text-ink-muted">
                    {{ __('Nothing further is needed — confirming records the change against your name.') }}
                </p>
            @endif

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelMove">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button
                    wire:click="confirmMove"
                    loading="confirmMove"
                    :icon="$target?->icon() ?? 'check'"
                >{{ $target ? $target->label() : __('Confirm') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
