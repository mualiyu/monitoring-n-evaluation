{{--
    Project registration wizard (App\Livewire\Tenant\Projects\ProjectCreate).
    Step indicator → one panel per step → save. Draft autosaves to the session.
--}}
<div
    x-data="{ dirty: false }"
    x-on:beforeunload.window="if (dirty) $event.preventDefault()"
    x-on:input="dirty = true"
    x-on:project-saved.window="dirty = false"
>
    <x-ui.page-header
        :title="__('Register a project')"
        :description="__('Three short steps. Everything except the title, reference and sector can be completed later — the project is created as a draft.')"
        :back="url('/projects')"
        :back-label="__('Back to projects')"
    />

    @if ($draftRestored)
        <x-ui.alert variant="info" class="mb-5" :title="__('We restored your unfinished form')">
            <p>
                {{ __('You left this form part-filled. Nothing has been registered yet.') }}
                @if ($draftSavedAt)
                    <span class="text-ink-muted">
                        {{ __('Last saved :time.', ['time' => \Illuminate\Support\Carbon::parse($draftSavedAt)->diffForHumans()]) }}
                    </span>
                @endif
            </p>
            <p class="mt-2">
                <button
                    type="button"
                    wire:click="discardDraft"
                    wire:confirm="{{ __('Discard the restored form and start again?') }}"
                    class="rounded font-semibold underline underline-offset-2 hover:no-underline"
                >{{ __('Start again with a blank form') }}</button>
            </p>
        </x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('The project could not be registered')">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.steps
            :current="$step"
            state="$wire.step"
            :label="__('Project registration progress')"
            class="mb-6"
            :steps="[
                ['label' => __('Identity & scope'), 'description' => __('What is being delivered')],
                ['label' => __('Funding & budget'), 'description' => __('Who is paying for it')],
                ['label' => __('Location & schedule'), 'description' => __('Where and by when')],
            ]"
        />

        {{-- ------------------------------------------------------------ --}}
        {{-- Step 1 — identity & scope                                     --}}
        {{-- ------------------------------------------------------------ --}}
        @if ($step === 1)
            <div class="space-y-5 border-t border-line pt-5">
                <x-ui.form.group
                    name="title"
                    :label="__('Project title')"
                    :hint="__('As it appears in the appropriation, e.g. “Rehabilitation of Township Road, Section II”.')"
                    required
                >
                    <x-ui.form.input name="title" has-hint wire:model.blur="title" />
                </x-ui.form.group>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group
                        name="reference"
                        :label="__('Project reference')"
                        :hint="__('Your entity’s own code. Must be unique within this workspace.')"
                        required
                    >
                        <x-ui.form.input name="reference" has-hint wire:model.blur="reference" />
                    </x-ui.form.group>

                    <x-ui.form.group name="sector_id" :label="__('Sector')" required>
                        <x-ui.form.select
                            name="sector_id"
                            :placeholder="__('Choose a sector')"
                            :options="$this->sectors->pluck('name', 'id')->all()"
                            wire:model.blur="sector_id"
                        />
                    </x-ui.form.group>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group name="type" :label="__('Project type')" required>
                        <x-ui.form.select
                            name="type"
                            :options="collect(\App\Enums\ProjectType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                            wire:model.blur="type"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group name="manager_id" :label="__('Project manager')" optional>
                        <x-ui.form.select
                            name="manager_id"
                            :placeholder="__('Assign later')"
                            :options="$this->managers->pluck('name', 'id')->all()"
                            wire:model.blur="manager_id"
                        />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group
                    name="goal"
                    :label="__('Goal')"
                    :hint="__('The change this project is meant to produce — not the works themselves.')"
                    optional
                >
                    <x-ui.form.textarea name="goal" rows="2" maxlength="2000" has-hint wire:model.blur="goal" />
                </x-ui.form.group>

                <x-ui.form.group name="objectives" :label="__('Objectives')" optional>
                    <x-ui.form.textarea
                        name="objectives"
                        rows="3"
                        maxlength="5000"
                        :placeholder="__('One objective per line.')"
                        wire:model.blur="objectives"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="description"
                    :label="__('Scope description')"
                    :hint="__('Summarise the works, goods or services. The contract carries the full scope.')"
                    optional
                >
                    <x-ui.form.textarea name="description" rows="4" maxlength="5000" has-hint wire:model.blur="description" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="supervising_agency_name"
                    :label="__('Supervising agency')"
                    :hint="__('Only where an outside body supervises, e.g. a federal project implementation unit or a donor.')"
                    optional
                >
                    <x-ui.form.input name="supervising_agency_name" has-hint wire:model.blur="supervising_agency_name" />
                </x-ui.form.group>
            </div>
        @endif

        {{-- ------------------------------------------------------------ --}}
        {{-- Step 2 — funding & budget                                     --}}
        {{-- ------------------------------------------------------------ --}}
        @if ($step === 2)
            <div class="space-y-5 border-t border-line pt-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group
                        name="budget_allocation"
                        :label="__('Budget allocation')"
                        :hint="__('The appropriation for this project. The contract sum is recorded separately when a contract is awarded.')"
                        optional
                    >
                        <x-ui.form.input
                            name="budget_allocation"
                            type="number"
                            step="0.01"
                            min="0"
                            prefix="₦"
                            has-hint
                            wire:model.blur="budget_allocation"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="budget_code"
                        :label="__('Budget code')"
                        :hint="__('Finance system line, where you have it.')"
                        optional
                    >
                        <x-ui.form.input name="budget_code" has-hint wire:model.blur="budget_code" />
                    </x-ui.form.group>
                </div>

                <fieldset class="border-t border-line pt-5">
                    <legend class="text-sm font-semibold text-ink">{{ __('Funding sources') }}</legend>
                    <p class="mt-1 text-sm text-ink-muted">
                        {{ __('Add every source paying for this project — donor and counterpart funding are recorded separately, never merged. The first row is treated as the primary source.') }}
                    </p>

                    <x-ui.form.error name="funding" class="mt-2" />

                    <div class="mt-4 space-y-3">
                        @foreach ($funding as $index => $row)
                            <div class="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end" wire:key="funding-{{ $index }}">
                                <x-ui.form.group :name="'funding.'.$index.'.funding_source_id'" :label="__('Source')">
                                    <x-ui.form.select
                                        :name="'funding.'.$index.'.funding_source_id'"
                                        :placeholder="__('Choose a source')"
                                        :options="$this->fundingSources->pluck('name', 'id')->all()"
                                        wire:model.blur="funding.{{ $index }}.funding_source_id"
                                    />
                                </x-ui.form.group>

                                <x-ui.form.group :name="'funding.'.$index.'.percentage'" :label="__('Share')" class="sm:w-28">
                                    <x-ui.form.input
                                        :name="'funding.'.$index.'.percentage'"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        suffix="%"
                                        wire:model.blur="funding.{{ $index }}.percentage"
                                    />
                                </x-ui.form.group>

                                <x-ui.form.group :name="'funding.'.$index.'.amount'" :label="__('Amount')" class="sm:w-44">
                                    <x-ui.form.input
                                        :name="'funding.'.$index.'.amount'"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        prefix="₦"
                                        wire:model.blur="funding.{{ $index }}.amount"
                                    />
                                </x-ui.form.group>

                                <div class="sm:pb-1">
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        icon-only
                                        wire:click="removeFundingRow({{ $index }})"
                                    >{{ __('Remove this funding source') }}</x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <x-ui.button variant="secondary" size="sm" icon="plus" wire:click="addFundingRow">
                            {{ __('Add another source') }}
                        </x-ui.button>

                        @php $total = $this->fundingPercentageTotal(); @endphp
                        @if ($total > 0)
                            <p @class([
                                'text-sm tabular-nums',
                                'text-critical-ink font-medium' => $total > 100,
                                'text-ink-muted' => $total <= 100,
                            ])>
                                {{ __('Shares total :total%', ['total' => rtrim(rtrim(number_format($total, 2), '0'), '.')]) }}
                            </p>
                        @endif
                    </div>
                </fieldset>
            </div>
        @endif

        {{-- ------------------------------------------------------------ --}}
        {{-- Step 3 — location & schedule                                  --}}
        {{-- ------------------------------------------------------------ --}}
        @if ($step === 3)
            <div class="space-y-5 border-t border-line pt-5">
                <div>
                    <h2 class="text-sm font-semibold text-ink">{{ __('Primary site') }}</h2>
                    <p class="mt-1 text-sm text-ink-muted">
                        {{ __('Where the work happens. Additional sites can be added to the project afterwards — multi-site projects are counted in every LGA they touch.') }}
                    </p>
                </div>

                <x-ui.form.group
                    name="site_name"
                    :label="__('Site name')"
                    :hint="__('e.g. “Ward 3 Primary Health Centre” or “Km 4–7 alignment”.')"
                    optional
                >
                    <x-ui.form.input name="site_name" has-hint wire:model.blur="site_name" />
                </x-ui.form.group>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group name="lga_id" :label="__('LGA')" optional>
                        <x-ui.form.select
                            name="lga_id"
                            :placeholder="__('Choose an LGA')"
                            :options="$this->lgas->pluck('name', 'id')->all()"
                            wire:model.live="lga_id"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="ward_id"
                        :label="__('Ward')"
                        :hint="$lga_id === '' ? __('Choose an LGA first.') : null"
                        optional
                    >
                        <x-ui.form.select
                            name="ward_id"
                            :placeholder="$lga_id === '' ? __('Choose an LGA first') : __('Choose a ward')"
                            :options="$this->wards->pluck('name', 'id')->all()"
                            :has-hint="$lga_id === ''"
                            :disabled="$lga_id === ''"
                            wire:model.blur="ward_id"
                        />
                    </x-ui.form.group>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group
                        name="latitude"
                        :label="__('Latitude')"
                        :hint="__('Decimal degrees, e.g. 9.0765. Field monitors capture this on site.')"
                        optional
                    >
                        <x-ui.form.input name="latitude" type="number" step="0.0000001" has-hint wire:model.blur="latitude" />
                    </x-ui.form.group>

                    <x-ui.form.group name="longitude" :label="__('Longitude')" optional>
                        <x-ui.form.input name="longitude" type="number" step="0.0000001" wire:model.blur="longitude" />
                    </x-ui.form.group>
                </div>

                <div class="grid gap-5 border-t border-line pt-5 sm:grid-cols-2">
                    <x-ui.form.group name="start_date" :label="__('Planned start date')" optional>
                        <x-ui.form.input name="start_date" type="date" wire:model.blur="start_date" />
                    </x-ui.form.group>

                    <x-ui.form.group name="expected_end_date" :label="__('Expected completion date')" optional>
                        <x-ui.form.input name="expected_end_date" type="date" wire:model.blur="expected_end_date" />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group
                    name="reporting_frequency"
                    :label="__('Reporting frequency')"
                    :hint="__('How often progress reports are due. Leave blank to use this instance’s default.')"
                    optional
                >
                    <x-ui.form.select
                        name="reporting_frequency"
                        :placeholder="__('Use the default')"
                        :options="collect(\App\Enums\MeasurementFrequency::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                        has-hint
                        wire:model.blur="reporting_frequency"
                    />
                </x-ui.form.group>
            </div>
        @endif

        {{-- ------------------------------------------------------------ --}}
        {{-- Wizard controls                                               --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="mt-6 flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-center">
            <div class="flex flex-wrap gap-2">
                @if ($step > 1)
                    <x-ui.button variant="secondary" icon="arrow-left" wire:click="back">{{ __('Back') }}</x-ui.button>
                @endif

                @if ($step < 3)
                    <x-ui.button trailing-icon="arrow-right" wire:click="next" loading="next">{{ __('Continue') }}</x-ui.button>
                @else
                    <x-ui.button icon="check-circle" wire:click="save" loading="save" x-on:click="dirty = false">
                        {{ __('Register project') }}
                    </x-ui.button>
                @endif
            </div>

            <p class="text-xs text-ink-muted sm:ml-auto">
                <span wire:loading.remove wire:target="saveDraft,next,back">
                    @if ($draftSavedAt)
                        <span class="inline-flex items-center gap-1">
                            <x-ui.icon name="check-circle" class="size-3.5 text-positive" />
                            {{ __('Draft saved automatically') }}
                        </span>
                    @else
                        {{ __('Your progress saves automatically as you type.') }}
                    @endif
                </span>
                <span wire:loading wire:target="saveDraft,next,back" class="inline-flex items-center gap-1">
                    <x-ui.icon name="arrow-path" class="size-3.5 animate-spin" />
                    {{ __('Saving…') }}
                </span>
            </p>
        </div>
    </x-ui.card>
</div>
