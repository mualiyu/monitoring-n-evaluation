{{--
    One challenge (App\Livewire\Tenant\Issues\IssueDetail).

    The obstruction on the left, the decision and the plan on the right, the
    append-only history underneath. The timeline reads `issue_events`, not the
    issue's own stamps: an issue resolved, reopened and resolved again has two
    resolve events and one `resolved_at`, and the second resolution is exactly
    the one somebody will ask about.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $issue = $this->issue;
    $pending = $this->pendingStatus ? \App\Enums\IssueStatus::from($this->pendingStatus) : null;
    $canUpdate = $this->canUpdate();
@endphp

<div>
    <x-ui.page-header
        :title="$issue->title"
        :back="route('tenant.issues.index', $workspace)"
        :back-label="__('Back to the register')"
        :breadcrumbs="[
            ['label' => __('Challenges register'), 'href' => route('tenant.issues.index', $workspace)],
            ['label' => $issue->project->reference, 'href' => route('tenant.projects.show', [...$workspace, 'project' => $issue->project])],
            ['label' => __('Issue')],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge
                :status="$issue->severity->badgeStatus()"
                :label="$issue->severity->label()"
                :icon="$issue->severity->icon()"
            />
            <x-ui.badge
                :status="$issue->status->badgeStatus()"
                :label="$issue->status->label()"
                :icon="$issue->status->icon()"
            />
            @if ($issue->isOverdue())
                <x-ui.badge status="overdue" :label="__('Past its deadline')" />
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

    @if ($issue->escalated_at)
        <x-ui.alert variant="warning" :title="__('Escalated')" class="mb-4">
            {{ __('This issue passed the allowance for :severity challenges on :date and was escalated to the entity\'s administrators.', [
                'severity' => $issue->severity->label(),
                'date' => $issue->escalated_at->translatedFormat('j M Y'),
            ]) }}
        </x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ------------------------------------------------------------ --}}
        {{-- The obstruction                                               --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card :title="__('What is happening')">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs font-medium text-ink-muted">{{ __('Description') }}</dt>
                        <dd class="mt-1 text-sm whitespace-pre-line text-ink">{{ $issue->description }}</dd>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium text-ink-muted">{{ __('Project') }}</dt>
                            <dd class="mt-1 text-sm">
                                <a
                                    href="{{ route('tenant.projects.show', [...$workspace, 'project' => $issue->project]) }}"
                                    class="rounded text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $issue->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs text-ink-muted">{{ $issue->project->reference }}</span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-ink-muted">{{ __('Category') }}</dt>
                            <dd class="mt-1 flex items-center gap-1.5 text-sm text-ink">
                                <x-ui.icon :name="$issue->category->icon()" class="size-4 text-ink-subtle" />
                                {{ $issue->category->label() }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-ink-muted">{{ __('Raised by') }}</dt>
                            <dd class="mt-1 text-sm text-ink">
                                {{ $issue->raisedBy?->name ?? __('Unknown') }}
                                <span class="block text-xs text-ink-muted">
                                    {{ $issue->created_at->translatedFormat('j M Y') }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-ink-muted">{{ __('Owner') }}</dt>
                            <dd class="mt-1 text-sm">
                                @if ($issue->owner)
                                    <span class="text-ink">{{ $issue->owner->name }}</span>
                                @else
                                    <span class="text-warning-ink">{{ __('Unassigned') }}</span>
                                @endif
                            </dd>
                        </div>
                    </div>

                    @if ($issue->resolution_note)
                        <div>
                            <dt class="text-xs font-medium text-ink-muted">{{ __('How it was resolved') }}</dt>
                            <dd class="mt-1 text-sm whitespace-pre-line text-ink">{{ $issue->resolution_note }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            {{-- The evidence vault — the shared panel, not a second
                 implementation: the rules about what may be uploaded live in
                 config/documents.php and are enforced in AttachDocument. --}}
            <livewire:shared.document-panel
                :model="$issue"
                collection="issue_evidence"
                :readonly="! $canUpdate"
                :heading="__('Evidence')"
            />

            {{-- ------------------------------------------------------- --}}
            {{-- The ledger                                               --}}
            {{-- ------------------------------------------------------- --}}
            <x-ui.card :title="__('History')" :subtitle="__('Append-only. Every step of this issue\'s life, in order.')">
                <ol class="space-y-4">
                    @foreach ($this->timeline as $event)
                        <li class="flex gap-3" wire:key="event-{{ $event->id }}">
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-soft text-neutral-ink">
                                <x-ui.icon :name="$event->to_status->icon()" class="size-4" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink">
                                    @if ($event->isCreation())
                                        {{ __('Raised') }}
                                    @else
                                        {{ __(':from → :to', [
                                            'from' => $event->from_status?->label(),
                                            'to' => $event->to_status->label(),
                                        ]) }}
                                    @endif
                                </p>

                                <p class="mt-0.5 text-xs text-ink-muted">
                                    {{ $event->isSystemAction()
                                        ? __('By the platform — no action was taken in time')
                                        : __('By :name', ['name' => $event->actor?->name ?? __('a removed account')]) }}
                                    &middot;
                                    {{ $event->occurred_at->translatedFormat('j M Y, H:i') }}
                                </p>

                                @if ($event->reason)
                                    <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">{{ $event->reason }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>

        {{-- ------------------------------------------------------------ --}}
        {{-- The decision + the plan                                       --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4">
            <x-ui.card :title="__('Move it on')">
                @if ($this->blockedReason())
                    <p class="text-sm text-ink-muted">{{ $this->blockedReason() }}</p>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->availableTransitions as $target)
                            <x-ui.button
                                :variant="$target === \App\Enums\IssueStatus::Resolved ? 'primary' : 'secondary'"
                                :icon="$target->icon()"
                                wire:click="startTransition('{{ $target->value }}')"
                                loading="startTransition('{{ $target->value }}')"
                            >{{ __('Mark as :status', ['status' => $target->label()]) }}</x-ui.button>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            @if ($canUpdate)
                <x-ui.card :title="__('Owner')">
                    <form wire:submit="saveOwner" class="space-y-3">
                        <x-ui.form.group name="ownerId" :label="__('Accountable for clearing it')">
                            <x-ui.form.select
                                name="ownerId"
                                :placeholder="__('Nobody yet')"
                                :options="$this->ownerOptions"
                                wire:model="ownerId"
                            />
                        </x-ui.form.group>

                        <x-ui.button type="submit" variant="secondary" size="sm" loading="saveOwner" class="w-full">
                            {{ __('Save owner') }}
                        </x-ui.button>
                    </form>
                </x-ui.card>

                <x-ui.card
                    :title="__('Corrective action')"
                    :subtitle="__('What will be done, by when, and how bad this actually is.')"
                >
                    <form wire:submit="saveCorrectiveAction" class="space-y-3">
                        <x-ui.form.group name="correctiveAction" :label="__('What will be done')">
                            <x-ui.form.textarea
                                name="correctiveAction"
                                rows="4"
                                maxlength="2000"
                                :placeholder="__('e.g. Temporary bailey crossing to be installed; LGA works department to mobilise.')"
                                wire:model="correctiveAction"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="dueDate"
                            :label="__('Due by')"
                            :hint="__('An issue with no deadline is never flagged as overdue.')"
                        >
                            <x-ui.form.input name="dueDate" type="date" has-hint wire:model="dueDate" />
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="severity"
                            :label="__('Severity')"
                            :hint="__('Drives how long it may sit open before it escalates.')"
                        >
                            <x-ui.form.select
                                name="severity"
                                :options="$this->severityOptions()"
                                has-hint
                                wire:model="severity"
                            />
                        </x-ui.form.group>

                        <x-ui.button type="submit" variant="secondary" size="sm" loading="saveCorrectiveAction" class="w-full">
                            {{ __('Save corrective action') }}
                        </x-ui.button>
                    </form>
                </x-ui.card>
            @elseif ($issue->corrective_action)
                <x-ui.card :title="__('Corrective action')">
                    <p class="text-sm whitespace-pre-line text-ink">{{ $issue->corrective_action }}</p>
                    @if ($issue->due_date)
                        <p class="mt-2 text-xs text-ink-muted">
                            {{ __('Due :date', ['date' => $issue->due_date->translatedFormat('j M Y')]) }}
                        </p>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Confirming a move                                                 --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="issue-transition"
        :title="$pending ? __('Mark this issue as :status?', ['status' => $pending->label()]) : __('Move this issue on?')"
        :description="__('The change is recorded on the issue\'s append-only history with your name against it.')"
        max-width="md"
    >
        @if ($this->reasonRequired())
            <x-ui.form.group
                name="reason"
                :label="$pending === \App\Enums\IssueStatus::Resolved ? __('What was done') : __('Why it is being closed')"
                :hint="__('Required. Kept on the record and read by anyone auditing this entity\'s corrective actions.')"
                required
            >
                <x-ui.form.textarea
                    name="reason"
                    rows="3"
                    maxlength="2000"
                    has-hint
                    :placeholder="__('e.g. Crossing reinstated and haul route reopened on 14 March.')"
                    wire:model="reason"
                />
            </x-ui.form.group>
        @else
            <p class="text-sm text-ink-muted">
                {{ __('No note is needed for this step — the history records who took it and when.') }}
            </p>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelTransition">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                wire:click="confirmTransition"
                loading="confirmTransition"
                :icon="$pending?->icon()"
            >{{ $pending ? __('Mark as :status', ['status' => $pending->label()]) : __('Confirm') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
