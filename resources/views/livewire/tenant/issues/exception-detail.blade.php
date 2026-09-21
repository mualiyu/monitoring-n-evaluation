{{--
    One deviation (App\Livewire\Tenant\Issues\ExceptionDetail).

    THE MEASUREMENT IS THE SCREEN. Everything else hangs off "is this real?",
    and that question is only answerable because the record stored every figure
    that went into the judgement rather than re-deriving them from a project
    whose numbers have since moved.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $report = $this->exceptionReport;
    $pending = $this->pendingStatus ? \App\Enums\ExceptionStatus::from($this->pendingStatus) : null;
@endphp

<div>
    <x-ui.page-header
        :title="$report->trigger->label()"
        :description="$report->project->title"
        :back="route('tenant.exceptions.index', $workspace)"
        :back-label="__('Back to the deviation board')"
        :breadcrumbs="[
            ['label' => __('Exception reports'), 'href' => route('tenant.exceptions.index', $workspace)],
            ['label' => $report->project->reference, 'href' => route('tenant.projects.show', [...$workspace, 'project' => $report->project])],
            ['label' => __('Deviation')],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge
                :status="$report->severity->badgeStatus()"
                :label="$report->severity->label()"
                :icon="$report->severity->icon()"
            />
            <x-ui.badge
                :status="$report->status->badgeStatus()"
                :label="$report->status->label()"
                :icon="$report->status->icon()"
            />
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

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card :title="__('What was measured')">
                <p class="text-sm whitespace-pre-line text-ink">{{ $report->narrative }}</p>

                @if ($report->measured_value !== null)
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <x-ui.stat
                            :label="__('Measured')"
                            :value="$report->measured_value.' '.$report->trigger->unit()"
                            icon="chart-bar"
                            intent="critical"
                            :hint="__('what tripped the tolerance')"
                        />
                        <x-ui.stat
                            :label="__('Tolerance in force')"
                            :value="$report->threshold_value.' '.$report->trigger->unit()"
                            icon="adjustments"
                            :hint="__('this entity\'s configured limit at the time')"
                        />
                    </div>
                @endif

                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium text-ink-muted">{{ __('Physical progress') }}</dt>
                        <dd class="mt-1">
                            <x-ui.progress :value="$report->physical_progress" :label="__('Physical progress')" size="sm" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-ink-muted">{{ __('Schedule elapsed') }}</dt>
                        <dd class="mt-1">
                            <x-ui.progress :value="$report->schedule_elapsed" :label="__('Schedule elapsed')" size="sm" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-ink-muted">{{ __('Financial progress') }}</dt>
                        <dd class="mt-1">
                            <x-ui.progress :value="$report->financial_progress" :label="__('Financial progress')" size="sm" />
                        </dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-ink-muted">
                    {{ $report->isAutomatic()
                        ? __('Measured by the nightly threshold sweep on :date.', ['date' => $report->measured_at->translatedFormat('j M Y, H:i')])
                        : __('Reported by :name on :date.', [
                            'name' => $report->raisedBy?->name ?? __('a removed account'),
                            'date' => $report->measured_at->translatedFormat('j M Y, H:i'),
                        ]) }}
                </p>
            </x-ui.card>

            @if ($report->resolution_note)
                <x-ui.card :title="__('Why the deviation no longer stands')">
                    <p class="text-sm whitespace-pre-line text-ink">{{ $report->resolution_note }}</p>
                    <p class="mt-2 text-xs text-ink-muted">
                        {{ __('Resolved by :name on :date.', [
                            'name' => $report->resolvedBy?->name ?? __('a removed account'),
                            'date' => $report->resolved_at?->translatedFormat('j M Y'),
                        ]) }}
                    </p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card :title="__('Answer for it')">
                @if ($this->blockedReason())
                    <p class="text-sm text-ink-muted">{{ $this->blockedReason() }}</p>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->availableTransitions as $target)
                            <x-ui.button
                                :variant="$target === \App\Enums\ExceptionStatus::Resolved ? 'primary' : 'secondary'"
                                :icon="$target->icon()"
                                wire:click="startTransition('{{ $target->value }}')"
                                loading="startTransition('{{ $target->value }}')"
                            >{{ __('Mark as :status', ['status' => $target->label()]) }}</x-ui.button>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- Turning a notice into work somebody owns. An exception report
                 says a project has drifted; it does not say what anyone will
                 do about it — that is an Issue. --}}
            <x-ui.card :title="__('Corrective action')">
                @if ($report->issue)
                    <p class="text-sm text-ink-muted">{{ __('An issue has been raised from this deviation:') }}</p>
                    <a
                        href="{{ route('tenant.issues.show', [...$workspace, 'issue' => $report->issue]) }}"
                        class="mt-2 block rounded text-sm font-medium text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >{{ $report->issue->title }}</a>
                    <span class="mt-2 inline-block">
                        <x-ui.badge
                            size="sm"
                            :status="$report->issue->status->badgeStatus()"
                            :label="$report->issue->status->label()"
                            :icon="$report->issue->status->icon()"
                        />
                    </span>
                @elseif ($this->canRaiseIssue())
                    @if (! $raisingIssue)
                        <p class="text-sm text-ink-muted">
                            {{ __('Nothing is being done about this deviation yet. Raising an issue puts a name and a deadline against it.') }}
                        </p>
                        <x-ui.button
                            variant="secondary"
                            size="sm"
                            icon="plus"
                            class="mt-3 w-full"
                            wire:click="startIssue"
                            loading="startIssue"
                        >{{ __('Raise an issue from this') }}</x-ui.button>
                    @else
                        <form wire:submit="confirmIssue" class="space-y-3">
                            <x-ui.form.group name="issueTitle" :label="__('Title')" required>
                                <x-ui.form.input name="issueTitle" maxlength="255" wire:model="issueTitle" />
                            </x-ui.form.group>

                            <x-ui.form.group name="issueCategory" :label="__('Category')" required>
                                <x-ui.form.select
                                    name="issueCategory"
                                    :placeholder="__('Choose a category…')"
                                    :options="$this->categoryOptions()"
                                    wire:model="issueCategory"
                                />
                            </x-ui.form.group>

                            <x-ui.form.group name="issueDescription" :label="__('What is happening')" required>
                                <x-ui.form.textarea
                                    name="issueDescription"
                                    rows="4"
                                    maxlength="5000"
                                    wire:model="issueDescription"
                                />
                            </x-ui.form.group>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <x-ui.button variant="secondary" size="sm" wire:click="cancelIssue" class="sm:flex-1">
                                    {{ __('Cancel') }}
                                </x-ui.button>
                                <x-ui.button type="submit" size="sm" loading="confirmIssue" class="sm:flex-1">
                                    {{ __('Raise it') }}
                                </x-ui.button>
                            </div>
                        </form>
                    @endif
                @else
                    <p class="text-sm text-ink-muted">
                        {{ __('No issue has been raised from this deviation yet.') }}
                    </p>
                @endif
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal
        name="exception-transition"
        :title="$pending ? __('Mark this deviation as :status?', ['status' => $pending->label()]) : __('Answer for this deviation?')"
        :description="__('The change is recorded against the report with your name and the time against it.')"
        max-width="md"
    >
        @if ($this->reasonRequired())
            <x-ui.form.group
                name="reason"
                :label="__('Why the deviation no longer stands')"
                :hint="__('Required. A report closed with no account of why is indistinguishable from one closed to clear a board.')"
                required
            >
                <x-ui.form.textarea
                    name="reason"
                    rows="3"
                    maxlength="2000"
                    has-hint
                    :placeholder="__('e.g. Extension of time approved; the revised programme brings the works back inside tolerance.')"
                    wire:model="reason"
                />
            </x-ui.form.group>
        @else
            <p class="text-sm text-ink-muted">
                {{ __('Acknowledging records that this entity has seen the deviation and accepts that it is real.') }}
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
