{{--
    Indicator detail (App\Livewire\Tenant\Indicators\IndicatorDetail).

    Definition sheet → standing → targets → figures → capture.
    The definition comes first on purpose: an officer disputing a number reads
    the definition, the data source and the means of verification BEFORE the
    figure, and the manual's indicator matrix is exactly those fields.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $achievement = $this->achievement;
    $unit = $indicator->unit->suffix();

    $canRecord = auth()->user()?->can('create', \App\Models\IndicatorReading::class) ?? false;
    $canManage = auth()->user()?->can('update', $indicator) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="$indicator->name"
        :description="$indicator->definition"
        :breadcrumbs="[
            ['label' => __('Indicators'), 'href' => route('tenant.indicators.index', $workspace)],
            ['label' => $indicator->name],
        ]"
    >
        <x-slot:actions>
            @if (! $indicator->is_active)
                @can('activate', $indicator)
                    <x-ui.button
                        size="sm"
                        icon="check-circle"
                        wire:click="activate"
                        loading="activate"
                    >{{ __('Activate') }}</x-ui.button>
                @endcan
            @elseif ($canRecord)
                <x-ui.button size="sm" icon="plus" wire:click="startReading" loading="startReading">
                    {{ __('Record a figure') }}
                </x-ui.button>
            @endif

            @if ($canManage)
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="flag"
                    wire:click="startTarget"
                    loading="startTarget"
                >{{ __('Set a target') }}</x-ui.button>
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

    @unless ($indicator->is_active)
        <x-ui.alert variant="warning" :title="__('Awaiting a baseline')" class="mb-4">
            {{ __('No figure can be recorded until this indicator is activated, and it cannot be activated without a baseline value, the date it was measured and where it came from. A fabricated zero is worse data than an honest gap.') }}
        </x-ui.alert>
    @endunless

    {{-- ---------------------------------------------------------------- --}}
    {{-- Standing                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Baseline')"
            :value="$indicator->baseline_value === null ? '—' : $indicator->baseline_value.$unit"
            icon="map-pin"
            :hint="$indicator->baseline_date?->translatedFormat('j M Y')"
        />
        <x-ui.stat
            :label="__('Current target')"
            :value="$indicator->latestTarget?->target_value === null ? '—' : $indicator->latestTarget->target_value.$unit"
            icon="flag"
            :hint="$indicator->latestTarget?->period_end?->translatedFormat('j M Y')"
        />
        <x-ui.stat
            :label="__('Latest validated figure')"
            :value="$indicator->latestCountableReading?->actual_value === null ? '—' : $indicator->latestCountableReading->actual_value.$unit"
            icon="chart-bar"
            :hint="$indicator->latestCountableReading?->period_end?->translatedFormat('j M Y')"
        />
        <x-ui.stat
            :label="__('Achievement')"
            :value="$achievement->percentLabel()"
            :icon="$achievement->icon()"
            :intent="$achievement->tone()"
            :hint="$achievement->label()"
        />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ------------------------------------------------------------ --}}
        {{-- Definition sheet (manual digest §2)                           --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Definition')" class="lg:col-span-1">
            <dl class="space-y-4 text-sm">
                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Tier') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->tier?->label() ?? __('Not set') }}</dd>
                </div>

                @if ($indicator->resultFramework)
                    <div>
                        <dt class="font-medium text-ink-muted">{{ __('Measures') }}</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-1.5 text-ink">
                            <x-ui.badge
                                status="level"
                                size="sm"
                                :icon="$indicator->resultFramework->level->icon()"
                                :label="$indicator->resultFramework->level->label()"
                            />
                            <span>{{ $indicator->resultFramework->statement }}</span>
                        </dd>
                    </div>
                @endif

                @if ($indicator->focus)
                    <div>
                        <dt class="font-medium text-ink-muted">{{ __('Focus') }}</dt>
                        <dd class="mt-1 text-ink">{{ $indicator->focus }}</dd>
                    </div>
                @endif

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Unit of measure') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->unit->label() }}</dd>
                </div>

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Measured') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->measurement_frequency->label() }}</dd>
                </div>

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Target type') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->target_type->label() }}</dd>
                </div>

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Source of data') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->data_source ?? __('Not stated') }}</dd>
                </div>

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Means of verification') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->means_of_verification ?? __('Not stated') }}</dd>
                </div>

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Responsible for collection') }}</dt>
                    <dd class="mt-1 text-ink">
                        {{ $indicator->responsibleCollector?->name ?? $indicator->responsible_collector_text ?? __('Not assigned') }}
                    </dd>
                </div>

                <div>
                    <dt class="font-medium text-ink-muted">{{ __('Baseline source') }}</dt>
                    <dd class="mt-1 text-ink">{{ $indicator->baseline_source ?? __('Not stated') }}</dd>
                </div>

                @if ($indicator->smart_justification)
                    <div>
                        <dt class="font-medium text-ink-muted">{{ __('SMART statement') }}</dt>
                        <dd class="mt-1 text-ink">{{ $indicator->smart_justification }}</dd>
                    </div>
                @endif

                @if ($indicator->libraryDefinition)
                    <div>
                        <dt class="font-medium text-ink-muted">{{ __('State library entry') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-ink">{{ $indicator->libraryDefinition->code }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        <div class="space-y-4 lg:col-span-2">
            {{-- -------------------------------------------------------- --}}
            {{-- Targets                                                   --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card :title="__('Targets')" flush>
                @if ($this->targets->isEmpty())
                    <x-ui.empty-state
                        compact
                        icon="flag"
                        :title="__('No targets set')"
                        :description="__('Achievement cannot be computed without a target for the period. An annual target and its quarterly milestones can both be set — they are different periods.')"
                    >
                        <x-slot:actions>
                            @if ($canManage)
                                <x-ui.button icon="plus" wire:click="startTarget">{{ __('Set a target') }}</x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.table
                        :caption="__('Targets set for this indicator, by period')"
                        class="p-4 sm:p-0"
                        :headings="[__('Period'), __('Cadence'), ['label' => __('Target'), 'align' => 'right'], __('Notes')]"
                    >
                        @foreach ($this->targets as $target)
                            <x-ui.table.row wire:key="target-{{ $target->id }}">
                                <x-ui.table.cell :label="__('Period')" primary>
                                    {{ $target->period_start->translatedFormat('j M Y') }}
                                    &ndash;
                                    {{ $target->period_end->translatedFormat('j M Y') }}
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Cadence')">
                                    <span class="text-ink-muted">{{ $target->period_type->label() }}</span>
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Target')" numeric>
                                    {{ $target->target_value }}{{ $unit }}
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Notes')" stacked>
                                    <span class="text-ink-muted">{{ $target->notes ?? '—' }}</span>
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            {{-- -------------------------------------------------------- --}}
            {{-- Figures                                                   --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card :title="__('Figures')" flush>
                <div wire:loading.delay.long.flex class="hidden p-4">
                    <x-ui.skeleton variant="table" :rows="4" />
                </div>

                <div wire:loading.delay.long.remove wire:target="saveReading,submitReading">
                    @if ($this->readings->isEmpty())
                        <x-ui.empty-state
                            compact
                            icon="chart-bar"
                            :title="__('No figures recorded')"
                            :description="__('Record what was actually measured for a period. A figure stays a draft until you submit it, and only clears review when a data-quality reviewer — never you — validates it.')"
                        >
                            <x-slot:actions>
                                @if ($canRecord && $indicator->is_active)
                                    <x-ui.button icon="plus" wire:click="startReading">{{ __('Record a figure') }}</x-ui.button>
                                @endif
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @else
                        <x-ui.table
                            :caption="__('Figures recorded against this indicator, with their review status')"
                            class="p-4 sm:p-0"
                            :headings="[
                                __('Period'),
                                ['label' => __('Actual'), 'align' => 'right'],
                                __('Source'),
                                __('Status'),
                                '',
                            ]"
                        >
                            @foreach ($this->readings as $reading)
                                <x-ui.table.row wire:key="reading-{{ $reading->ulid }}">
                                    <x-ui.table.cell :label="__('Period')" primary>
                                        {{ $reading->period_start->translatedFormat('j M Y') }}
                                        &ndash;
                                        {{ $reading->period_end->translatedFormat('j M Y') }}
                                        <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                            {{ __('recorded by :name', ['name' => $reading->recordedBy?->name ?? __('an earlier system')]) }}
                                        </span>
                                    </x-ui.table.cell>

                                    <x-ui.table.cell :label="__('Actual')" numeric>
                                        {{ $reading->actual_value }}{{ $unit }}
                                    </x-ui.table.cell>

                                    <x-ui.table.cell :label="__('Source')">
                                        <span class="text-ink-muted">{{ $reading->source_type->label() }}</span>
                                    </x-ui.table.cell>

                                    <x-ui.table.cell :label="__('Status')">
                                        <x-ui.badge
                                            :status="$reading->status->value"
                                            :icon="$reading->status->icon()"
                                            :label="$reading->status->label()"
                                        />

                                        @if ($reading->rejection_reason)
                                            <span class="mt-1 block text-xs text-warning-ink">
                                                {{ __('Sent back: :reason', ['reason' => $reading->rejection_reason]) }}
                                            </span>
                                        @elseif ($reading->validatedBy)
                                            <span class="mt-1 block text-xs text-ink-muted">
                                                {{ __('validated by :name', ['name' => $reading->validatedBy->name]) }}
                                            </span>
                                        @endif
                                    </x-ui.table.cell>

                                    <x-ui.table.cell align="right">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            @can('update', $reading)
                                                <x-ui.button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="pencil-square"
                                                    wire:click="editReading('{{ $reading->ulid }}')"
                                                    loading="editReading('{{ $reading->ulid }}')"
                                                >{{ __('Edit') }}</x-ui.button>
                                            @endcan

                                            @if ($reading->status->isEditable())
                                                @can('submit', $reading)
                                                    <x-ui.button
                                                        variant="secondary"
                                                        size="sm"
                                                        icon="paper-airplane"
                                                        wire:click="submitReading('{{ $reading->ulid }}')"
                                                        loading="submitReading('{{ $reading->ulid }}')"
                                                    >{{ __('Submit') }}</x-ui.button>
                                                @endcan
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
                        <x-ui.pagination :paginator="$this->readings" :label="__('Figure history pages')" />
                    </x-slot:footer>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Capture a figure                                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canRecord)
        <x-ui.modal
            name="record-reading"
            :title="__('Record a figure')"
            :description="__('What was actually measured for the period, and where it came from. It is saved as a draft — submitting it for data-quality review is a separate step.')"
            max-width="lg"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="periodStart" :label="__('Period start')" required>
                    <x-ui.form.input name="periodStart" type="date" wire:model="periodStart" />
                </x-ui.form.group>

                <x-ui.form.group name="periodEnd" :label="__('Period end')" required>
                    <x-ui.form.input name="periodEnd" type="date" wire:model="periodEnd" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="actualValue"
                    :label="__('Actual value')"
                    :hint="$indicator->unit->label()"
                    required
                >
                    <x-ui.form.input name="actualValue" type="text" inputmode="decimal" has-hint wire:model="actualValue" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="sourceType"
                    :label="__('Source')"
                    :hint="__('Collected for this indicator, or taken from an existing record.')"
                    required
                >
                    <x-ui.form.select
                        name="sourceType"
                        has-hint
                        :options="$this->sourceTypeOptions"
                        wire:model="sourceType"
                    />
                </x-ui.form.group>
            </div>

            <x-ui.form.group
                name="collectionMethod"
                :label="__('How it was collected')"
                :hint="__('e.g. Physical count during the quarterly site visit.')"
                class="mt-4"
            >
                <x-ui.form.input name="collectionMethod" type="text" maxlength="255" has-hint wire:model="collectionMethod" />
            </x-ui.form.group>

            <x-ui.form.group name="notes" :label="__('Notes')" class="mt-4">
                <x-ui.form.textarea name="notes" rows="3" maxlength="2000" wire:model="notes" />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="saveReading" loading="saveReading" icon="check">
                    {{ __('Save draft') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Set a target                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canManage)
        <x-ui.modal
            name="set-target"
            :title="__('Set a target')"
            :description="__('One target per period. Revising a target is allowed and recorded with its before and after — a target quietly lowered to meet the actual is the oldest trick in monitoring, and the audit trail is the answer to it.')"
            max-width="lg"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="targetPeriodType" :label="__('Cadence')" required>
                    <x-ui.form.select
                        name="targetPeriodType"
                        :options="$this->periodTypeOptions"
                        wire:model="targetPeriodType"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="targetValue"
                    :label="__('Target value')"
                    :hint="$indicator->unit->label()"
                    required
                >
                    <x-ui.form.input name="targetValue" type="text" inputmode="decimal" has-hint wire:model="targetValue" />
                </x-ui.form.group>

                <x-ui.form.group name="targetStart" :label="__('Period start')" required>
                    <x-ui.form.input name="targetStart" type="date" wire:model="targetStart" />
                </x-ui.form.group>

                <x-ui.form.group name="targetEnd" :label="__('Period end')" required>
                    <x-ui.form.input name="targetEnd" type="date" wire:model="targetEnd" />
                </x-ui.form.group>
            </div>

            <x-ui.form.group name="targetNotes" :label="__('Notes')" class="mt-4">
                <x-ui.form.textarea name="targetNotes" rows="3" maxlength="1000" wire:model="targetNotes" />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="saveTarget" loading="saveTarget" icon="flag">
                    {{ __('Save target') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
