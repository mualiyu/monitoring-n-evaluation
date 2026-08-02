{{--
    Return detail + decision (App\Livewire\Tenant\Reporting\ReportReview).

    Two columns on desktop, stacked on mobile: what was reported on the left,
    what you may do about it on the right. The chain timeline sits under the
    decision panel — "who has already touched this" is the first thing a
    director wants before signing.
--}}
@php
    $report = $this->report;
    $project = $report->project;
    $blocked = $this->blockedReason();
@endphp

<div>
    <x-ui.page-header
        :title="__(':window progress report', ['window' => $report->reportingPeriod->label])"
        :description="$project->title.' · '.$project->reference"
        :back="url('/reports')"
        :back-label="__('Back to progress reports')"
    >
        <x-slot:actions>
            <x-ui.badge :status="$report->status->value" />

            @if ($report->submitted_late)
                <x-ui.badge status="overdue" :label="__('Filed late')" />
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

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ------------------------------------------------------------ --}}
        {{-- The return                                                    --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card :title="__('Figures reported')">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-ink-muted">{{ __('Cumulative physical progress claimed') }}</dt>
                        <dd class="mt-1">
                            <x-ui.progress
                                :value="$report->physical_progress_claimed"
                                :label="__('Physical progress claimed in this return')"
                            />
                            <span class="mt-1 block text-xs text-ink-muted">
                                {{ __('Project record currently stands at :current%', ['current' => $project->physical_progress]) }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-ink-muted">{{ __('Expenditure in this period') }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-ink tabular-nums">
                            {{ $report->period_expenditure->format() }}
                        </dd>
                        <span class="mt-0.5 block text-xs text-ink-muted">
                            {{ __('Project total to date: :total', ['total' => $project->expenditure_to_date->format()]) }}
                        </span>
                    </div>

                    <div>
                        <dt class="text-sm text-ink-muted">{{ __('Deadline for this window') }}</dt>
                        <dd class="mt-0.5 font-medium text-ink">{{ $report->due_at->translatedFormat('j M Y') }}</dd>
                    </div>

                    <div>
                        <dt class="text-sm text-ink-muted">{{ __('How it was filed') }}</dt>
                        <dd class="mt-0.5 font-medium text-ink">
                            {{ $report->entry_mode->label() }}
                            @if ($report->contractor)
                                <span class="block text-xs font-normal text-ink-muted">
                                    {{ __('figures reported for :firm', ['firm' => $report->contractor->name]) }}
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($report->progress_decrease_reason)
                    <x-ui.alert variant="warning" class="mt-4" :title="__('Progress was revised downward')">
                        {{ $report->progress_decrease_reason }}
                    </x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('What was reported')">
                <div class="space-y-5">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Work done in this period') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">{{ $report->narrative_work_done }}</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Challenges') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">
                            {{ $report->narrative_challenges ?: __('None reported for this period.') }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Mitigation') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">
                            {{ $report->narrative_mitigation ?: __('None reported for this period.') }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Planned for next period') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">
                            {{ $report->narrative_next_period ?: __('Not stated.') }}
                        </p>
                    </div>
                </div>
            </x-ui.card>
        </div>

        {{-- ------------------------------------------------------------ --}}
        {{-- The decision                                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4">
            <x-ui.card :title="__('Your decision')">
                @if ($this->returning)
                    <x-ui.form.group
                        name="returnReason"
                        :label="__('Why is it going back?')"
                        required
                        :hint="__('The author sees this word for word. Name what to fix.')"
                        :error="$errors->first('returnReason')"
                    >
                        <x-ui.form.textarea
                            name="returnReason"
                            rows="4"
                            :maxlength="2000"
                            has-hint
                            wire:model="returnReason"
                        />
                    </x-ui.form.group>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button variant="secondary" wire:click="cancelReturn">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button icon="arrow-uturn-left" wire:click="confirmReturn" loading="confirmReturn">
                            {{ __('Send it back') }}
                        </x-ui.button>
                    </div>
                @else
                    <div class="space-y-2">
                        @if ($this->canReview())
                            <x-ui.button
                                class="w-full"
                                icon="clipboard-check"
                                wire:click="review"
                                loading="review"
                            >{{ __('Mark as reviewed') }}</x-ui.button>
                        @endif

                        @if ($this->canApprove())
                            <x-ui.button
                                class="w-full"
                                icon="check-circle"
                                wire:click="approve"
                                loading="approve"
                            >{{ __('Approve this return') }}</x-ui.button>
                        @endif

                        @if ($this->canReturn())
                            <x-ui.button
                                class="w-full"
                                variant="secondary"
                                icon="arrow-uturn-left"
                                wire:click="startReturn"
                            >{{ __('Send back for correction') }}</x-ui.button>
                        @endif

                        @if ($this->canEdit())
                            <x-ui.button
                                class="w-full"
                                variant="secondary"
                                icon="pencil-square"
                                :href="url('/reports/'.$report->ulid.'/edit')"
                            >{{ __('Continue editing') }}</x-ui.button>
                        @endif

                        @if ($blocked)
                            {{-- A panel that simply goes blank reads as a bug;
                                 naming the reason turns a dead end into an
                                 explanation. --}}
                            <p class="flex items-start gap-2 text-sm text-ink-muted">
                                <x-ui.icon name="information-circle" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ $blocked }}</span>
                            </p>
                        @endif
                    </div>

                    @if ($this->canApprove())
                        <p class="mt-3 text-xs text-ink-muted">
                            {{ __('Approving applies the claimed progress and adds this period’s spend to the project record.') }}
                        </p>
                    @endif
                @endif
            </x-ui.card>

            {{-- --------------------------------------------------------- --}}
            {{-- Chain timeline                                             --}}
            {{-- --------------------------------------------------------- --}}
            <x-ui.card :title="__('Approval chain')" :subtitle="__('Every step, in order, with who signed for it')">
                @if ($this->chain->isEmpty())
                    <p class="text-sm text-ink-muted">{{ __('Nothing has happened to this return yet.') }}</p>
                @else
                    <ol class="space-y-4">
                        @foreach ($this->chain as $event)
                            <li class="flex gap-3">
                                <div class="flex flex-col items-center">
                                    <span
                                        @class([
                                            'flex size-7 shrink-0 items-center justify-center rounded-full ring-1 ring-inset',
                                            'bg-positive-soft text-positive-ink ring-positive/30' => $event->to_status->value === 'approved',
                                            'bg-warning-soft text-warning-ink ring-warning/40' => $event->to_status->value === 'returned',
                                            'bg-brand-soft text-brand-ink ring-brand/30' => ! in_array($event->to_status->value, ['approved', 'returned'], true),
                                        ])
                                    >
                                        <x-ui.icon
                                            :name="match ($event->to_status->value) {
                                                'submitted' => 'paper-airplane',
                                                'reviewed' => 'clipboard-check',
                                                'approved' => 'check-circle',
                                                'returned' => 'arrow-uturn-left',
                                                default => 'pencil-square',
                                            }"
                                            class="size-4"
                                        />
                                    </span>

                                    @unless ($loop->last)
                                        <span class="mt-1 w-px flex-1 bg-line" aria-hidden="true"></span>
                                    @endunless
                                </div>

                                <div class="min-w-0 flex-1 pb-1">
                                    <p class="text-sm font-medium text-ink">{{ $event->to_status->label() }}</p>
                                    <p class="text-xs text-ink-muted">
                                        {{ $event->actor?->name ?? __('System') }}
                                        · {{ $event->occurred_at->translatedFormat('j M Y, H:i') }}
                                    </p>
                                    @if ($event->reason)
                                        <p class="mt-1 rounded-lg bg-neutral-soft px-3 py-2 text-xs text-neutral-ink">
                                            {{ $event->reason }}
                                        </p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
