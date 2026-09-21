{{--
    Read-only project record on the state surface
    (App\Livewire\Oversight\Projects\ProjectView).
--}}
@php
    $project = $this->project;
    $deliveryDate = $project->revised_end_date ?? $project->expected_end_date;
    $isLate = $deliveryDate && $deliveryDate->isPast()
        && ! in_array($project->status->value, ['completed', 'certified', 'closed', 'cancelled'], true);
    $pending = $pendingStatus ? \App\Enums\ProjectStatus::tryFrom($pendingStatus) : null;

    // Named routes, not hand-built paths: route() fails loudly on a missing
    // route or the wrong binding key, where a string 404s silently. The
    // oversight surface carries no domain parameter, so no extra arguments.
    $portfolioUrl = route('oversight.portfolio.index');
    $entityUrl = $project->tenant
        ? route('oversight.portfolio.tenant', ['tenant' => $project->tenant->slug])
        : $portfolioUrl;
@endphp

<div>
    <x-ui.page-header
        :title="$project->title"
        :back="$portfolioUrl"
        :back-label="__('State portfolio')"
        :breadcrumbs="[
            ['label' => __('State portfolio'), 'href' => $portfolioUrl],
            ['label' => $project->tenant?->name ?? __('Entity'), 'href' => $entityUrl],
            ['label' => $project->reference],
        ]"
    >
        <x-slot:actions>
            @if (count($this->availableTransitions) > 0)
                <x-ui.dropdown align="right" :label="__('State interventions')">
                    <x-slot:trigger>
                        <x-ui.button size="sm" variant="secondary" trailing-icon="chevron-down">
                            {{ __('Intervene') }}
                        </x-ui.button>
                    </x-slot:trigger>

                    @foreach ($this->availableTransitions as $transition)
                        <x-ui.dropdown.item
                            :destructive="$transition['status'] === \App\Enums\ProjectStatus::Cancelled"
                            wire:click="startTransition('{{ $transition['status']->value }}')"
                        >
                            {{ __('Move to :status', ['status' => $transition['status']->label()]) }}
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
        <x-ui.alert variant="critical" class="mb-5" :title="__('That action could not be completed')">{{ $failure }}</x-ui.alert>
    @endif

    {{-- Surface honesty: say what this screen can and cannot do. --}}
    <x-ui.alert variant="neutral" icon="eye" class="mb-5">
        {{ __('Read-only view. Scope, contracts and team are maintained by :entity in its own workspace; the state surface can suspend, close or cancel a project, and every such change is recorded against your name.', ['entity' => $project->tenant?->name ?? __('the entity')]) }}
    </x-ui.alert>

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <x-ui.badge :status="$project->status->value" />
        <span class="font-mono text-sm text-ink-muted">{{ $project->reference }}</span>
        <span class="text-sm text-ink-muted">·</span>
        <span class="text-sm text-ink-muted">{{ $project->sector?->name }}</span>
        <span class="text-sm text-ink-muted">·</span>
        <a href="{{ $entityUrl }}" class="rounded text-sm font-medium text-brand-ink hover:underline">
            {{ $project->tenant?->name }}
        </a>
        @if ($isLate)
            <x-ui.badge status="overdue" />
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card :title="__('Delivery')">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Physical progress') }}</dt>
                        <dd class="mt-1.5">
                            <x-ui.progress :value="$project->physical_progress" :label="__('Physical progress')" :intent="$isLate ? 'warning' : 'auto'" />
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
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Delivery date') }}</dt>
                        <dd @class(['mt-1 text-sm', 'font-medium text-critical-ink' => $isLate, 'text-ink' => ! $isLate])>
                            {{ $deliveryDate?->translatedFormat('j M Y') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card flush :title="__('Contracts')">
                @if ($this->contracts->isEmpty())
                    <x-ui.empty-state compact icon="banknotes" :title="__('No contract recorded')" />
                @else
                    <x-ui.table
                        :caption="__('Contracts on this project')"
                        class="p-4 sm:p-0"
                        :headings="[__('Contract'), __('Contractor'), ['label' => __('Award sum'), 'align' => 'right'], ['label' => __('Revised value'), 'align' => 'right']]"
                    >
                        @foreach ($this->contracts as $contract)
                            <x-ui.table.row wire:key="oversight-contract-{{ $contract->id }}">
                                <x-ui.table.cell :label="__('Contract')" primary>
                                    {{ $contract->contract_number }}
                                    <span class="block text-xs font-normal text-ink-muted">{{ $contract->award_date?->translatedFormat('j M Y') }}</span>
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Contractor')">
                                    {{ $contract->contractor?->name }}
                                    @if ($contract->contractor?->is_blacklisted)
                                        <span class="mt-1 block"><x-ui.badge status="rejected" size="sm" :label="__('Blacklisted')" /></span>
                                    @endif
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Award sum')" numeric>{{ $contract->sum->format() }}</x-ui.table.cell>
                                <x-ui.table.cell :label="__('Revised value')" numeric>{{ $contract->revisedValue()->format() }}</x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            {{-- The entity's own files, read-only. Oversight reads the record;
                 it does not add to or remove from another entity's evidence,
                 so both panels are mounted with :readonly. The download links
                 are still signed and still permission-checked — the surface a
                 file is read on never changes who may read it. --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <livewire:shared.document-panel
                    :key="'oversight-project-documents-'.$project->ulid"
                    :model="$project"
                    collection="project_documents"
                    :readonly="true"
                />

                <livewire:shared.document-panel
                    :key="'oversight-project-photos-'.$project->ulid"
                    :model="$project"
                    collection="project_photos"
                    :readonly="true"
                />
            </div>
        </div>

        <div class="space-y-4">
            <x-ui.card :title="__('Sites')">
                @forelse ($this->locations as $location)
                    <div class="border-b border-line py-2 first:pt-0 last:border-0 last:pb-0">
                        <p class="text-sm text-ink">{{ $location->site_name ?? __('Unnamed site') }}</p>
                        <p class="text-xs text-ink-muted">
                            {{ collect([$location->ward?->name, $location->lga?->name])->filter()->implode(', ') ?: __('Location not recorded') }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-ink-muted">{{ __('No sites recorded.') }}</p>
                @endforelse
            </x-ui.card>

            <x-ui.card :title="__('Status history')">
                <ol class="space-y-4">
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
                                    @if ($event->actor) · {{ $event->actor->name }} @endif
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

    <x-ui.modal
        name="confirm-oversight-transition"
        :title="$pending ? __('Move project to :status?', ['status' => $pending->label()]) : __('Change project status')"
        :description="__('This is a state-level intervention on another entity’s project. It is recorded against your name and visible in their workspace.')"
        max-width="md"
    >
        @if ($this->pendingIsEarlyClosure)
            {{-- The one place the §2.1 early-closure override is offered. Say
                 plainly that this is what is happening — a state administrator
                 ending post-completion monitoring before its window closes. --}}
            <x-ui.alert variant="warning" class="mb-4" :title="__('Closing before the post-completion review window ends')">
                {{ __('This project’s post-completion monitoring has not run its course. As a state-level administrator you may close it early; your reason is recorded on the project’s timeline.') }}
            </x-ui.alert>
        @endif

        <x-ui.form.group
            name="transitionReason"
            :label="__('Reason')"
            :hint="$this->pendingNeedsReason
                ? __('Required. Shown to the entity delivering this project.')
                : __('Optional for closure.')"
            :required="$this->pendingNeedsReason"
        >
            <x-ui.form.textarea
                name="transitionReason"
                rows="3"
                maxlength="1000"
                has-hint
                :placeholder="__('e.g. Suspended pending the outcome of the procurement review.')"
                wire:model="transitionReason"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'confirm-oversight-transition')">
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
</div>
