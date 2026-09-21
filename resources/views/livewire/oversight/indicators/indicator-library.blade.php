{{--
    State indicator library (App\Livewire\Oversight\Indicators\IndicatorLibrary).

    The manual's predetermined indicator list: one wording, one unit, one
    frequency, used by every MDA — which is the only way the secretariat's
    annual consolidation produces comparable numbers.
--}}
@php
    $canManage = auth()->user()?->can('create', \App\Models\IndicatorDefinition::class) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Indicator library')"
        :description="__('The state-wide list every entity draws its indicators from. An entry defines what is measured, in what unit and how often; each entity supplies its own baseline and targets.')"
    >
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button size="sm" icon="plus" wire:click="startDefinition" loading="startDefinition">
                    {{ __('Add an indicator') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat :label="__('In circulation')" :value="number_format($this->stats['active'])" icon="folder" />
        <x-ui.stat :label="__('Retired')" :value="number_format($this->stats['retired'])" icon="pause-circle"
            :hint="__('kept readable so published figures keep their meaning')" />
        <x-ui.stat :label="__('Never instantiated')" :value="number_format($this->stats['unused'])" icon="question-mark-circle"
            :intent="$this->stats['unused'] > 0 ? 'warning' : 'neutral'"
            :hint="__('candidates for the next indicator review')" />
        <x-ui.stat :label="__('Total entries')" :value="number_format($this->stats['total'])" icon="squares" />
    </div>

    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Code, name or definition…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="sector" :label="__('Sector')">
                    <x-ui.form.select
                        name="sector"
                        :placeholder="__('All sectors')"
                        :options="$this->sectorOptions"
                        wire:model.live="sector"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="state" :label="__('State')">
                    <x-ui.form.select
                        name="state"
                        :placeholder="__('All entries')"
                        :options="['active' => __('In circulation'), 'retired' => __('Retired')]"
                        wire:model.live="state"
                    />
                </x-ui.form.group>
            </div>

            @if ($this->hasFilters())
                <div class="mt-3 flex justify-end">
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,sector,state">
            @if ($this->definitions->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state variant="filtered" :description="__('No library indicators match the filters you have set.')">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="folder"
                        :title="__('The library is empty')"
                        :description="__('Add the measures every entity should report against. Until there are entries here, entities define their own indicators locally and the state report cannot be consolidated against one list.')"
                    >
                        <x-slot:actions>
                            @if ($canManage)
                                <x-ui.button icon="plus" wire:click="startDefinition">{{ __('Add an indicator') }}</x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('State indicator library, with how many entities use each entry')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Indicator'),
                        __('Sector'),
                        __('Unit'),
                        __('Frequency'),
                        ['label' => __('In use by'), 'align' => 'right'],
                        __('State'),
                        '',
                    ]"
                >
                    @foreach ($this->definitions as $definition)
                        <x-ui.table.row wire:key="definition-{{ $definition->id }}" :muted="! $definition->is_active">
                            <x-ui.table.cell :label="__('Indicator')" primary>
                                {{ $definition->name }}
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $definition->code }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Sector')">
                                <span class="text-ink-muted">{{ $definition->sector?->name ?? __('Cross-cutting') }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Unit')">
                                <span class="text-ink-muted">{{ $definition->unit->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Frequency')">
                                <span class="text-ink-muted">{{ $definition->default_measurement_frequency->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('In use by')" numeric>
                                {{ number_format($definition->indicators_count) }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('State')">
                                <x-ui.badge
                                    :status="$definition->is_active ? 'fulfilled' : 'waived'"
                                    :icon="$definition->is_active ? 'check-circle' : 'pause-circle'"
                                    :label="$definition->is_active ? __('In circulation') : __('Retired')"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @can('update', $definition)
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            wire:click="editDefinition({{ $definition->id }})"
                                            loading="editDefinition({{ $definition->id }})"
                                        >{{ __('Edit') }}</x-ui.button>

                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            :icon="$definition->is_active ? 'pause-circle' : 'arrow-path'"
                                            wire:click="toggleRetired({{ $definition->id }})"
                                            loading="toggleRetired({{ $definition->id }})"
                                        >{{ $definition->is_active ? __('Retire') : __('Restore') }}</x-ui.button>
                                    @endcan
                                </div>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->definitions->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->definitions" :label="__('Indicator library pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    @if ($canManage)
        <x-ui.modal
            name="library-entry"
            :title="__('Library indicator')"
            :description="__('This wording is what every entity will report against, so it has to mean one thing. The code is quoted in returns and is never reused.')"
            max-width="xl"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="code"
                    :label="__('Code')"
                    :hint="__('e.g. EDU-ENR-01. Fixed once created.')"
                    required
                >
                    <x-ui.form.input
                        name="code"
                        type="text"
                        maxlength="40"
                        has-hint
                        :disabled="$editing !== ''"
                        wire:model="code"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="name" :label="__('Name')" required>
                    <x-ui.form.input name="name" type="text" maxlength="255" wire:model="name" />
                </x-ui.form.group>
            </div>

            <x-ui.form.group
                name="definition"
                :label="__('Definition')"
                :hint="__('What exactly counts, and what does not. The sentence a disputed figure is settled by.')"
                class="mt-4"
            >
                <x-ui.form.textarea name="definition" rows="3" maxlength="2000" has-hint wire:model="definition" />
            </x-ui.form.group>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="focus" :label="__('Focus')">
                    <x-ui.form.input name="focus" type="text" maxlength="255" wire:model="focus" />
                </x-ui.form.group>

                <x-ui.form.group name="sectorId" :label="__('Sector')">
                    <x-ui.form.select
                        name="sectorId"
                        :placeholder="__('Cross-cutting')"
                        :options="$this->sectorOptions"
                        wire:model="sectorId"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="unit" :label="__('Unit')" required>
                    <x-ui.form.select name="unit" :options="$this->unitOptions" wire:model="unit" />
                </x-ui.form.group>

                <x-ui.form.group name="frequency" :label="__('Frequency')" required>
                    <x-ui.form.select name="frequency" :options="$this->frequencyOptions" wire:model="frequency" />
                </x-ui.form.group>

                <x-ui.form.group name="targetType" :label="__('Target type')" required>
                    <x-ui.form.select name="targetType" :options="$this->targetTypeOptions" wire:model="targetType" />
                </x-ui.form.group>

                <x-ui.form.group name="tier" :label="__('Default tier')">
                    <x-ui.form.select
                        name="tier"
                        :placeholder="__('Set per framework')"
                        :options="$this->tierOptions"
                        wire:model="tier"
                    />
                </x-ui.form.group>
            </div>

            <x-ui.form.group name="dataSource" :label="__('Source of data')" class="mt-4">
                <x-ui.form.input name="dataSource" type="text" maxlength="1000" wire:model="dataSource" />
            </x-ui.form.group>

            <x-ui.form.group name="meansOfVerification" :label="__('Means of verification')" class="mt-4">
                <x-ui.form.input name="meansOfVerification" type="text" maxlength="1000" wire:model="meansOfVerification" />
            </x-ui.form.group>

            <x-ui.form.group
                name="responsibleCollector"
                :label="__('Responsible for collection')"
                :hint="__('An office or role — never a named officer: the library is state-wide.')"
                class="mt-4"
            >
                <x-ui.form.input name="responsibleCollector" type="text" maxlength="255" has-hint wire:model="responsibleCollector" />
            </x-ui.form.group>

            <x-ui.form.group name="smartStatement" :label="__('SMART statement')" class="mt-4">
                <x-ui.form.textarea name="smartStatement" rows="3" maxlength="2000" wire:model="smartStatement" />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="saveDefinition" loading="saveDefinition" icon="check">
                    {{ __('Save entry') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
