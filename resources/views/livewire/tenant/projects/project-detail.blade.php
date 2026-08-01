{{--
    Project record (App\Livewire\Tenant\Projects\ProjectDetail).
    Header + status machine, then tabs. Every panel reads a computed property.
--}}
@php
    $project = $this->project;
    $deliveryDate = $project->revised_end_date ?? $project->expected_end_date;
    $isLate = $deliveryDate && $deliveryDate->isPast() && ! in_array($project->status->value, ['completed', 'certified', 'closed', 'cancelled'], true);

    $tabs = [
        'overview' => ['label' => __('Overview'), 'icon' => 'squares'],
        'contracts' => ['label' => __('Contracts'), 'icon' => 'banknotes', 'count' => $this->contracts->count()],
        'team' => ['label' => __('Team'), 'icon' => 'users', 'count' => $this->assignments->count()],
        'indicators' => ['label' => __('Indicators'), 'icon' => 'chart-bar', 'count' => $this->indicators->count()],
        'documents' => ['label' => __('Documents'), 'icon' => 'document-text'],
    ];
@endphp

<div>
    <x-ui.page-header
        :title="$project->title"
        :back="url('/projects')"
        :back-label="__('All projects')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => url('/projects')],
            ['label' => $project->reference],
        ]"
    >
        <x-slot:actions>
            @can('update', $project)
                <x-ui.button variant="secondary" size="sm" icon="pencil-square" :href="url('/projects/'.$project->ulid.'/edit')">
                    {{ __('Edit details') }}
                </x-ui.button>
            @endcan

            @if (count($this->availableTransitions) > 0)
                <x-ui.dropdown align="right" :label="__('Change project status')">
                    <x-slot:trigger>
                        <x-ui.button size="sm" trailing-icon="chevron-down">{{ __('Change status') }}</x-ui.button>
                    </x-slot:trigger>

                    @foreach ($this->availableTransitions as $transition)
                        <x-ui.dropdown.item
                            :destructive="$transition['status'] === \App\Enums\ProjectStatus::Cancelled"
                            wire:click="startTransition('{{ $transition['status']->value }}')"
                        >
                            {{ __('Move to :status', ['status' => $transition['status']->label()]) }}
                            @if ($transition['needsReason'])
                                <span class="sr-only">{{ __('(a reason is required)') }}</span>
                            @endif
                        </x-ui.dropdown.item>
                    @endforeach
                </x-ui.dropdown>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That action could not be completed')">
            {{ $failure }}
        </x-ui.alert>
    @endif

    {{-- Identity strip --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        <x-ui.badge :status="$project->status->value" />
        <span class="font-mono text-sm text-ink-muted">{{ $project->reference }}</span>
        <span class="text-sm text-ink-muted">·</span>
        <span class="text-sm text-ink-muted">{{ $project->sector?->name }}</span>
        @if ($project->mid_term_flagged_at)
            <x-ui.badge status="under_review" :label="__('Mid-term evaluation due')" icon="exclamation-triangle" />
        @endif
        @if ($isLate)
            <x-ui.badge status="overdue" />
        @endif
    </div>

    {{-- Tabs --}}
    <div class="mb-5 overflow-x-auto border-b border-line">
        <nav class="-mb-px flex min-w-max gap-1" aria-label="{{ __('Project sections') }}">
            @foreach ($tabs as $key => $meta)
                <button
                    type="button"
                    wire:click="selectTab('{{ $key }}')"
                    @if ($tab === $key) aria-current="page" @endif
                    @class([
                        'inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus',
                        'border-brand text-brand-ink' => $tab === $key,
                        'border-transparent text-ink-muted hover:border-line-strong hover:text-ink' => $tab !== $key,
                    ])
                >
                    <x-ui.icon :name="$meta['icon']" class="size-4" />
                    {{ $meta['label'] }}
                    @if (($meta['count'] ?? null) !== null)
                        <span class="rounded-full bg-neutral-soft px-1.5 text-xs text-neutral-ink tabular-nums">{{ $meta['count'] }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ================================================================ --}}
    {{-- Overview                                                          --}}
    {{-- ================================================================ --}}
    @if ($tab === 'overview')
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <x-ui.card :title="__('Delivery')">
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Physical progress') }}</dt>
                            <dd class="mt-1.5">
                                <x-ui.progress
                                    :value="$project->physical_progress"
                                    :label="__('Physical progress')"
                                    :intent="$isLate ? 'warning' : 'auto'"
                                />
                                <p class="mt-1 text-xs text-ink-muted">{{ __('Verified figure — updated when a progress report is approved.') }}</p>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Budget allocation') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $project->budget_allocation?->format() ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Contract value') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $project->contract_value_total?->format() ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Paid to date') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $project->expenditure_to_date->format() }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Financial progress') }}</dt>
                            <dd class="mt-1">
                                <x-ui.progress :value="$project->financial_progress" :label="__('Financial progress')" size="sm" />
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Start date') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $project->start_date?->translatedFormat('j M Y') ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Delivery date') }}</dt>
                            <dd @class(['mt-1 text-sm', 'font-medium text-critical-ink' => $isLate, 'text-ink' => ! $isLate])>
                                {{ $deliveryDate?->translatedFormat('j M Y') ?? '—' }}
                                @if ($project->revised_end_date)
                                    <span class="block text-xs font-normal text-ink-muted">
                                        {{ __('revised from :date', ['date' => $project->expected_end_date?->translatedFormat('j M Y')]) }}
                                    </span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($project->goal || $project->description)
                        <div class="mt-5 space-y-3 border-t border-line pt-4">
                            @if ($project->goal)
                                <div>
                                    <h3 class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Goal') }}</h3>
                                    <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ $project->goal }}</p>
                                </div>
                            @endif
                            @if ($project->description)
                                <div>
                                    <h3 class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Scope') }}</h3>
                                    <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ $project->description }}</p>
                                </div>
                            @endif
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('Sites')" :subtitle="__('Where this project is being delivered')">
                    @forelse ($this->locations as $location)
                        <div class="flex items-start gap-3 border-b border-line py-3 first:pt-0 last:border-0 last:pb-0">
                            <x-ui.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink">
                                    {{ $location->site_name ?? __('Unnamed site') }}
                                    @if ($location->is_primary)
                                        <span class="ml-1 align-middle"><x-ui.badge status="approved" size="sm" :label="__('Primary')" icon="map-pin" /></span>
                                    @endif
                                </p>
                                <p class="text-sm text-ink-muted">
                                    {{ collect([$location->ward?->name, $location->lga?->name])->filter()->implode(', ') ?: __('Location not recorded') }}
                                </p>
                                @if ($location->latitude && $location->longitude)
                                    <p class="font-mono text-xs text-ink-muted">{{ $location->latitude }}, {{ $location->longitude }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state
                            compact
                            icon="map-pin"
                            :title="__('No sites recorded')"
                            :description="__('Add at least one site so inspections and the project map have somewhere to point.')"
                        />
                    @endforelse
                </x-ui.card>
            </div>

            <div class="space-y-4">
                <x-ui.card :title="__('Funding')">
                    @forelse ($this->fundingAllocations as $allocation)
                        <div class="flex items-baseline justify-between gap-3 border-b border-line py-2 first:pt-0 last:border-0 last:pb-0">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-ink">{{ $allocation->fundingSource?->name }}</p>
                                @if ($allocation->is_primary)
                                    <p class="text-xs text-ink-muted">{{ __('Primary source') }}</p>
                                @endif
                            </div>
                            <p class="shrink-0 text-sm text-ink-muted tabular-nums">
                                @if ($allocation->percentage)
                                    {{ rtrim(rtrim(number_format((float) $allocation->percentage, 2), '0'), '.') }}%
                                @endif
                                @if ($allocation->amount)
                                    <span class="block text-xs">{{ $allocation->amount->format() }}</span>
                                @endif
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-ink-muted">{{ __('No funding sources recorded.') }}</p>
                    @endforelse
                </x-ui.card>

                <x-ui.card :title="__('Status history')" :subtitle="__('Append-only record of every transition')">
                    <ol class="relative space-y-4">
                        @foreach ($this->statusEvents as $event)
                            <li class="flex gap-3">
                                <div class="flex flex-col items-center">
                                    <span class="mt-1 size-2.5 shrink-0 rounded-full bg-brand"></span>
                                    @unless ($loop->last)
                                        <span class="mt-1 w-px flex-1 bg-line"></span>
                                    @endunless
                                </div>
                                <div class="min-w-0 flex-1 pb-1">
                                    <p class="text-sm text-ink">
                                        @if ($event->from_status)
                                            {{ __(':from → :to', ['from' => $event->from_status->label(), 'to' => $event->to_status->label()]) }}
                                        @else
                                            {{ __('Project registered') }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ $event->occurred_at?->translatedFormat('j M Y, H:i') }}
                                        @if ($event->actor)
                                            · {{ $event->actor->name }}
                                        @endif
                                    </p>
                                    @if ($event->reason)
                                        <p class="mt-1 rounded-lg bg-surface-sunken px-2 py-1 text-xs text-ink-muted">{{ $event->reason }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            </div>
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- Contracts                                                         --}}
    {{-- ================================================================ --}}
    @if ($tab === 'contracts')
        <x-ui.card flush :title="__('Contracts')" :subtitle="__('Award sums are immutable — corrections are recorded as variations.')">
            <x-slot:actions>
                @can('award', $project)
                    <x-ui.button size="sm" icon="plus" x-on:click="$dispatch('open-modal', 'award-contract')">
                        {{ __('Record an award') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>

            @if ($this->contracts->isEmpty())
                <x-ui.empty-state
                    icon="banknotes"
                    :title="__('No contract recorded yet')"
                    :description="__('Recording the award moves this project from draft to awarded and sets its contract value.')"
                >
                    <x-slot:actions>
                        @can('award', $project)
                            <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'award-contract')">
                                {{ __('Record an award') }}
                            </x-ui.button>
                        @endcan
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.table
                    :caption="__('Contracts awarded on this project')"
                    class="p-4 sm:p-0"
                    :headings="[__('Contract'), __('Contractor'), ['label' => __('Award sum'), 'align' => 'right'], ['label' => __('Revised value'), 'align' => 'right'], __('Status')]"
                >
                    @foreach ($this->contracts as $contract)
                        <x-ui.table.row wire:key="contract-{{ $contract->id }}">
                            <x-ui.table.cell :label="__('Contract')" primary>
                                {{ $contract->contract_number }}
                                <span class="block text-xs font-normal text-ink-muted">
                                    {{ $contract->type->label() }} · {{ $contract->award_date?->translatedFormat('j M Y') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contractor')">
                                {{ $contract->contractor?->name }}
                                @if ($contract->contractor?->is_blacklisted)
                                    <span class="mt-1 block"><x-ui.badge status="rejected" size="sm" :label="__('Blacklisted')" /></span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Award sum')" numeric>{{ $contract->sum->format() }}</x-ui.table.cell>

                            <x-ui.table.cell :label="__('Revised value')" numeric>
                                {{ $contract->revisedValue()->format() }}
                                @if ($contract->variations->isNotEmpty())
                                    <span class="block text-xs font-normal text-ink-muted">
                                        {{ trans_choice('{1}:count variation|[2,*]:count variations', $contract->variations->count(), ['count' => $contract->variations->count()]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$contract->status->value" :label="$contract->status->label()" />
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif

    {{-- ================================================================ --}}
    {{-- Team                                                              --}}
    {{-- ================================================================ --}}
    @if ($tab === 'team')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2" flush :title="__('Project team')" :subtitle="__('Who may report on and inspect this project')">
                @if ($this->assignments->isEmpty())
                    <x-ui.empty-state
                        icon="users"
                        :title="__('Nobody assigned yet')"
                        :description="__('Assign a consultant or field monitor so they can see this project and file reports against it.')"
                    />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($this->assignments as $assignment)
                            <li class="flex flex-wrap items-center gap-3 p-4" wire:key="assignment-{{ $assignment->id }}">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">
                                    {{ \Illuminate\Support\Str::of($assignment->user?->name ?? '?')->explode(' ')->take(2)->map(fn ($p) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($p, 0, 1)))->implode('') }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-ink">{{ $assignment->user?->name }}</p>
                                    <p class="truncate text-xs text-ink-muted">{{ $assignment->user?->email }}</p>
                                </div>
                                <x-ui.badge status="approved" size="sm" :label="$assignment->role->label()" icon="user-circle" />
                                @can('assign', $project)
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        icon-only
                                        wire:click="unassignMember({{ $assignment->id }})"
                                        wire:confirm="{{ __('Remove :name from this project?', ['name' => $assignment->user?->name]) }}"
                                    >{{ __('Remove :name', ['name' => $assignment->user?->name]) }}</x-ui.button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @can('assign', $project)
                <x-ui.card :title="__('Add a team member')">
                    <div class="space-y-4">
                        <x-ui.form.group name="assigneeId" :label="__('Workspace member')" required>
                            <x-ui.form.select
                                name="assigneeId"
                                :placeholder="__('Choose a member')"
                                :options="$this->assignableUsers->mapWithKeys(fn ($u) => [$u->id => $u->name])->all()"
                                wire:model="assigneeId"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="assigneeRole"
                            :label="__('Project role')"
                            :hint="__('Consultants and field monitors only see projects they are assigned to.')"
                            required
                        >
                            <x-ui.form.select
                                name="assigneeRole"
                                :options="collect(\App\Enums\ProjectRole::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                                has-hint
                                wire:model="assigneeRole"
                            />
                        </x-ui.form.group>

                        <x-ui.button icon="plus" wire:click="assignMember" loading="assignMember" class="w-full">
                            {{ __('Add to team') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endcan
        </div>
    @endif

    {{-- ================================================================ --}}
    {{-- Indicators                                                        --}}
    {{-- ================================================================ --}}
    @if ($tab === 'indicators')
        <x-ui.card flush :title="__('Indicators')" :subtitle="__('What this project is measured on')">
            @if ($this->indicators->isEmpty())
                <x-ui.empty-state
                    icon="chart-bar"
                    :title="__('No indicators defined')"
                    :description="__('Indicators need a baseline value, date and source before they can be activated — an indicator without a baseline cannot show progress.')"
                />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->indicators as $indicator)
                        <li class="flex flex-wrap items-start gap-3 p-4" wire:key="indicator-{{ $indicator->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink">{{ $indicator->name }}</p>
                                <p class="mt-0.5 text-xs text-ink-muted">
                                    {{ $indicator->unit?->label() }} · {{ $indicator->measurement_frequency?->label() }}
                                    @if ($indicator->baseline_value !== null)
                                        · {{ __('baseline :value', ['value' => $indicator->baseline_value]) }}
                                    @endif
                                </p>
                            </div>
                            <x-ui.badge
                                :status="$indicator->is_active ? 'approved' : 'draft'"
                                :label="$indicator->is_active ? __('Active') : __('Not activated')"
                            />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    {{-- ================================================================ --}}
    {{-- Documents (placeholder — medialibrary wiring is a follow-up)       --}}
    {{-- ================================================================ --}}
    @if ($tab === 'documents')
        <x-ui.card flush :title="__('Documents')" :subtitle="__('Award letters, drawings, certificates and site photographs')">
            <x-ui.empty-state
                icon="document-text"
                :title="__('Document upload is not available yet')"
                :description="__('Evidence uploads arrive with the progress reporting module — photographs keep their GPS and timestamp data, and documents are served through permission-checked links, never public URLs.')"
            >
                <x-slot:actions>
                    <x-ui.button variant="secondary" icon="arrow-left" wire:click="selectTab('overview')">
                        {{ __('Back to overview') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @endif

    {{-- ================================================================ --}}
    {{-- Modals                                                            --}}
    {{-- ================================================================ --}}
    @php
        $pending = $pendingStatus ? \App\Enums\ProjectStatus::tryFrom($pendingStatus) : null;
        $needsReason = $pending && in_array($pending, [\App\Enums\ProjectStatus::Suspended, \App\Enums\ProjectStatus::Cancelled], true);
    @endphp

    <x-ui.modal
        name="confirm-transition"
        :title="$pending ? __('Move project to :status?', ['status' => $pending->label()]) : __('Change project status')"
        :description="__('The change is recorded against your name in the project’s status history.')"
        max-width="md"
    >
        @if ($needsReason)
            <x-ui.form.group
                name="transitionReason"
                :label="__('Reason')"
                :hint="__('Required. Shown to everyone working on this project and kept permanently.')"
                required
            >
                <x-ui.form.textarea
                    name="transitionReason"
                    rows="3"
                    maxlength="1000"
                    has-hint
                    :placeholder="__('e.g. Works suspended pending resolution of the right-of-way dispute at Km 5.')"
                    wire:model="transitionReason"
                />
            </x-ui.form.group>
        @else
            <p class="text-sm text-ink-muted">
                {{ __('You can add a note explaining this change.') }}
            </p>
            <x-ui.form.group name="transitionReason" :label="__('Note')" optional class="mt-3">
                <x-ui.form.textarea name="transitionReason" rows="2" maxlength="1000" wire:model="transitionReason" />
            </x-ui.form.group>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'confirm-transition')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button
                :variant="$pending === \App\Enums\ProjectStatus::Cancelled ? 'destructive' : 'primary'"
                wire:click="confirmTransition"
                loading="confirmTransition"
                icon="check-circle"
            >{{ $pending ? __('Move to :status', ['status' => $pending->label()]) : __('Confirm') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @can('award', $project)
        <x-ui.modal
            name="award-contract"
            :title="__('Record a contract award')"
            :description="__('The award sum cannot be edited afterwards — later changes are recorded as variations.')"
            max-width="lg"
        >
            <div class="space-y-4">
                <x-ui.form.group name="contractorId" :label="__('Contractor')" :hint="__('Blacklisted firms are not listed.')" required>
                    <x-ui.form.select
                        name="contractorId"
                        :placeholder="__('Choose a contractor')"
                        :options="$this->contractors->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->rc_number ? ' — '.$c->rc_number : '')])->all()"
                        has-hint
                        wire:model="contractorId"
                    />
                </x-ui.form.group>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group name="contractNumber" :label="__('Contract number')" required>
                        <x-ui.form.input name="contractNumber" wire:model="contractNumber" />
                    </x-ui.form.group>

                    <x-ui.form.group name="contractType" :label="__('Contract type')" required>
                        <x-ui.form.select
                            name="contractType"
                            :options="collect(\App\Enums\ContractType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                            wire:model="contractType"
                        />
                    </x-ui.form.group>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group name="contractSum" :label="__('Award sum')" required>
                        <x-ui.form.input name="contractSum" type="number" step="0.01" min="0" prefix="₦" wire:model="contractSum" />
                    </x-ui.form.group>

                    <x-ui.form.group name="awardDate" :label="__('Award date')" required>
                        <x-ui.form.input name="awardDate" type="date" wire:model="awardDate" />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group name="expectedCompletionDate" :label="__('Expected completion date')" optional>
                    <x-ui.form.input name="expectedCompletionDate" type="date" wire:model="expectedCompletionDate" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="scopeOfWorks"
                    :label="__('Scope of works')"
                    :hint="__('Quoted verbatim in the commencement notice, so write it as it appears in the contract.')"
                    required
                >
                    <x-ui.form.textarea name="scopeOfWorks" rows="4" maxlength="10000" has-hint wire:model="scopeOfWorks" />
                </x-ui.form.group>
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'award-contract')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="awardContract" loading="awardContract" icon="check-circle">
                    {{ __('Record award') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</div>
