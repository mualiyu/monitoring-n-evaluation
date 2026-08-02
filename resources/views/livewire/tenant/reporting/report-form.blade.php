{{--
    Reporting wizard (App\Livewire\Tenant\Reporting\ReportForm).

    Four steps, with the autosave state stated plainly at the top of every one:
    a field officer on a bad connection needs to SEE that their typing is safe,
    not be told so in a tooltip.
--}}
@php
    $report = $this->report;
    $obligation = $this->obligation;
@endphp

<div>
    <x-ui.page-header
        :title="__('File a progress report')"
        :description="__('Report what was actually built and spent in this window. Figures you file here move the project record once they are approved.')"
        :back="url('/reports')"
        :back-label="__('Back to progress reports')"
    />

    <x-ui.steps
        :steps="$this->steps()"
        :current="$step"
        :label="__('Progress report steps')"
        class="mb-6"
    />

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('This report cannot be filed yet')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    {{-- Autosave state. Shown from the moment a draft row exists. --}}
    @if ($report)
        <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-muted">
            <span class="inline-flex items-center gap-1.5">
                <x-ui.icon name="check-circle" class="size-4 text-positive" />
                @if ($savedAt)
                    {{ __('Draft saved :time', ['time' => \Illuminate\Support\Carbon::parse($savedAt)->diffForHumans()]) }}
                @else
                    {{ __('Draft started — your typing is saved as you go') }}
                @endif
            </span>

            @if ($obligation)
                <span aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1.5">
                    <x-ui.icon name="clock" class="size-4" />
                    {{ __('Due :date', ['date' => $obligation->due_at->translatedFormat('j M Y')]) }}
                    @if ($obligation->isOverdue())
                        <x-ui.badge status="overdue" size="sm" />
                    @endif
                </span>
            @endif
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Step 1 — window & project                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($step === 1)
        <x-ui.card :title="__('What are you reporting on?')" :subtitle="__('One return per project per window. Opening a window you have already started takes you back to that draft.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="projectUlid" :label="__('Project')" required :error="$errors->first('projectUlid')">
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('Choose a project…')"
                        :options="$this->projects->pluck('title', 'ulid')->all()"
                        wire:model="projectUlid"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="periodId" :label="__('Reporting window')" required :error="$errors->first('periodId')">
                    <x-ui.form.select
                        name="periodId"
                        :placeholder="__('Choose a window…')"
                        :options="$this->periods->pluck('label', 'id')->all()"
                        wire:model="periodId"
                    />
                </x-ui.form.group>
            </div>

            @if ($this->projects->isEmpty())
                <x-ui.alert variant="info" class="mt-4" :title="__('No projects to report on')">
                    {{ __('Progress returns are filed against projects under execution. You are not assigned to any project that is currently reporting.') }}
                </x-ui.alert>
            @endif

            <x-slot:footer>
                <div class="flex justify-end">
                    <x-ui.button icon="arrow-right" wire:click="start" loading="start">
                        {{ __('Start the report') }}
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Step 2 — work done, progress and spend                            --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($step === 2)
        <x-ui.card :title="__('Work done, progress and spend')" :subtitle="__('The figures a reviewer will check against the site and the valuation.')">
            <x-ui.form.group
                name="narrative_work_done"
                :label="__('What was done in this period')"
                required
                :hint="__('Describe the actual works — sections completed, structures cast, quantities laid. This is what the reviewer verifies.')"
                :error="$errors->first('narrative_work_done')"
            >
                <x-ui.form.textarea
                    name="narrative_work_done"
                    rows="6"
                    :maxlength="5000"
                    has-hint
                    wire:model.blur="narrative_work_done"
                />
            </x-ui.form.group>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="physical_progress_claimed"
                    :label="__('Cumulative physical progress')"
                    required
                    :hint="$report && $report->project
                        ? __('The project record currently stands at :current%.', ['current' => $report->project->physical_progress])
                        : null"
                    :error="$errors->first('physical_progress_claimed')"
                >
                    <x-ui.form.input
                        name="physical_progress_claimed"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        suffix="%"
                        has-hint
                        wire:model.blur="physical_progress_claimed"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="period_expenditure"
                    :label="__('Expenditure in THIS period')"
                    required
                    :hint="__('Not the cumulative total — the platform adds up the periods itself.')"
                    :error="$errors->first('period_expenditure')"
                >
                    <x-ui.form.input
                        name="period_expenditure"
                        type="number"
                        step="0.01"
                        min="0"
                        :prefix="config('platform.instance.currency')"
                        has-hint
                        wire:model.blur="period_expenditure"
                    />
                </x-ui.form.group>
            </div>

            {{-- Only asked for when it is actually needed: a permanently
                 visible "why did it go down?" box on a form where it usually
                 did not is noise. --}}
            @if ($this->claimIsBelowRecordedProgress())
                <div class="mt-4">
                    <x-ui.alert variant="warning" :title="__('This is lower than the recorded progress')" class="mb-3">
                        {{ __('Progress can be revised down — re-measurement, defective work removed — but the reason goes on the record.') }}
                    </x-ui.alert>

                    <x-ui.form.group
                        name="progress_decrease_reason"
                        :label="__('Why has it been revised down?')"
                        required
                        :error="$errors->first('progress_decrease_reason')"
                    >
                        <x-ui.form.textarea
                            name="progress_decrease_reason"
                            rows="3"
                            :maxlength="2000"
                            wire:model.blur="progress_decrease_reason"
                        />
                    </x-ui.form.group>
                </div>
            @endif

            <x-slot:footer>
                <div class="flex flex-wrap justify-between gap-2">
                    <x-ui.button variant="ghost" icon="arrow-left" :href="url('/reports')">
                        {{ __('Leave — the draft is saved') }}
                    </x-ui.button>

                    <x-ui.button trailing-icon="arrow-right" wire:click="next" loading="next">
                        {{ __('Continue') }}
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Step 3 — challenges & mitigation                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($step === 3)
        <x-ui.card :title="__('Challenges and what is being done about them')" :subtitle="__('The manual pairs these deliberately: a challenge reported without a mitigation is an excuse, not a report.')">
            <x-ui.form.group
                name="narrative_challenges"
                :label="__('Challenges encountered')"
                :error="$errors->first('narrative_challenges')"
            >
                <x-ui.form.textarea
                    name="narrative_challenges"
                    rows="4"
                    :maxlength="5000"
                    wire:model.blur="narrative_challenges"
                />
            </x-ui.form.group>

            <div class="mt-4">
                <x-ui.form.group
                    name="narrative_mitigation"
                    :label="__('Mitigation — what is being done')"
                    :error="$errors->first('narrative_mitigation')"
                >
                    <x-ui.form.textarea
                        name="narrative_mitigation"
                        rows="4"
                        :maxlength="5000"
                        wire:model.blur="narrative_mitigation"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-4">
                <x-ui.form.group
                    name="narrative_next_period"
                    :label="__('Planned for the next period')"
                    :error="$errors->first('narrative_next_period')"
                >
                    <x-ui.form.textarea
                        name="narrative_next_period"
                        rows="3"
                        :maxlength="5000"
                        wire:model.blur="narrative_next_period"
                    />
                </x-ui.form.group>
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap justify-between gap-2">
                    <x-ui.button variant="secondary" icon="arrow-left" wire:click="back">
                        {{ __('Back') }}
                    </x-ui.button>

                    <x-ui.button trailing-icon="arrow-right" wire:click="next" loading="next">
                        {{ __('Continue') }}
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Step 4 — review & file                                            --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($step === 4 && $report)
        <x-ui.card :title="__('Check it, then file it')" :subtitle="__('Once filed, the figures and evidence freeze until a reviewer returns it to you.')">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-ink-muted">{{ __('Project') }}</dt>
                    <dd class="mt-0.5 font-medium text-ink">
                        {{ $report->project->title }}
                        <span class="block font-mono text-xs font-normal text-ink-muted">{{ $report->project->reference }}</span>
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-ink-muted">{{ __('Reporting window') }}</dt>
                    <dd class="mt-0.5 font-medium text-ink">
                        {{ $report->reportingPeriod->label }}
                        <span class="block text-xs font-normal text-ink-muted">
                            {{ __('due :date', ['date' => $report->due_at->translatedFormat('j M Y')]) }}
                        </span>
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-ink-muted">{{ __('Cumulative physical progress claimed') }}</dt>
                    <dd class="mt-1">
                        <x-ui.progress
                            :value="$report->physical_progress_claimed"
                            :label="__('Physical progress claimed')"
                        />
                    </dd>
                </div>

                <div>
                    <dt class="text-sm text-ink-muted">{{ __('Expenditure this period') }}</dt>
                    <dd class="mt-0.5 font-medium text-ink tabular-nums">
                        {{ $report->period_expenditure->format() }}
                    </dd>
                </div>
            </dl>

            <div class="mt-6 space-y-4 border-t border-line pt-4">
                <div>
                    <h3 class="text-sm font-semibold text-ink">{{ __('Work done in this period') }}</h3>
                    <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">{{ $report->narrative_work_done }}</p>
                </div>

                @if ($report->narrative_challenges)
                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Challenges') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">{{ $report->narrative_challenges }}</p>
                    </div>
                @endif

                @if ($report->narrative_mitigation)
                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Mitigation') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">{{ $report->narrative_mitigation }}</p>
                    </div>
                @endif

                @if ($report->progress_decrease_reason)
                    <div>
                        <h3 class="text-sm font-semibold text-ink">{{ __('Reason for the downward revision') }}</h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink-muted">{{ $report->progress_decrease_reason }}</p>
                    </div>
                @endif
            </div>

            @if ($obligation && $obligation->isOverdue())
                <x-ui.alert variant="warning" class="mt-4" :title="__('This return is past its deadline')">
                    {{ __('It will still be accepted, and it will be recorded as filed late on the state compliance report.') }}
                </x-ui.alert>
            @endif

            <x-slot:footer>
                <div class="flex flex-wrap justify-between gap-2">
                    <x-ui.button variant="secondary" icon="arrow-left" wire:click="back">
                        {{ __('Back') }}
                    </x-ui.button>

                    <x-ui.button icon="paper-airplane" wire:click="submit" loading="submit">
                        {{ __('File this report') }}
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
