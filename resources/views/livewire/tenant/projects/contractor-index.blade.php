{{--
    Vendor registry, workspace view (App\Livewire\Tenant\Projects\ContractorIndex).
    Read + add. Editing and blacklisting live on the oversight surface.
--}}
@php
    $canCreate = auth()->user()?->can('create', \App\Models\Contractor::class) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Contractors')"
        :description="__('The state-wide vendor register. It is shared by every entity on this platform, so a firm blacklisted elsewhere is visible here too.')"
    >
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button size="sm" icon="plus" x-on:click="$dispatch('open-modal', 'register-contractor')">
                    {{ __('Add a contractor') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice)
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ $notice }}</x-ui.alert>
    @endif

    {{-- Filter bar --}}
    <x-ui.card class="mb-4" flush>
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.group name="search" :label="__('Search')" class="lg:col-span-2">
                <x-ui.form.input
                    name="search"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('Firm name, RC number or category…')"
                    wire:model.live.debounce.300ms="search"
                />
            </x-ui.form.group>

            <x-ui.form.group name="type" :label="__('Firm type')">
                <x-ui.form.select
                    name="type"
                    :placeholder="__('Any type')"
                    :options="$this->typeOptions"
                    wire:model.live="type"
                />
            </x-ui.form.group>

            <div class="flex items-end justify-between gap-3 pb-1">
                <x-ui.form.checkbox
                    name="blacklistedOnly"
                    :label="__('Blacklisted only')"
                    wire:model.live="blacklistedOnly"
                />

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    {{-- Summary --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <x-ui.stat :label="__('Firms on the register')" :value="number_format($this->stats['total'])" icon="building-office" />
        <x-ui.stat
            :label="__('Blacklisted')"
            :value="number_format($this->stats['blacklisted'])"
            icon="exclamation-triangle"
            :hint="$this->stats['blacklisted'] > 0 ? __('cannot be awarded new contracts') : __('none on the register')"
        />
    </div>

    {{-- Table --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,type,blacklistedOnly,gotoPage,previousPage,nextPage">
            @if ($this->contractors->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state variant="filtered" :description="__('No firms match these filters.')">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="building-office"
                        :title="__('The vendor register is empty')"
                        :description="__('Add the firms your entity works with. Registering a firm once makes it available to every entity, and keeps one performance history per vendor.')"
                    >
                        <x-slot:actions>
                            @if ($canCreate)
                                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'register-contractor')">
                                    {{ __('Add a contractor') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Firms on the state vendor register')"
                    class="p-4 sm:p-0"
                    :headings="[__('Firm'), __('Type'), __('Category'), ['label' => __('Contracts'), 'align' => 'right'], __('Status')]"
                >
                    @foreach ($this->contractors as $contractor)
                        <x-ui.table.row wire:key="contractor-{{ $contractor->id }}">
                            <x-ui.table.cell :label="__('Firm')" primary>
                                {{ $contractor->name }}
                                @if ($contractor->rc_number)
                                    <span class="block font-mono text-xs font-normal text-ink-muted">{{ $contractor->rc_number }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Type')">
                                <span class="text-ink-muted">{{ $contractor->type->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Category')">
                                <span class="text-ink-muted">{{ $contractor->category ?? '—' }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contracts')" numeric>
                                {{ number_format($contractor->contracts_count) }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                @if ($contractor->is_blacklisted)
                                    <x-ui.badge status="rejected" :label="__('Blacklisted')" />
                                    @if ($contractor->blacklist_reason)
                                        <span class="mt-1 block max-w-xs text-xs text-ink-muted">{{ $contractor->blacklist_reason }}</span>
                                    @endif
                                @else
                                    <x-ui.badge status="approved" :label="__('In good standing')" />
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->contractors->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->contractors" :label="__('Contractor list pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    <p class="mt-3 flex items-start gap-1.5 text-xs text-ink-muted">
        <x-ui.icon name="information-circle" class="mt-0.5 size-3.5 shrink-0" />
        {{ __('Editing a firm’s details or blacklisting it is done by the state oversight team, so that one entity cannot rewrite a vendor record another entity’s contracts depend on.') }}
    </p>

    {{-- Registration --}}
    @if ($canCreate)
        <x-ui.modal
            name="register-contractor"
            :title="__('Add a contractor')"
            :description="__('If the RC number is already on the register we will link you to the existing firm instead of creating a duplicate.')"
            max-width="lg"
        >
            <div class="space-y-4">
                <x-ui.form.group name="name" :label="__('Registered firm name')" required>
                    <x-ui.form.input name="name" wire:model="name" />
                </x-ui.form.group>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group
                        name="rcNumber"
                        :label="__('RC number')"
                        :hint="__('CAC registration number. Strongly recommended — it is what de-duplicates the register.')"
                        optional
                    >
                        <x-ui.form.input name="rcNumber" has-hint wire:model="rcNumber" />
                    </x-ui.form.group>

                    <x-ui.form.group name="firmType" :label="__('Firm type')" required>
                        <x-ui.form.select name="firmType" :options="$this->typeOptions" wire:model="firmType" />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group
                    name="category"
                    :label="__('Category')"
                    :hint="__('e.g. civil works, ICT, medical supply.')"
                    optional
                >
                    <x-ui.form.input name="category" has-hint wire:model="category" />
                </x-ui.form.group>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group name="contactName" :label="__('Contact person')" optional>
                        <x-ui.form.input name="contactName" wire:model="contactName" />
                    </x-ui.form.group>

                    <x-ui.form.group name="contactPhone" :label="__('Contact phone')" optional>
                        <x-ui.form.input name="contactPhone" type="tel" inputmode="tel" wire:model="contactPhone" />
                    </x-ui.form.group>
                </div>

                <x-ui.form.group name="contactEmail" :label="__('Contact email')" optional>
                    <x-ui.form.input name="contactEmail" type="email" inputmode="email" wire:model="contactEmail" />
                </x-ui.form.group>

                <x-ui.form.group name="address" :label="__('Address')" optional>
                    <x-ui.form.textarea name="address" rows="2" maxlength="1000" wire:model="address" />
                </x-ui.form.group>
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'register-contractor')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button icon="plus" wire:click="register" loading="register">{{ __('Add to register') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
