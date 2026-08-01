{{--
    Vendor registry management, state surface
    (App\Livewire\Oversight\Projects\ContractorRegistry).
--}}
<div>
    <x-ui.page-header
        :title="__('Vendor registry')"
        :description="__('The state-wide register of contractors, consultants and suppliers. Changes here apply to every entity on the platform.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That action could not be completed')">{{ $failure }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <x-ui.stat :label="__('Firms on the register')" :value="number_format($this->stats['total'])" icon="building-office" />
        <x-ui.stat
            :label="__('Blacklisted')"
            :value="number_format($this->stats['blacklisted'])"
            icon="exclamation-triangle"
            :intent="$this->stats['blacklisted'] > 0 ? 'critical' : 'neutral'"
            :hint="__('barred from new awards state-wide')"
        />
    </div>

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
                <x-ui.form.select name="type" :placeholder="__('Any type')" :options="$this->typeOptions" wire:model.live="type" />
            </x-ui.form.group>

            <div class="flex items-end justify-between gap-3 pb-1">
                <x-ui.form.checkbox name="blacklistedOnly" :label="__('Blacklisted only')" wire:model.live="blacklistedOnly" />

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">{{ __('Clear') }}</x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,type,blacklistedOnly,gotoPage,previousPage,nextPage">
            @if ($this->contractors->isEmpty())
                <x-ui.empty-state
                    :variant="$this->hasFilters() ? 'filtered' : 'empty'"
                    icon="building-office"
                    :title="$this->hasFilters() ? null : __('The vendor register is empty')"
                    :description="$this->hasFilters()
                        ? __('No firms match these filters.')
                        : __('Entities add firms from their own workspaces as they award contracts. They appear here for editing and blacklisting.')"
                >
                    @if ($this->hasFilters())
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.table
                    :caption="__('State vendor register')"
                    class="p-4 sm:p-0"
                    :headings="[__('Firm'), __('Type'), ['label' => __('Contracts (state-wide)'), 'align' => 'right'], __('Status'), '']"
                >
                    @foreach ($this->contractors as $contractor)
                        <x-ui.table.row wire:key="registry-{{ $contractor->id }}">
                            <x-ui.table.cell :label="__('Firm')" primary>
                                {{ $contractor->name }}
                                <span class="block font-mono text-xs font-normal text-ink-muted">
                                    {{ $contractor->rc_number ?? __('no RC number') }}
                                    @if ($contractor->category) · {{ $contractor->category }} @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Type')">
                                <span class="text-ink-muted">{{ $contractor->type->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contracts (state-wide)')" numeric>{{ number_format($contractor->contracts_count) }}</x-ui.table.cell>

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

                            <x-ui.table.cell align="right">
                                <x-ui.dropdown align="right" :label="__('Manage :firm', ['firm' => $contractor->name])">
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" icon-only>
                                            {{ __('Manage :firm', ['firm' => $contractor->name]) }}
                                        </x-ui.button>
                                    </x-slot:trigger>

                                    @can('update', $contractor)
                                        <x-ui.dropdown.item icon="pencil-square" wire:click="edit({{ $contractor->id }})">
                                            {{ __('Edit details') }}
                                        </x-ui.dropdown.item>
                                    @endcan

                                    @can('blacklist', $contractor)
                                        @if ($contractor->is_blacklisted)
                                            <x-ui.dropdown.item icon="check-circle" wire:click="confirmBlacklist({{ $contractor->id }}, true)">
                                                {{ __('Lift blacklisting') }}
                                            </x-ui.dropdown.item>
                                        @else
                                            <x-ui.dropdown.item icon="x-circle" destructive wire:click="confirmBlacklist({{ $contractor->id }})">
                                                {{ __('Blacklist this firm') }}
                                            </x-ui.dropdown.item>
                                        @endif
                                    @endcan
                                </x-ui.dropdown>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->contractors->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->contractors" :label="__('Registry pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- Edit --}}
    <x-ui.modal name="edit-contractor" :title="__('Edit firm details')" max-width="lg">
        <div class="space-y-4">
            <x-ui.form.group name="name" :label="__('Registered firm name')" required>
                <x-ui.form.input name="name" wire:model="name" />
            </x-ui.form.group>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="rcNumber" :label="__('RC number')" optional>
                    <x-ui.form.input name="rcNumber" wire:model="rcNumber" />
                </x-ui.form.group>

                <x-ui.form.group name="firmType" :label="__('Firm type')" required>
                    <x-ui.form.select name="firmType" :options="$this->typeOptions" wire:model="firmType" />
                </x-ui.form.group>
            </div>

            <x-ui.form.group name="category" :label="__('Category')" optional>
                <x-ui.form.input name="category" wire:model="category" />
            </x-ui.form.group>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="contactName" :label="__('Contact person')" optional>
                    <x-ui.form.input name="contactName" wire:model="contactName" />
                </x-ui.form.group>

                <x-ui.form.group name="contactPhone" :label="__('Contact phone')" optional>
                    <x-ui.form.input name="contactPhone" type="tel" wire:model="contactPhone" />
                </x-ui.form.group>
            </div>

            <x-ui.form.group name="contactEmail" :label="__('Contact email')" optional>
                <x-ui.form.input name="contactEmail" type="email" wire:model="contactEmail" />
            </x-ui.form.group>

            <x-ui.form.group name="address" :label="__('Address')" optional>
                <x-ui.form.textarea name="address" rows="2" maxlength="1000" wire:model="address" />
            </x-ui.form.group>

            <p class="flex items-start gap-1.5 text-xs text-ink-muted">
                <x-ui.icon name="information-circle" class="mt-0.5 size-3.5 shrink-0" />
                {{ __('Blacklisting is not part of this form — it requires a stated reason and is applied separately.') }}
            </p>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'edit-contractor')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button icon="check-circle" wire:click="saveContractor" loading="saveContractor">{{ __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Blacklist / lift --}}
    <x-ui.modal
        name="blacklist-contractor"
        :title="$lifting ? __('Lift this blacklisting?') : __('Blacklist this firm?')"
        :description="$lifting
            ? __('The firm becomes eligible for new contract awards across every entity.')
            : __('The firm cannot be awarded new contracts by any entity. Existing contracts are unaffected.')"
        max-width="md"
    >
        <x-ui.form.group
            name="blacklistReason"
            :label="__('Reason')"
            :hint="__('Required and kept permanently — this is the record another entity will read when the firm bids there.')"
            required
        >
            <x-ui.form.textarea
                name="blacklistReason"
                rows="3"
                maxlength="1000"
                has-hint
                :placeholder="$lifting
                    ? __('e.g. Remedial works completed and verified; debarment period served.')
                    : __('e.g. Abandoned three contracts after mobilisation; confirmed by the procurement review of 12 March.')"
                wire:model="blacklistReason"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'blacklist-contractor')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                :variant="$lifting ? 'primary' : 'destructive'"
                :icon="$lifting ? 'check-circle' : 'x-circle'"
                wire:click="applyBlacklist"
                loading="applyBlacklist"
            >{{ $lifting ? __('Lift blacklisting') : __('Blacklist firm') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
