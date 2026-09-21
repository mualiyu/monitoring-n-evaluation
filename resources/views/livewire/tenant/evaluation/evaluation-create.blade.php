{{--
    Commission an evaluation (App\Livewire\Tenant\Evaluation\EvaluationCreate).

    Three cards — what is being evaluated, the commission itself, the team —
    rather than a wizard: a commission is eleven fields, and step indicators
    for eleven fields are ceremony. Every control has a label; every field that
    the Action can refuse says why in its hint before it is submitted.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $evaluatesAProject = $this->evaluatesAProject();
@endphp

<div>
    <x-ui.page-header
        :title="__('Commission an evaluation')"
        :description="__('An evaluation asks whether what was delivered was worth delivering, and what should be done differently. Its recommendations go onto the follow-up register and are tracked until they are implemented or closed.')"
        :back="route('tenant.evaluations.index', $workspace)"
        :back-label="__('Back to evaluations')"
    />

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-4">
        {{-- ------------------------------------------------------------ --}}
        {{-- What is being evaluated                                       --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('What is being evaluated')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="scope"
                    :label="__('Unit of evaluation')"
                    :hint="__('A study of one project, or of a programme that spans several.')"
                    required
                >
                    <x-ui.form.select
                        name="scope"
                        :options="$this->scopeOptions"
                        has-hint
                        wire:model.live="scope"
                    />
                </x-ui.form.group>

                @if ($evaluatesAProject)
                    <x-ui.form.group name="projectUlid" :label="__('Project')" required>
                        <x-ui.form.select
                            name="projectUlid"
                            :placeholder="__('Choose a project…')"
                            :options="$this->projectOptions"
                            wire:model="projectUlid"
                        />
                    </x-ui.form.group>
                @else
                    <x-ui.form.group
                        name="subjectName"
                        :label="__('Programme, sector or theme')"
                        :hint="__('What this evaluation covers, as it should appear on the report’s title page.')"
                        required
                    >
                        <x-ui.form.input name="subjectName" has-hint wire:model="subjectName" />
                    </x-ui.form.group>
                @endif

                <x-ui.form.group
                    name="type"
                    :label="__('Evaluation type')"
                    :hint="$this->typeDescription"
                    required
                >
                    <x-ui.form.select
                        name="type"
                        :options="$this->typeOptions"
                        has-hint
                        wire:model.live="type"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="sponsor"
                    :label="__('Commissioned by')"
                    :hint="__('The body that asked for this evaluation and will receive its findings.')"
                    required
                >
                    <x-ui.form.input name="sponsor" has-hint wire:model="sponsor" />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- The commission                                                --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('The commission')">
            <div class="space-y-4">
                <x-ui.form.group name="title" :label="__('Title')" required>
                    <x-ui.form.input
                        name="title"
                        maxlength="255"
                        :placeholder="__('e.g. Mid-term evaluation of the township road rehabilitation programme')"
                        wire:model="title"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="purpose"
                    :label="__('Purpose')"
                    :hint="__('Why this evaluation is being done and what decision its findings are meant to inform.')"
                    required
                >
                    <x-ui.form.textarea name="purpose" rows="3" maxlength="5000" has-hint wire:model="purpose" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="evaluationQuestions"
                    :label="__('Evaluation questions')"
                    :hint="__('The questions the report must answer, one per line. They become the spine of the findings chapter.')"
                    optional
                >
                    <x-ui.form.textarea
                        name="evaluationQuestions"
                        rows="3"
                        maxlength="5000"
                        has-hint
                        wire:model="evaluationQuestions"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="methodologySummary"
                    :label="__('Methodology')"
                    :hint="__('How evidence will be gathered — desk review, site observation, interviews, focus groups.')"
                    optional
                >
                    <x-ui.form.textarea
                        name="methodologySummary"
                        rows="3"
                        maxlength="5000"
                        has-hint
                        wire:model="methodologySummary"
                    />
                </x-ui.form.group>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.form.group name="startsOn" :label="__('Starts')" optional>
                        <x-ui.form.input name="startsOn" type="date" wire:model="startsOn" />
                    </x-ui.form.group>

                    <x-ui.form.group name="endsOn" :label="__('Ends')" optional>
                        <x-ui.form.input name="endsOn" type="date" wire:model="endsOn" />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="reportDueOn"
                        :label="__('Report due')"
                        :hint="__('Chased if it passes.')"
                        optional
                    >
                        <x-ui.form.input name="reportDueOn" type="date" has-hint wire:model="reportDueOn" />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="budget"
                        :label="__('Budget')"
                        :hint="__('Best practice reserves 2–5% of the intervention’s budget for M&E.')"
                        optional
                    >
                        <x-ui.form.input
                            name="budget"
                            type="text"
                            inputmode="decimal"
                            :prefix="__('₦')"
                            has-hint
                            wire:model="budget"
                        />
                    </x-ui.form.group>
                </div>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- The team                                                      --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card
            :title="__('The evaluation team')"
            :subtitle="__('The lead signs for the findings and, for that reason, cannot approve them. Most evaluation teams include contracted evaluators with no account here — name them as externals.')"
        >
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group
                        name="leadUserId"
                        :label="__('Lead (a colleague)')"
                        :hint="__('Leave empty if the lead is an external evaluator.')"
                    >
                        <x-ui.form.select
                            name="leadUserId"
                            :placeholder="__('Choose a colleague…')"
                            :options="$this->staffOptions"
                            has-hint
                            wire:model.live="leadUserId"
                        />
                    </x-ui.form.group>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.group name="leadExternalName" :label="__('Lead (external)')">
                            <x-ui.form.input
                                name="leadExternalName"
                                :placeholder="__('Full name')"
                                wire:model.live="leadExternalName"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group name="leadOrganisation" :label="__('Their organisation')" optional>
                            <x-ui.form.input name="leadOrganisation" wire:model="leadOrganisation" />
                        </x-ui.form.group>
                    </div>
                </div>

                <x-ui.form.group
                    name="memberIds"
                    :label="__('Team members (colleagues)')"
                    :hint="__('Hold ⌘ or Ctrl to choose more than one.')"
                    optional
                >
                    <x-ui.form.select
                        name="memberIds"
                        multiple
                        size="5"
                        :options="$this->staffOptions"
                        has-hint
                        wire:model="memberIds"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="externalMembers"
                    :label="__('Team members (external)')"
                    :hint="__('One name per line.')"
                    optional
                >
                    <x-ui.form.textarea
                        name="externalMembers"
                        rows="3"
                        maxlength="2000"
                        has-hint
                        :placeholder="__('Dr. A. Balogun')"
                        wire:model="externalMembers"
                    />
                </x-ui.form.group>
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <x-ui.button variant="secondary" :href="route('tenant.evaluations.index', $workspace)">
                        {{ __('Cancel') }}
                    </x-ui.button>

                    <x-ui.button type="submit" icon="check" loading="save">
                        {{ __('Commission evaluation') }}
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>
