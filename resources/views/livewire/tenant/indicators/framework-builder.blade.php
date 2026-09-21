{{--
    Logframe builder (App\Livewire\Tenant\Indicators\FrameworkBuilder).

    Impact → outcome → output, three tiers rendered explicitly because the enum
    guarantees the tree is exactly three deep. Indicators hang off the
    statement they measure, each showing its standing so the framework reads as
    a picture of delivery rather than a filing cabinet.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $indicatorUrl = fn ($indicator) => route('tenant.indicators.show', [...$workspace, 'indicator' => $indicator]);

    $canManage = auth()->user()?->can('create', \App\Models\ResultFramework::class) ?? false;
    $canAddIndicator = auth()->user()?->can('create', \App\Models\Indicator::class) ?? false;

    $levels = \App\Enums\FrameworkLevel::class;
@endphp

<div>
    <x-ui.page-header
        :title="__('Results framework')"
        :description="__('What this project is trying to change, and how anyone would know. Impact sits at the top, served by outcomes, delivered through outputs — each measured by indicators drawn from the state library wherever one fits.')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => route('tenant.projects.index', $workspace)],
            ['label' => $project->title, 'href' => route('tenant.projects.show', [...$workspace, 'project' => $project])],
            ['label' => __('Results framework')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="chart-bar"
                :href="route('tenant.indicators.index', [...$workspace, 'project' => $project->ulid])"
            >{{ __('Indicator register') }}</x-ui.button>

            @if ($canManage)
                <x-ui.button size="sm" icon="plus" wire:click="startStatement" loading="startStatement">
                    {{ __('Add an impact statement') }}
                </x-ui.button>
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

    @if ($this->tree === [])
        <x-ui.card flush>
            <x-ui.empty-state
                icon="flag"
                :title="__('No results framework yet')"
                :description="__('Start with the impact this project exists to bring about — the long-term change, not the works. Outcomes and outputs hang beneath it, and indicators hang off those.')"
            >
                <x-slot:actions>
                    @if ($canManage)
                        <x-ui.button icon="plus" wire:click="startStatement">
                            {{ __('Add an impact statement') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="space-y-4">
            @foreach ($this->tree as $impact)
                <x-ui.card wire:key="impact-{{ $impact['statement']->ulid }}" flush>
                    <div class="space-y-4 p-4 sm:p-5">
                        {{-- Impact --}}
                        <x-indicators.statement
                            :statement="$impact['statement']"
                            :can-manage="$canManage"
                            :can-add-indicator="$canAddIndicator"
                            :indicator-url="$indicatorUrl"
                            :add-child-label="__('Add an outcome')"
                        />

                        {{-- Outcomes --}}
                        <div class="space-y-3 border-l-2 border-line pl-4 sm:pl-6">
                            @forelse ($impact['children'] as $outcome)
                                <div wire:key="outcome-{{ $outcome['statement']->ulid }}" class="space-y-3">
                                    <x-indicators.statement
                                        :statement="$outcome['statement']"
                                        :can-manage="$canManage"
                                        :can-add-indicator="$canAddIndicator"
                                        :indicator-url="$indicatorUrl"
                                        :add-child-label="__('Add an output')"
                                    />

                                    {{-- Outputs --}}
                                    <div class="space-y-3 border-l-2 border-line pl-4 sm:pl-6">
                                        @forelse ($outcome['children'] as $output)
                                            <x-indicators.statement
                                                wire:key="output-{{ $output['statement']->ulid }}"
                                                :statement="$output['statement']"
                                                :can-manage="$canManage"
                                                :can-add-indicator="$canAddIndicator"
                                                :indicator-url="$indicatorUrl"
                                            />
                                        @empty
                                            <p class="text-sm text-ink-muted">
                                                {{ __('No outputs yet — what will actually be handed over?') }}
                                            </p>
                                        @endforelse
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-ink-muted">
                                    {{ __('No outcomes yet — what change should this impact be reached through?') }}
                                </p>
                            @endforelse
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Add / edit a result statement                                     --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canManage)
        <x-ui.modal
            name="result-statement"
            :title="__('Result statement')"
            :description="__('One sentence naming the change, plus the assumption it rests on — the condition outside this entity’s control that has to hold for the result below it to produce this one.')"
            max-width="lg"
        >
            <x-ui.form.group
                name="statementLevel"
                :label="__('Level')"
                :hint="__('A logframe runs impact → outcome → output. The level follows from where the statement sits.')"
            >
                <x-ui.form.select
                    name="statementLevel"
                    has-hint
                    disabled
                    :options="collect($levels::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()"
                    :selected="$statementLevel"
                />
            </x-ui.form.group>

            <x-ui.form.group
                name="statementText"
                :label="__('Statement')"
                :hint="__('e.g. “Travel time between the three northern LGAs and the state capital is reduced.”')"
                class="mt-4"
                required
            >
                <x-ui.form.input name="statementText" type="text" maxlength="255" has-hint wire:model="statementText" />
            </x-ui.form.group>

            <x-ui.form.group
                name="statementCode"
                :label="__('Reference')"
                :hint="__('Optional — the numbering used in the printed framework, e.g. 1.2.')"
                class="mt-4"
            >
                <x-ui.form.input name="statementCode" type="text" maxlength="40" has-hint wire:model="statementCode" />
            </x-ui.form.group>

            <x-ui.form.group name="statementDescription" :label="__('Description')" class="mt-4">
                <x-ui.form.textarea name="statementDescription" rows="3" maxlength="2000" wire:model="statementDescription" />
            </x-ui.form.group>

            <x-ui.form.group
                name="statementAssumptions"
                :label="__('Assumptions')"
                :hint="__('What has to remain true outside this entity’s control.')"
                class="mt-4"
            >
                <x-ui.form.textarea name="statementAssumptions" rows="3" maxlength="2000" has-hint wire:model="statementAssumptions" />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="saveStatement" loading="saveStatement" icon="check">
                    {{ __('Save statement') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Attach an indicator                                               --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canAddIndicator)
        <x-ui.modal
            name="attach-indicator"
            :title="__('Add an indicator')"
            :description="__('Take it from the state library wherever one fits — that is what makes this entity’s figures comparable with every other. Define one locally only when the library has no entry, and it will be considered for the library at the next indicator review.')"
            max-width="lg"
        >
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-ink">{{ __('Where the indicator comes from') }}</legend>

                <div class="flex flex-wrap gap-2" role="group">
                    <x-ui.button
                        size="sm"
                        type="button"
                        :variant="$indicatorSource === 'library' ? 'primary' : 'secondary'"
                        icon="folder"
                        wire:click="$set('indicatorSource', 'library')"
                        :aria-pressed="$indicatorSource === 'library' ? 'true' : 'false'"
                    >{{ __('State library') }}</x-ui.button>

                    <x-ui.button
                        size="sm"
                        type="button"
                        :variant="$indicatorSource === 'local' ? 'primary' : 'secondary'"
                        icon="pencil-square"
                        wire:click="$set('indicatorSource', 'local')"
                        :aria-pressed="$indicatorSource === 'local' ? 'true' : 'false'"
                    >{{ __('Define locally') }}</x-ui.button>
                </div>
            </fieldset>

            <x-ui.form.group name="indicatorTier" :label="__('Tier')" class="mt-4" required>
                <x-ui.form.select
                    name="indicatorTier"
                    :options="$this->tierOptions"
                    wire:model="indicatorTier"
                />
            </x-ui.form.group>

            @if ($indicatorSource === 'library')
                <x-ui.form.group
                    name="definitionId"
                    :label="__('Library indicator')"
                    :hint="__('Its definition, unit, frequency and means of verification are copied onto this entity’s indicator; the baseline stays yours to measure.')"
                    class="mt-4"
                    required
                >
                    <x-ui.form.select
                        name="definitionId"
                        has-hint
                        :placeholder="__('Choose from the state library…')"
                        :options="$this->definitionOptions"
                        wire:model="definitionId"
                    />
                </x-ui.form.group>

                @if ($this->definitionOptions === [])
                    <x-ui.alert variant="info" class="mt-3">
                        {{ __('The state library is empty. Define the indicator locally for now — state oversight can add it to the library later.') }}
                    </x-ui.alert>
                @endif
            @else
                <x-ui.form.group name="indicatorName" :label="__('Indicator name')" class="mt-4" required>
                    <x-ui.form.input name="indicatorName" type="text" maxlength="255" wire:model="indicatorName" />
                </x-ui.form.group>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <x-ui.form.group name="indicatorUnit" :label="__('Unit')" required>
                        <x-ui.form.select name="indicatorUnit" :options="$this->unitOptions" wire:model="indicatorUnit" />
                    </x-ui.form.group>

                    <x-ui.form.group name="indicatorFrequency" :label="__('Measured')" required>
                        <x-ui.form.select name="indicatorFrequency" :options="$this->frequencyOptions" wire:model="indicatorFrequency" />
                    </x-ui.form.group>

                    <x-ui.form.group name="indicatorTargetType" :label="__('Target type')" required>
                        <x-ui.form.select name="indicatorTargetType" :options="$this->targetTypeOptions" wire:model="indicatorTargetType" />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group name="indicatorDefinitionText" :label="__('Definition')" class="mt-4">
                    <x-ui.form.textarea name="indicatorDefinitionText" rows="3" maxlength="2000" wire:model="indicatorDefinitionText" />
                </x-ui.form.group>

                <x-ui.form.group name="indicatorDataSource" :label="__('Source of data')" class="mt-4">
                    <x-ui.form.input name="indicatorDataSource" type="text" maxlength="1000" wire:model="indicatorDataSource" />
                </x-ui.form.group>

                <x-ui.form.group name="indicatorVerification" :label="__('Means of verification')" class="mt-4">
                    <x-ui.form.input name="indicatorVerification" type="text" maxlength="1000" wire:model="indicatorVerification" />
                </x-ui.form.group>
            @endif

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="saveIndicator" loading="saveIndicator" icon="check">
                    {{ __('Add indicator') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
