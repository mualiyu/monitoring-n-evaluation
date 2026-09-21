{{--
    Record a contract (App\Livewire\Tenant\Projects\ContractCreate).

    One page, two paths. The path selector comes FIRST and drives what the rest
    of the form asks for, so an officer never fills an award form only to find
    they were meant to be recording a variation.

    The variation panel shows the original's contractor and category as stated
    facts rather than disabled inputs: RecordContractVariation copies them from
    the award, and a field that cannot be changed and is not read is noise.
--}}
@php
    $project = $this->project;
    $original = $this->variedContract;
    $isVariation = $this->isVariation();
    $canVary = $this->headContracts->isNotEmpty();
@endphp

<div
    x-data="{ dirty: false }"
    x-on:beforeunload.window="if (dirty) $event.preventDefault()"
    x-on:input="dirty = true"
>
    <x-ui.page-header
        :title="$isVariation ? __('Record a contract variation') : __('Record a contract award')"
        :description="$project->title"
        :back="$this->projectUrl"
        :back-label="__('Back to project')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => $this->projectsUrl],
            ['label' => $project->reference, 'href' => $this->projectUrl],
            ['label' => __('New contract')],
        ]"
    />

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That contract was not recorded')">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-5">
        {{-- ---------------------------------------------------------- --}}
        {{-- Which of the two instruments is being recorded              --}}
        {{-- ---------------------------------------------------------- --}}
        <x-ui.card
            :title="__('What are you recording?')"
            :subtitle="__('An award commits the project to a firm. A variation amends an award that already exists — the original figures are never edited.')"
        >
            <div class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.form.group name="mode" :label="__('Instrument')" required>
                        <x-ui.form.select
                            name="mode"
                            :options="$canVary
                                ? [
                                    \App\Livewire\Tenant\Projects\ContractCreate::MODE_AWARD => __('A new contract award'),
                                    \App\Livewire\Tenant\Projects\ContractCreate::MODE_VARIATION => __('A variation on an existing contract'),
                                ]
                                : [\App\Livewire\Tenant\Projects\ContractCreate::MODE_AWARD => __('A new contract award')]"
                            wire:model.live="mode"
                        />
                    </x-ui.form.group>

                    @if ($isVariation)
                        <x-ui.form.group
                            name="variesUlid"
                            :label="__('Contract being varied')"
                            :hint="__('Variations attach to the award itself, never to another variation.')"
                            required
                        >
                            <x-ui.form.select
                                name="variesUlid"
                                :placeholder="__('Choose a contract')"
                                :options="$this->contractOptions"
                                has-hint
                                wire:model.live="variesUlid"
                            />
                        </x-ui.form.group>
                    @endif
                </div>

                @unless ($canVary)
                    <p class="flex items-start gap-1.5 text-sm text-ink-muted">
                        <x-ui.icon name="information-circle" class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        <span>{{ __('This project has no contract yet, so there is nothing to vary. Record the award first.') }}</span>
                    </p>
                @endunless

                @if ($isVariation && $original)
                    {{-- Carried from the award, stated rather than offered. --}}
                    <dl class="grid gap-4 rounded-xl bg-surface-sunken px-4 py-3 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Contractor') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $original->contractor?->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Original award sum') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $original->sum->format() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Value today') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $original->revisedValue()->format() }}</dd>
                        </div>
                    </dl>
                @endif
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------- --}}
        {{-- Variation                                                    --}}
        {{-- ---------------------------------------------------------- --}}
        @if ($isVariation)
            <x-ui.card
                :title="__('Variation instrument')"
                :subtitle="__('Recorded as its own entry in the amendment register, so the superseded figures stay readable.')"
            >
                <div class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.form.group
                            name="contractNumber"
                            :label="__('Variation number')"
                            :hint="__('The number on the variation order — unique in this workspace.')"
                            required
                        >
                            <x-ui.form.input name="contractNumber" has-hint maxlength="60" wire:model.blur="contractNumber" />
                        </x-ui.form.group>

                        <x-ui.form.group name="awardDate" :label="__('Date of the variation')" required>
                            <x-ui.form.input name="awardDate" type="date" wire:model.blur="awardDate" />
                        </x-ui.form.group>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.form.group
                            name="variationDirection"
                            :label="__('Variation type')"
                            :hint="__('A downward variation (an omission) is recorded the same way — it lowers the contract value.')"
                            required
                        >
                            <x-ui.form.select
                                name="variationDirection"
                                :options="[
                                    \App\Livewire\Tenant\Projects\ContractCreate::DIRECTION_INCREASE => __('Additional works — increases the value'),
                                    \App\Livewire\Tenant\Projects\ContractCreate::DIRECTION_DECREASE => __('Omission — reduces the value'),
                                ]"
                                has-hint
                                wire:model.live="variationDirection"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="variationAmount"
                            :label="__('Amount of the variation')"
                            :hint="__('The change only — not the new contract total.')"
                            required
                        >
                            <x-ui.form.input
                                name="variationAmount"
                                type="number"
                                step="0.01"
                                min="0"
                                :prefix="$this->currencySymbol"
                                has-hint
                                wire:model.blur="variationAmount"
                            />
                        </x-ui.form.group>
                    </div>

                    <x-ui.form.group name="expectedCompletionDate" :label="__('Revised completion date')" optional>
                        <x-ui.form.input name="expectedCompletionDate" type="date" wire:model.blur="expectedCompletionDate" />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="scopeOfWorks"
                        :label="__('Varied works')"
                        :hint="__('What this instrument adds or removes, as written on the variation order.')"
                        required
                    >
                        <x-ui.form.textarea name="scopeOfWorks" rows="4" maxlength="10000" has-hint wire:model.blur="scopeOfWorks" />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="variationReason"
                        :label="__('Reason for the variation')"
                        :hint="__('Required and permanent. A variation without a stated reason is an unexplained change in public money.')"
                        required
                    >
                        <x-ui.form.textarea
                            name="variationReason"
                            rows="3"
                            maxlength="2000"
                            has-hint
                            :placeholder="__('e.g. Additional drainage arising from ground conditions found at Km 3.')"
                            wire:model.blur="variationReason"
                        />
                    </x-ui.form.group>
                </div>
            </x-ui.card>
        @else
            {{-- ------------------------------------------------------ --}}
            {{-- Award                                                    --}}
            {{-- ------------------------------------------------------ --}}
            <x-ui.card
                :title="__('Award terms')"
                :subtitle="__('The award sum, date, contractor and scope cannot be edited afterwards — later changes are recorded as variations.')"
            >
                <div class="space-y-5">
                    <x-ui.form.group
                        name="contractorId"
                        :label="__('Contractor')"
                        :hint="__('Blacklisted firms are not listed. The registry is state-wide, so a firm barred by one entity cannot be engaged here.')"
                        required
                    >
                        <x-ui.form.select
                            name="contractorId"
                            :placeholder="__('Choose a contractor')"
                            :options="$this->contractorOptions"
                            has-hint
                            wire:model.blur="contractorId"
                        />
                    </x-ui.form.group>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.form.group
                            name="contractNumber"
                            :label="__('Contract number')"
                            :hint="__('Unique in this workspace.')"
                            required
                        >
                            <x-ui.form.input name="contractNumber" has-hint maxlength="60" wire:model.blur="contractNumber" />
                        </x-ui.form.group>

                        <x-ui.form.group name="contractType" :label="__('Contract type')" required>
                            <x-ui.form.select
                                name="contractType"
                                :options="collect(\App\Enums\ContractType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                                wire:model.blur="contractType"
                            />
                        </x-ui.form.group>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.form.group name="contractSum" :label="__('Award sum')" required>
                            <x-ui.form.input
                                name="contractSum"
                                type="number"
                                step="0.01"
                                min="0"
                                :prefix="$this->currencySymbol"
                                wire:model.blur="contractSum"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group name="awardDate" :label="__('Award date')" required>
                            <x-ui.form.input name="awardDate" type="date" wire:model.blur="awardDate" />
                        </x-ui.form.group>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-ui.form.group name="commencementDate" :label="__('Commencement date')" optional>
                            <x-ui.form.input name="commencementDate" type="date" wire:model.blur="commencementDate" />
                        </x-ui.form.group>

                        <x-ui.form.group name="durationDays" :label="__('Duration (days)')" optional>
                            <x-ui.form.input name="durationDays" type="number" min="1" max="65535" wire:model.blur="durationDays" />
                        </x-ui.form.group>

                        <x-ui.form.group name="expectedCompletionDate" :label="__('Expected completion')" optional>
                            <x-ui.form.input name="expectedCompletionDate" type="date" wire:model.blur="expectedCompletionDate" />
                        </x-ui.form.group>
                    </div>

                    <x-ui.form.group
                        name="retentionPercentage"
                        :label="__('Retention')"
                        :hint="__('Percentage withheld until the defects liability period ends.')"
                        optional
                    >
                        <x-ui.form.input
                            name="retentionPercentage"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            suffix="%"
                            has-hint
                            wire:model.blur="retentionPercentage"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="scopeOfWorks"
                        :label="__('Scope of works')"
                        :hint="__('Quoted verbatim in the commencement notice, so write it as it appears in the contract.')"
                        required
                    >
                        <x-ui.form.textarea name="scopeOfWorks" rows="5" maxlength="10000" has-hint wire:model.blur="scopeOfWorks" />
                    </x-ui.form.group>
                </div>
            </x-ui.card>
        @endif

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
            <x-ui.button variant="ghost" :href="$this->projectUrl">{{ __('Cancel') }}</x-ui.button>

            <x-ui.button type="submit" icon="check-circle" loading="save">
                {{ $isVariation ? __('Record variation') : __('Record award') }}
            </x-ui.button>
        </div>

        <p wire:loading wire:target="save" class="text-right text-xs text-ink-muted">
            {{ __('Saving…') }}
        </p>
    </form>
</div>
