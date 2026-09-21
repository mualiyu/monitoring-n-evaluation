{{--
    The field form (App\Livewire\Tenant\Inspections\InspectionConduct).

    Built for a monitor standing on a building site with a cheap Android and
    3G that comes and goes. Single column at every width — a two-column form at
    360px is two columns of nothing — large touch targets, and no save button:
    every field autosaves, so a dropped connection costs one field, not an hour.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $inspection = $this->record;
    $previous = $this->previousVisit;
    $editable = $inspection->isEditable();
@endphp

<div class="mx-auto max-w-3xl">
    <x-ui.page-header
        :title="__('Conduct: :type', ['type' => $inspection->type->label()])"
        :description="$inspection->project->title.' · '.$inspection->project->reference"
        :back="route('tenant.inspections.show', [...$workspace, 'inspection' => $inspection])"
        :back-label="__('Back to the visit')"
    />

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be saved')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    @unless ($editable)
        <x-ui.alert variant="neutral" :title="__('This report is filed')" class="mb-4">
            {{ __('Findings, checklist and evidence freeze at submission — evidence that can change after it has been read is not evidence.') }}
        </x-ui.alert>
    @endunless

    {{-- ---------------------------------------------------------------- --}}
    {{-- Autosave state, stated plainly and always visible                 --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($editable)
        <div
            class="sticky top-0 z-10 mb-4 flex items-center gap-2 rounded-xl border border-line bg-surface-raised px-4 py-2.5 text-xs shadow-e1"
            role="status"
            aria-live="polite"
        >
            <span wire:loading.delay.flex class="hidden items-center gap-2 text-ink-muted">
                <x-ui.icon name="arrow-path" class="size-4 animate-spin" />
                {{ __('Saving…') }}
            </span>

            <span wire:loading.delay.remove class="flex items-center gap-2 text-ink-muted">
                @if ($savedAt)
                    <x-ui.icon name="check-circle" class="size-4 text-positive" />
                    {{ __('Saved automatically. You can close this and come back.') }}
                @else
                    <x-ui.icon name="information-circle" class="size-4" />
                    {{ __('Everything you type is saved as you go — there is no save button.') }}
                @endif
            </span>
        </div>
    @endif

    <div class="space-y-4">
        {{-- ------------------------------------------------------------ --}}
        {{-- Where you are                                                 --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card
            :title="__('Where you are')"
            :subtitle="__('Recorded once, with the report. It is evidence of attendance, not surveillance.')"
        >
            <div
                x-data="{
                    asking: false,
                    /*
                        Best-effort by design. The browser may refuse, the device
                        may have no fix, and a monitor under a concrete slab will
                        get nothing at all — so every failure path calls back into
                        the component and the form stays usable either way. A form
                        that blocks on a position cannot be filled at the one place
                        it is meant to be filled.
                    */
                    capture() {
                        if (! navigator.geolocation) {
                            $wire.positionUnavailable(@js(__('This device has no location service. The report can still be filed.')));
                            return;
                        }

                        this.asking = true;

                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                this.asking = false;
                                $wire.capturePosition(
                                    position.coords.latitude,
                                    position.coords.longitude,
                                    position.coords.accuracy,
                                );
                            },
                            (error) => {
                                this.asking = false;
                                $wire.positionUnavailable(
                                    error.code === error.PERMISSION_DENIED
                                        ? @js(__('Location permission was declined. The report can still be filed — say in the findings where you were.'))
                                        : '',
                                );
                            },
                            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                        );
                    },
                }"
            >
                @if ($latitude)
                    <p class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                        <x-ui.badge status="on_track" icon="map-pin" size="sm" :label="__('Position recorded')" />
                        <span class="font-mono text-xs text-ink-muted">
                            {{ $latitude }}, {{ $longitude }}@if ($accuracy) (±{{ $accuracy }} m)@endif
                        </span>
                    </p>

                    @if ($inspection->geofence_breached)
                        <x-ui.alert variant="warning" class="mb-3">
                            {{ __('That is :distance m from this project’s registered site. Recorded and flagged for the reviewer.', ['distance' => number_format((float) $inspection->geofence_distance_metres)]) }}
                        </x-ui.alert>
                    @endif
                @endif

                @if ($positionError)
                    <x-ui.alert variant="neutral" class="mb-3">{{ $positionError }}</x-ui.alert>
                @endif

                @if ($editable)
                    <x-ui.button
                        variant="secondary"
                        icon="map-pin"
                        x-on:click="capture()"
                        x-bind:disabled="asking"
                    >
                        <span x-show="! asking">{{ $latitude ? __('Update my position') : __('Record my position') }}</span>
                        <span x-show="asking" x-cloak>{{ __('Getting a fix…') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Checklist                                                     --}}
        {{-- ------------------------------------------------------------ --}}
        @if ($this->items->isNotEmpty())
            <x-ui.card
                :title="__('Checklist')"
                :subtitle="$inspection->template?->name"
            >
                @if ($this->unansweredRequired > 0)
                    <p class="mb-4 flex items-start gap-1.5 text-xs text-ink-muted">
                        <x-ui.icon name="information-circle" class="mt-0.5 size-4 shrink-0" />
                        <span>{{ trans_choice(
                            '{1} :count required question still to answer.|[2,*] :count required questions still to answer.',
                            $this->unansweredRequired,
                            ['count' => $this->unansweredRequired],
                        ) }}</span>
                    </p>
                @endif

                <ol class="space-y-6">
                    @foreach ($this->items as $item)
                        @php
                            $field = 'answers.'.$item->id;
                            $isFinding = ($item->response_type === $responseTypes::YesNo && ($answers[$item->id] ?? null) === false)
                                || ($item->finding_on_no
                                    && $item->finding_threshold !== null
                                    && is_numeric($answers[$item->id] ?? null)
                                    && (float) $answers[$item->id] <= (float) $item->finding_threshold);
                        @endphp

                        <li wire:key="item-{{ $item->id }}" class="border-b border-line pb-6 last:border-0 last:pb-0">
                            <x-ui.form.group
                                :name="$field"
                                :label="$item->prompt"
                                :hint="$item->guidance"
                                :required="$item->is_required"
                            >
                                @switch($item->response_type)
                                    @case($responseTypes::YesNo)
                                        {{-- Radios, not a select: two large targets
                                             beat a dropdown for a gloved thumb. --}}
                                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ $item->prompt }}">
                                            @foreach ([['1', __('Yes'), 'check-circle'], ['0', __('No'), 'x-circle']] as [$value, $label, $icon])
                                                <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-lg border border-line bg-surface-raised px-4 text-sm font-medium text-ink has-[:checked]:border-brand has-[:checked]:bg-brand-soft has-[:checked]:text-brand-ink has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-focus">
                                                    <input
                                                        type="radio"
                                                        class="sr-only"
                                                        name="answer-{{ $item->id }}"
                                                        value="{{ $value }}"
                                                        @disabled(! $editable)
                                                        wire:model.live="answers.{{ $item->id }}"
                                                    />
                                                    <x-ui.icon :name="$icon" class="size-4" />
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                        @break

                                    @case($responseTypes::Rating)
                                        <x-ui.form.select
                                            :name="$field"
                                            :placeholder="__('Not rated')"
                                            :options="collect(range(1, $item->rating_scale ?? 5))->mapWithKeys(fn ($n) => [(string) $n => (string) $n])->all()"
                                            :disabled="! $editable"
                                            wire:model.live="answers.{{ $item->id }}"
                                        />
                                        @break

                                    @case($responseTypes::Numeric)
                                        <x-ui.form.input
                                            :name="$field"
                                            type="number"
                                            inputmode="decimal"
                                            step="0.01"
                                            min="0"
                                            :suffix="$item->unit"
                                            :disabled="! $editable"
                                            wire:model.live.debounce.500ms="answers.{{ $item->id }}"
                                        />
                                        @break

                                    @default
                                        <x-ui.form.textarea
                                            :name="$field"
                                            rows="2"
                                            maxlength="2000"
                                            :disabled="! $editable"
                                            wire:model.live.debounce.500ms="answers.{{ $item->id }}"
                                        />
                                @endswitch
                            </x-ui.form.group>

                            @if ($isFinding || filled($notes[$item->id] ?? null))
                                <div class="mt-3">
                                    <x-ui.form.group
                                        :name="'notes.'.$item->id"
                                        :label="__('What exactly is wrong?')"
                                        :hint="__('Required for a finding — a flag with no explanation cannot be acted on by anyone.')"
                                        required
                                    >
                                        <x-ui.form.textarea
                                            :name="'notes.'.$item->id"
                                            rows="2"
                                            maxlength="2000"
                                            has-hint
                                            :disabled="! $editable"
                                            wire:model.live.debounce.500ms="notes.{{ $item->id }}"
                                        />
                                    </x-ui.form.group>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        @endif

        {{-- ------------------------------------------------------------ --}}
        {{-- What you saw                                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('What you saw')">
            <div class="space-y-4">
                <x-ui.form.group
                    name="physicalProgressObserved"
                    :label="__('Physical progress observed (%)')"
                    :hint="__('The project record currently says :value%. Your figure is an observation, not an edit to that number.', ['value' => $inspection->project->physical_progress])"
                >
                    <x-ui.form.input
                        name="physicalProgressObserved"
                        type="number"
                        inputmode="decimal"
                        step="0.01"
                        min="0"
                        max="100"
                        suffix="%"
                        has-hint
                        :disabled="! $editable"
                        wire:model.live.debounce.500ms="physicalProgressObserved"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="outcome"
                    :label="__('Verdict on the site')"
                    :hint="__('“Major issues” and “work stopped” reach the MDA administrator the moment you file — use them when you mean them.')"
                    required
                >
                    <x-ui.form.select
                        name="outcome"
                        has-hint
                        :placeholder="__('Choose a verdict…')"
                        :options="$this->outcomeOptions"
                        :disabled="! $editable"
                        wire:model.live="outcome"
                    />
                </x-ui.form.group>

                {{-- tryFrom, not from: this reads the RAW property, which a
                     Livewire endpoint lets a client set to anything. A value
                     that is not a verdict must render no banner, not throw
                     mid-view and cost the inspector the form. --}}
                @php($chosenOutcome = \App\Enums\InspectionOutcome::tryFrom($outcome))

                @if ($chosenOutcome?->requiresEscalation())
                    <x-ui.alert variant="warning" :title="__('This verdict escalates')">
                        {{ $chosenOutcome->description() }}
                    </x-ui.alert>
                @endif

                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-ink">{{ __('Risks observed') }}</legend>
                    <p class="mb-2 text-xs text-ink-muted">
                        {{ __('Tick everything that applies. These are the words the state counts — two inspectors describing the same problem should pick the same one.') }}
                    </p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->riskFlagOptions as $value => $label)
                            <x-ui.form.checkbox
                                :name="'riskFlags.'.$value"
                                :label="$label"
                                :value="$value"
                                :disabled="! $editable"
                                wire:model.live="riskFlags"
                            />
                        @endforeach
                    </div>
                </fieldset>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Photographic evidence                                         --}}
        {{-- ------------------------------------------------------------ --}}
        @if ($this->requiresPhoto && $this->photoCount === 0)
            <x-ui.alert variant="warning" :title="__('A photograph is required')">
                {{ __('This instance requires at least one photograph before a report may be filed. A site visit with no image of the site is an assertion.') }}
            </x-ui.alert>
        @endif

        <livewire:shared.document-panel
            :model="$inspection"
            collection="inspection_photos"
            :readonly="! $editable"
            :heading="__('Photographic evidence')"
            :key="'conduct-photos-'.$inspection->ulid"
        />

        {{-- ------------------------------------------------------------ --}}
        {{-- Field Trip Report                                             --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card
            :title="__('Field Trip Report')"
            :subtitle="__('The seven sections every field visit must produce')"
        >
            <div class="space-y-4">
                <x-ui.form.group name="objectives" :label="__('1. Objectives')">
                    <x-ui.form.textarea name="objectives" rows="2" maxlength="2000" :disabled="! $editable" wire:model.blur="objectives" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="peopleMet"
                    :label="__('2. People and groups met')"
                    :hint="__('Everyone you spoke to, including people who are not platform users.')"
                >
                    <x-ui.form.textarea name="peopleMet" rows="2" maxlength="5000" has-hint :disabled="! $editable" wire:model.blur="peopleMet" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="methods"
                    :label="__('3. Methods used')"
                    :hint="__('Measurement, observation, interview, photographic record.')"
                >
                    <x-ui.form.textarea name="methods" rows="2" maxlength="5000" has-hint :disabled="! $editable" wire:model.blur="methods" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="findings"
                    :label="__('4. Findings')"
                    :hint="__('The section everyone reads. What is actually on the ground.')"
                    required
                >
                    <x-ui.form.textarea name="findings" rows="5" maxlength="10000" has-hint :disabled="! $editable" wire:model.blur="findings" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="comparisonWithPrevious"
                    :label="__('5. Comparison with previous visits')"
                    :hint="$previous
                        ? __('Last visit: :type on :date — :outcome', [
                            'type' => $previous->type->label(),
                            'date' => ($previous->conducted_at ?? $previous->scheduled_date)->translatedFormat('j M Y'),
                            'outcome' => $previous->outcome?->label() ?? __('no verdict recorded'),
                        ])
                        : __('No earlier inspection of this project is on record.')"
                >
                    <x-ui.form.textarea name="comparisonWithPrevious" rows="3" maxlength="5000" has-hint :disabled="! $editable" wire:model.blur="comparisonWithPrevious" />
                </x-ui.form.group>

                <x-ui.form.group name="conclusions" :label="__('6. Conclusions')">
                    <x-ui.form.textarea name="conclusions" rows="3" maxlength="5000" :disabled="! $editable" wire:model.blur="conclusions" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="recommendations"
                    :label="__('7. Recommendations for action')"
                    :hint="__('What should happen next, and who should do it.')"
                >
                    <x-ui.form.textarea name="recommendations" rows="3" maxlength="5000" has-hint :disabled="! $editable" wire:model.blur="recommendations" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="team"
                    :label="__('Who attended')"
                    :hint="__('Names and designations of the visiting team.')"
                >
                    <x-ui.form.textarea name="team" rows="2" maxlength="1000" has-hint :disabled="! $editable" wire:model.blur="team" />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- File it                                                       --}}
        {{-- ------------------------------------------------------------ --}}
        @if ($editable)
            <x-ui.card>
                <p class="mb-3 text-sm text-ink-muted">
                    {{ __('Filing freezes the report, the checklist and the photographs, and sends it to the M&E office. You cannot sign off your own visit.') }}
                </p>

                <x-ui.button
                    icon="paper-airplane"
                    class="w-full sm:w-auto"
                    wire:click="submit"
                    loading="submit"
                >{{ __('File the Field Trip Report') }}</x-ui.button>
            </x-ui.card>
        @endif
    </div>
</div>
