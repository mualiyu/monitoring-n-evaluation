{{--
    State-wide evaluation board (App\Livewire\Oversight\Evaluation\EvaluationBoard).

    Read-only. filter bar → stat row → table → pagination, as every data-heavy
    screen does. The cross-MDA read happens inside the Oversight Action, which
    re-checks evaluations.view in the GLOBAL permission team first.
--}}
<div>
    <x-ui.page-header
        :title="__('Evaluations')"
        :description="__('Every evaluation commissioned across the entities, what stage it has reached, and which reports are past their deadline.')"
    />

    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Title or subject…')"
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

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->statusOptions"
                        wire:model.live="status"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="type" :label="__('Type')">
                    <x-ui.form.select
                        name="type"
                        :placeholder="__('Any type')"
                        :options="$this->typeOptions"
                        wire:model.live="type"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <x-ui.form.checkbox
                    name="overdue"
                    :label="__('Only evaluations whose report is overdue')"
                    :description="__('Past the report deadline and not yet approved, published or cancelled.')"
                    wire:model.live="overdue"
                />

                @if ($this->hasFilters())
                    <div class="sm:ml-auto">
                        <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                            {{ __('Clear filters') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantId,status,type,overdue,gotoPage,previousPage,nextPage">
            @if ($this->evaluations->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No evaluations match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="clipboard-check"
                        :title="__('No evaluation has been commissioned yet')"
                        :description="__('Evaluations are commissioned by each entity in its own workspace, or by the secretariat over one. They appear on this board as they are opened.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Evaluations across every entity, with type, stage and follow-up count')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Entity'),
                        __('Evaluation'),
                        __('Type'),
                        __('Subject'),
                        __('Report due'),
                        ['label' => __('Recommendations'), 'align' => 'right'],
                        __('Status'),
                    ]"
                >
                    @foreach ($this->evaluations as $evaluation)
                        <x-ui.table.row wire:key="evaluation-{{ $evaluation->ulid }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                {{ $evaluation->tenant->name }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Evaluation')">
                                {{ $evaluation->title }}
                                <span class="mt-0.5 block text-xs text-ink-muted">{{ $evaluation->sponsor }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Type')">
                                <span class="text-ink-muted">{{ $evaluation->type->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Subject')">
                                {{ $evaluation->project?->title ?? $evaluation->subject_name ?? $evaluation->scope }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Report due')">
                                @if ($evaluation->report_due_on)
                                    <span class="tabular-nums">{{ $evaluation->report_due_on->translatedFormat('j M Y') }}</span>
                                    @if ($evaluation->isReportOverdue())
                                        {{-- Icon + words, never colour alone. --}}
                                        <span class="mt-1 flex items-center gap-1 text-xs font-medium text-critical-ink">
                                            <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                            {{ __('Overdue') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-subtle">—</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Recommendations')" numeric>
                                {{ number_format($evaluation->recommendations_count) }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$evaluation->status->badge()" :label="$evaluation->status->label()" />
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->evaluations->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->evaluations" :label="__('Evaluation board pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
