{{--
    State-wide follow-up register
    (App\Livewire\Oversight\Evaluation\RecommendationBoard).

    The board the evaluation module exists for: an evaluation whose
    recommendations nobody implements is a document, not a control. Defaults to
    OUTSTANDING items — the implemented ones are not the ones that need a
    secretariat.
--}}
<div>
    <x-ui.page-header
        :title="__('Recommendations follow-up')"
        :description="__('What evaluations and field reports have recommended across every entity, who owns each one, and what is overdue.')"
    />

    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Recommendation or addressee…')"
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

                <x-ui.form.group name="priority" :label="__('Priority')">
                    <x-ui.form.select
                        name="priority"
                        :placeholder="__('Any priority')"
                        :options="$this->priorityOptions"
                        wire:model.live="priority"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <x-ui.form.checkbox
                    name="outstanding"
                    :label="__('Only items still outstanding')"
                    :description="__('Ignored while an explicit status filter is set, so asking for “implemented” returns implemented items.')"
                    wire:model.live="outstanding"
                />

                <x-ui.form.checkbox
                    name="overdue"
                    :label="__('Only items past their due date')"
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

        <div wire:loading.delay.long.remove wire:target="search,tenantId,status,priority,outstanding,overdue,gotoPage,previousPage,nextPage">
            @if ($this->recommendations->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No recommendations match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="adjustments"
                        :title="__('Nothing outstanding')"
                        :description="__('Recommendations raised by evaluations, progress reports and inspections collect here until they are implemented.')"
                    />
                @endif
            @else
                <x-ui.table
                    :caption="__('Outstanding recommendations across every entity, most pressing first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Entity'),
                        __('Recommendation'),
                        __('Addressee'),
                        __('Priority'),
                        __('Due'),
                        __('Status'),
                    ]"
                >
                    @foreach ($this->recommendations as $recommendation)
                        <x-ui.table.row wire:key="recommendation-{{ $recommendation->ulid }}">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                {{ $recommendation->tenant->name }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Recommendation')">
                                {{ $recommendation->title }}
                                @if ($recommendation->project)
                                    <span class="mt-0.5 block text-xs text-ink-muted">{{ $recommendation->project->title }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Addressee')">
                                <span class="text-ink-muted">
                                    {{ $recommendation->addressee?->name ?? $recommendation->addressee_body ?? '—' }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Priority')">
                                <x-ui.badge :status="$recommendation->priority->badge()" :label="$recommendation->priority->label()" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Due')">
                                @if ($recommendation->due_on)
                                    <span class="tabular-nums">{{ $recommendation->due_on->translatedFormat('j M Y') }}</span>
                                    @if ($recommendation->isOverdue())
                                        {{-- Icon + words, never colour alone. --}}
                                        <span class="mt-1 flex items-center gap-1 text-xs font-medium text-critical-ink">
                                            <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                            {{ __('Overdue') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-subtle">{{ __('No date set') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$recommendation->status->badge()" :label="$recommendation->status->label()" />
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->recommendations->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->recommendations" :label="__('Follow-up register pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
