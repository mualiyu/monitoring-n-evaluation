{{--
    Edit project details (App\Livewire\Tenant\Projects\ProjectEdit).

    One page, three grouped panels — not a wizard: the record already exists, so
    an officer correcting one field should not walk three steps to reach it.

    When the project is certified or closed the certificate-attested fields are
    rendered read-only with the reason stated. The component's $this->frozen
    mirrors Project::isFrozen(); UpdateProjectDetails remains the authority and
    its refusal surfaces in the $failure alert.
--}}
<div
    x-data="{ dirty: false }"
    x-on:beforeunload.window="if (dirty) $event.preventDefault()"
    x-on:input="dirty = true"
>
    <x-ui.page-header
        :title="__('Edit project details')"
        :description="$project->title"
        :back="url('/projects/'.$project->ulid)"
        :back-label="__('Back to project')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => url('/projects')],
            ['label' => $project->reference, 'href' => url('/projects/'.$project->ulid)],
            ['label' => __('Edit')],
        ]"
    />

    @if ($this->frozen)
        <x-ui.alert variant="info" class="mb-5" :title="__('This project’s figures are locked')">
            {{ __('A completion certificate attests to the scope, money and dates below, so they can no longer be edited. The accountable officer and the reporting frequency can still be changed. Corrections to locked figures go through the amendment register.') }}
        </x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That change was not saved')">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-5">
        {{-- ------------------------------------------------------------ --}}
        {{-- Identity & scope                                              --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Identity & scope')" :subtitle="__('What the project is and who is accountable for it')">
            <div class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group name="title" :label="__('Project title')" required>
                        <x-ui.form.input name="title" wire:model.blur="title" :disabled="$this->frozen" />
                        <x-ui.form.error name="title" />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="reference"
                        :label="__('Reference')"
                        :hint="__('The entity’s own project code — unique in this workspace.')"
                        required
                    >
                        <x-ui.form.input name="reference" has-hint wire:model.blur="reference" :disabled="$this->frozen" />
                        <x-ui.form.error name="reference" />
                    </x-ui.form.group>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group name="sector_id" :label="__('Sector')" required>
                        <x-ui.form.select
                            name="sector_id"
                            :placeholder="__('Choose a sector')"
                            :options="$this->sectors->pluck('name', 'id')->all()"
                            wire:model.blur="sector_id"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="sector_id" />
                    </x-ui.form.group>

                    <x-ui.form.group name="type" :label="__('Project type')" required>
                        <x-ui.form.select
                            name="type"
                            :options="collect(\App\Enums\ProjectType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                            wire:model.blur="type"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="type" />
                    </x-ui.form.group>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    {{-- Stays editable after certification: the freeze covers
                         the figures, not who is accountable for the project. --}}
                    <x-ui.form.group name="manager_id" :label="__('Project manager')" optional>
                        <x-ui.form.select
                            name="manager_id"
                            :placeholder="__('Unassigned')"
                            :options="$this->managers->pluck('name', 'id')->all()"
                            wire:model.blur="manager_id"
                        />
                        <x-ui.form.error name="manager_id" />
                    </x-ui.form.group>

                    <x-ui.form.group name="supervising_agency_name" :label="__('Supervising agency')" optional>
                        <x-ui.form.input
                            name="supervising_agency_name"
                            wire:model.blur="supervising_agency_name"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="supervising_agency_name" />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group name="description" :label="__('Description')" optional>
                    <x-ui.form.textarea
                        name="description"
                        rows="3"
                        maxlength="5000"
                        wire:model.blur="description"
                        :disabled="$this->frozen"
                    />
                    <x-ui.form.error name="description" />
                </x-ui.form.group>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group name="goal" :label="__('Goal')" optional>
                        <x-ui.form.textarea
                            name="goal"
                            rows="3"
                            maxlength="2000"
                            wire:model.blur="goal"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="goal" />
                    </x-ui.form.group>

                    <x-ui.form.group name="objectives" :label="__('Objectives')" optional>
                        <x-ui.form.textarea
                            name="objectives"
                            rows="3"
                            maxlength="5000"
                            wire:model.blur="objectives"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="objectives" />
                    </x-ui.form.group>
                </div>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Budget                                                        --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Budget')" :subtitle="__('The appropriation this project draws on — contract sums are managed on the contracts tab')">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.form.group
                    name="budget_allocation"
                    :label="__('Budget allocation')"
                    :hint="__('Appropriated amount, in :currency.', ['currency' => config('platform.instance.currency')])"
                    optional
                >
                    <x-ui.form.input
                        name="budget_allocation"
                        type="text"
                        inputmode="decimal"
                        has-hint
                        wire:model.blur="budget_allocation"
                        :disabled="$this->frozen"
                        class="tabular-nums"
                    />
                    <x-ui.form.error name="budget_allocation" />
                </x-ui.form.group>

                <x-ui.form.group name="budget_code" :label="__('Budget code')" optional>
                    <x-ui.form.input name="budget_code" wire:model.blur="budget_code" :disabled="$this->frozen" />
                    <x-ui.form.error name="budget_code" />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Schedule & reporting                                          --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Schedule & reporting')" :subtitle="__('Delivery dates and how often this project reports')">
            <div class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.form.group name="start_date" :label="__('Start date')" optional>
                        <x-ui.form.input name="start_date" type="date" wire:model.blur="start_date" :disabled="$this->frozen" />
                        <x-ui.form.error name="start_date" />
                    </x-ui.form.group>

                    <x-ui.form.group name="expected_end_date" :label="__('Expected completion')" optional>
                        <x-ui.form.input
                            name="expected_end_date"
                            type="date"
                            wire:model.blur="expected_end_date"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="expected_end_date" />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="revised_end_date"
                        :label="__('Revised completion')"
                        :hint="__('Set when an extension is granted.')"
                        optional
                    >
                        <x-ui.form.input
                            name="revised_end_date"
                            type="date"
                            has-hint
                            wire:model.blur="revised_end_date"
                            :disabled="$this->frozen"
                        />
                        <x-ui.form.error name="revised_end_date" />
                    </x-ui.form.group>
                </div>

                {{-- Not a frozen field: post-completion monitoring can still
                     change how often a certified project reports. --}}
                <x-ui.form.group
                    name="reporting_frequency"
                    :label="__('Reporting frequency')"
                    :hint="__('Drives the deadline calendar and the reminders sent to this project’s team.')"
                    optional
                >
                    <x-ui.form.select
                        name="reporting_frequency"
                        :placeholder="__('Use the workspace default')"
                        :options="collect(\App\Enums\MeasurementFrequency::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                        has-hint
                        wire:model.blur="reporting_frequency"
                    />
                    <x-ui.form.error name="reporting_frequency" />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit" icon="check-circle" loading="save" x-on:click="dirty = false">
                {{ __('Save changes') }}
            </x-ui.button>

            <x-ui.button variant="ghost" :href="url('/projects/'.$project->ulid)">
                {{ __('Cancel') }}
            </x-ui.button>

            <p class="text-xs text-ink-muted sm:ml-auto">
                {{ __('Every change is recorded in this project’s activity timeline.') }}
            </p>
        </div>
    </form>
</div>
