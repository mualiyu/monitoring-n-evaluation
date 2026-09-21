{{--
    State completion register
    (App\Livewire\Oversight\Lifecycle\CertificateRegister).

    Read only, across every MDA. The data-heavy pattern from the design
    system: filter bar → stat summary → table (cards under sm:) → pagination.
--}}
<div>
    <x-ui.page-header
        :title="__('Completion certificates')"
        :description="__('Every completion certificate issued across all entities. Certification releases public works and unlocks payment, so the register is read here as it is signed — not in arrears.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — state-wide, deliberately NOT filtered              --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Certificates in force')"
            :value="number_format($this->stats['total'])"
            icon="document-check"
            :hint="__('across all entities')"
        />
        <x-ui.stat
            :label="__('Practical completion')"
            :value="number_format($this->stats['practical'])"
            icon="flag"
            :hint="__('works handed over')"
        />
        <x-ui.stat
            :label="__('Final completion')"
            :value="number_format($this->stats['final'])"
            icon="shield-check"
            :hint="__('retention released')"
        />
        <x-ui.stat
            :label="__('Under defects liability')"
            :value="number_format($this->stats['under_defects_liability'])"
            icon="clock"
            :intent="$this->stats['under_defects_liability'] > 0 ? 'warning' : 'neutral'"
            :hint="__('contractors still recallable')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Certificate number, project title or reference…')"
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

                <x-ui.form.group name="type" :label="__('Certificate type')">
                    <x-ui.form.select
                        name="type"
                        :placeholder="__('Any type')"
                        :options="$this->typeOptions"
                        wire:model.live="type"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="revoked" :label="__('Withdrawn')">
                    <label class="flex items-center gap-2 py-2 text-sm text-ink">
                        <input
                            type="checkbox"
                            id="revoked"
                            wire:model.live="revoked"
                            class="size-4 rounded border-line text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                        {{ __('Show withdrawn certificates') }}
                    </label>
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

    {{-- ---------------------------------------------------------------- --}}
    {{-- Register                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,tenantId,type,revoked">
            @if ($this->certificates->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No certificate matches the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="document-check"
                        :title="__('No completion certificate has been issued yet')"
                        :description="__('Entities certify their own completed works. Every certificate appears here the moment it is signed.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="folder" :href="route('oversight.portfolio.index')">
                                {{ __('Open the state portfolio') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    class="p-4 sm:p-0"
                    :caption="__('Completion certificates issued across all entities')"
                    :headings="[
                        __('Project'),
                        __('Entity'),
                        __('Certificate'),
                        __('Type'),
                        __('Issued'),
                        __('Status'),
                        '',
                    ]"
                >
                    @foreach ($this->certificates as $certificate)
                        <x-ui.table.row wire:key="certificate-{{ $certificate->ulid }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a
                                    href="{{ route('oversight.projects.show', ['ulid' => $certificate->project->ulid]) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $certificate->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">
                                    {{ $certificate->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Entity')">
                                {{ $certificate->tenant->name }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Certificate')">
                                <span class="font-mono text-xs">{{ $certificate->reference }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Type')">
                                {{ $certificate->type->shortLabel() }}
                                @if ($certificate->defects_liability_ends_on)
                                    <span class="mt-0.5 block text-xs text-ink-muted">
                                        {{ __('defects liability to :date', ['date' => $certificate->defects_liability_ends_on->translatedFormat('j M Y')]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Issued')">
                                {{ $certificate->issued_at->translatedFormat('j M Y') }}
                                <span class="mt-0.5 block text-xs text-ink-muted">
                                    {{ $certificate->issuedBy?->name ?? __('an account since removed') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                @if ($certificate->isRevoked())
                                    <x-ui.badge status="rejected" :label="__('Withdrawn')" />
                                @else
                                    <x-ui.badge status="certified" :label="__('In force')" />
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    trailing-icon="chevron-right"
                                    :href="route('oversight.projects.show', ['ulid' => $certificate->project->ulid])"
                                >{{ __('Open project') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->certificates->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->certificates" :label="__('State certificate register pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
