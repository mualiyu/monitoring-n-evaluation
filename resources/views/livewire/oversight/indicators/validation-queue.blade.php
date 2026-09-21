{{--
    Data-quality validation queue (App\Livewire\Oversight\Indicators\ValidationQueue).

    Every figure any entity has submitted and nobody has yet checked. Oldest
    first: a queue is a queue, and the figure that has been waiting longest is
    the one holding up a report.
--}}
@php
    $canPublish = auth()->user()?->holdsGlobalPermission('indicators.readings.publish') ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Data validation')"
        :description="__('Figures submitted by entities across the state, waiting on independent review. Whoever recorded or filed a figure can never be the person who clears it — that separation is the whole point of this desk.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <x-ui.stat
            :label="__('Figures awaiting review')"
            :value="number_format($this->stats['waiting'])"
            icon="inbox"
            :intent="$this->stats['waiting'] > 0 ? 'warning' : 'positive'"
            :hint="$this->stats['waiting'] > 0 ? __('across every entity') : __('the queue is clear')"
        />
        <x-ui.stat
            :label="__('Entities reporting')"
            :value="number_format($this->stats['mdas'])"
            icon="building-office"
        />
    </div>

    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Indicator name…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="tenantId" :label="__('Entity')">
                    <x-ui.form.select
                        name="tenantId"
                        :placeholder="__('All entities')"
                        :options="$this->tenants->pluck('name', 'id')->all()"
                        wire:model.live="tenantId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="sector" :label="__('Sector')">
                    <x-ui.form.select
                        name="sector"
                        :placeholder="__('All sectors')"
                        :options="$this->sectors->pluck('name', 'id')->all()"
                        wire:model.live="sector"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Awaiting review')"
                        :options="$this->statusOptions"
                        wire:model.live="status"
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

        <div wire:loading.delay.long.remove wire:target="search,tenantId,sector,status">
            @if ($this->readings->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state variant="filtered" :description="__('No submitted figures match the filters you have set.')">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="check-circle"
                        :title="__('Nothing is waiting on you')"
                        :description="__('Every figure submitted so far has been reviewed. New submissions from any entity will appear here, oldest first.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Indicator figures submitted across entities and awaiting data-quality review')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Indicator'),
                        __('Entity'),
                        __('Period'),
                        ['label' => __('Submitted figure'), 'align' => 'right'],
                        __('Provenance'),
                        '',
                    ]"
                >
                    @foreach ($this->readings as $reading)
                        @php $indicator = $reading->indicator; @endphp

                        <x-ui.table.row wire:key="queue-{{ $reading->ulid }}">
                            <x-ui.table.cell :label="__('Indicator')" primary>
                                {{ $indicator->name }}
                                @if ($indicator->project)
                                    <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {{ $indicator->project->title }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Entity')">
                                <span class="text-ink-muted">{{ $reading->tenant->name }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Period')">
                                <span class="text-ink-muted">
                                    {{ $reading->period_start->translatedFormat('j M Y') }}
                                    &ndash;
                                    {{ $reading->period_end->translatedFormat('j M Y') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Submitted figure')" numeric>
                                {{ $reading->actual_value }}{{ $indicator->unit->suffix() }}
                                <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                    {{ __('target :target', ['target' => $indicator->latestTarget?->target_value ?? '—']) }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Provenance')" stacked>
                                <x-ui.badge
                                    :status="$reading->source_type->value === 'primary' ? 'on_track' : 'pending'"
                                    size="sm"
                                    :icon="$reading->source_type->value === 'primary' ? 'map-pin' : 'document-text'"
                                    :label="$reading->source_type->label()"
                                />
                                <span class="mt-1 block text-xs text-ink-muted">
                                    {{ __('recorded by :recorder, filed by :submitter', [
                                        'recorder' => $reading->recordedBy?->name ?? __('unknown'),
                                        'submitter' => $reading->submittedBy?->name ?? __('unknown'),
                                    ]) }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($reading->status === \App\Enums\IndicatorReadingStatus::Submitted)
                                        <x-ui.button
                                            size="sm"
                                            icon="shield-check"
                                            wire:click="validateReading('{{ $reading->ulid }}')"
                                            loading="validateReading('{{ $reading->ulid }}')"
                                        >{{ __('Validate') }}</x-ui.button>
                                    @endif

                                    @if ($canPublish && $reading->status === \App\Enums\IndicatorReadingStatus::Validated)
                                        <x-ui.button
                                            size="sm"
                                            icon="globe"
                                            wire:click="publishReading('{{ $reading->ulid }}')"
                                            loading="publishReading('{{ $reading->ulid }}')"
                                        >{{ __('Publish') }}</x-ui.button>
                                    @endif

                                    @if ($reading->status->canTransitionTo(\App\Enums\IndicatorReadingStatus::Draft))
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrow-uturn-left"
                                            wire:click="startRejection('{{ $reading->ulid }}')"
                                            loading="startRejection('{{ $reading->ulid }}')"
                                        >{{ __('Send back') }}</x-ui.button>
                                    @endif
                                </div>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->readings->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->readings" :label="__('Validation queue pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Send a figure back                                                --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="reject-reading"
        :title="__('Send this figure back?')"
        :description="__('It returns to the entity that recorded it as a draft, with your reason attached. The rejection stays on the data-quality record permanently.')"
        max-width="md"
    >
        <x-ui.form.group
            name="rejectionReason"
            :label="__('Reason')"
            :hint="__('Required. Read by the person who measured it, and by anyone auditing this entity’s data quality.')"
            required
        >
            <x-ui.form.textarea
                name="rejectionReason"
                rows="3"
                maxlength="1000"
                has-hint
                :placeholder="__('e.g. The figure counts households reached rather than households with a working connection, which is what this indicator measures.')"
                wire:model="rejectionReason"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelRejection">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                wire:click="confirmRejection"
                loading="confirmRejection"
                icon="arrow-uturn-left"
            >{{ __('Send back') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
