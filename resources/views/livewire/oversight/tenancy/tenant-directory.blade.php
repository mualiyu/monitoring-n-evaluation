{{--
    Workspace register, oversight surface (App\Livewire\Oversight\Tenancy\TenantDirectory).
    Filter bar → stat row → table → pagination, per the design system.
--}}
<div>
    <x-ui.page-header
        :title="__('Entities & workspaces')"
        :description="__('Every entity on the platform, the subdomain it works from, and what it is carrying. Suspending a workspace closes its subdomain and deletes nothing.')"
    >
        <x-slot:actions>
            @if ($this->actorCanManage())
                <x-ui.button icon="plus" :href="route('oversight.entities.create')">
                    {{ __('Onboard an entity') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That action could not be completed')">{{ $failure }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat
            :label="__('Workspaces')"
            :value="number_format($this->summary['total'])"
            icon="building-office"
            :hint="__('Onboarded onto this instance')"
        />
        <x-ui.stat
            :label="__('Open')"
            :value="number_format($this->summary['active'])"
            icon="check-circle"
            :hint="__('Reachable on their subdomain today')"
        />
        <x-ui.stat
            :label="__('Suspended')"
            :value="number_format($this->summary['suspended'])"
            icon="pause-circle"
            :intent="$this->summary['suspended'] > 0 ? 'critical' : 'neutral'"
            :hint="__('Closed, with every record intact')"
        />
    </div>

    <x-ui.card class="mb-4" flush>
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.group name="search" :label="__('Search')">
                <x-ui.form.input
                    name="search"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('Name or subdomain…')"
                    wire:model.live.debounce.300ms="search"
                />
            </x-ui.form.group>

            <x-ui.form.group name="type" :label="__('Entity type')">
                <x-ui.form.select
                    name="type"
                    :placeholder="__('All types')"
                    :options="$this->typeOptions"
                    wire:model.live="type"
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

            <x-ui.form.group name="status" :label="__('Status')">
                <x-ui.form.select
                    name="status"
                    :placeholder="__('Open and suspended')"
                    :options="$this->statusOptions"
                    wire:model.live="status"
                />
            </x-ui.form.group>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,type,sector,status,gotoPage,previousPage,nextPage">
            @if ($this->workspaces->isEmpty())
                <x-ui.empty-state
                    :variant="$search !== '' || $type !== '' || $sector !== '' || $status !== '' ? 'filtered' : 'empty'"
                    icon="building-office"
                    :title="__('No workspaces here yet')"
                    :description="__('An entity gets a subdomain, a first administrator and its own set of records the moment it is onboarded.')"
                >
                    @if ($this->actorCanManage())
                        <x-ui.button icon="plus" :href="route('oversight.entities.create')">
                            {{ __('Onboard an entity') }}
                        </x-ui.button>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.table
                    :caption="__('Entities and their workspaces')"
                    class="p-4 sm:p-0"
                    :headings="[__('Entity'), __('Subdomain'), __('Type'), __('Sector'), __('People'), __('Projects'), __('Status'), __('Onboarded'), '']"
                >
                    @foreach ($this->workspaces as $workspace)
                        <x-ui.table.row wire:key="workspace-{{ $workspace->ulid }}" :muted="! $workspace->is_active">
                            <x-ui.table.cell :label="__('Entity')" primary>
                                <a
                                    href="{{ route('oversight.entities.show', $workspace) }}"
                                    class="rounded underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $workspace->name }}</a>
                                @if ($workspace->short_name)
                                    <span class="block text-xs font-normal text-ink-muted">{{ $workspace->short_name }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Subdomain')">
                                <span class="font-mono text-xs text-ink-muted">{{ $workspace->slug }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Type')">
                                <span class="text-ink-muted">{{ $workspace->type->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Sector')">
                                <span class="text-ink-muted">{{ $workspace->sector?->name ?? '—' }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('People')" numeric>{{ number_format((int) $workspace->users_count) }}</x-ui.table.cell>

                            <x-ui.table.cell :label="__('Projects')" numeric>{{ number_format((int) $workspace->projects_count) }}</x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                @if ($workspace->is_active)
                                    <x-ui.badge status="approved" :label="__('Open')" size="sm" />
                                @else
                                    <x-ui.badge status="on_hold" :label="__('Suspended')" size="sm" />
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Onboarded')">
                                <span class="text-ink-muted">
                                    {{ $workspace->onboarded_at ? \App\Support\InstanceTime::local($workspace->onboarded_at)->format('j M Y') : \App\Support\InstanceTime::local($workspace->created_at)->format('j M Y') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <span class="inline-flex items-center gap-1">
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="cog"
                                        :href="route('oversight.entities.show', $workspace)"
                                    >{{ __('Open') }}</x-ui.button>

                                    @if ($this->actorCanManage())
                                        @if ($workspace->is_active)
                                            <x-ui.button
                                                variant="ghost"
                                                size="sm"
                                                icon="pause-circle"
                                                wire:click="confirmSetActive('{{ $workspace->ulid }}', false)"
                                            >{{ __('Suspend') }}</x-ui.button>
                                        @else
                                            <x-ui.button
                                                variant="ghost"
                                                size="sm"
                                                icon="check-circle"
                                                wire:click="confirmSetActive('{{ $workspace->ulid }}', true)"
                                            >{{ __('Reopen') }}</x-ui.button>
                                        @endif
                                    @endif
                                </span>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->workspaces->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->workspaces" :label="__('Register pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- Suspend / reopen confirmation --}}
    <x-ui.modal
        name="set-tenant-active"
        :title="$togglingTo ? __('Reopen this workspace?') : __('Suspend this workspace?')"
        :description="$this->togglingWorkspace
            ? ($togglingTo
                ? __(':name becomes reachable again at its subdomain and its staff can sign in. Nothing was removed while it was closed.', ['name' => $this->togglingWorkspace->name])
                : __(':name\'s subdomain stops resolving and its staff cannot sign in. Its projects, returns, evidence and audit trail are all kept, and reopening restores the workspace exactly as it is now.', ['name' => $this->togglingWorkspace->name]))
            : null"
        max-width="lg"
    >
        @unless ($togglingTo)
            <x-ui.form.group
                name="togglingReason"
                :label="__('Why is it being suspended?')"
                :hint="__('Recorded in the audit log against this workspace.')"
                required
            >
                <x-ui.form.textarea name="togglingReason" rows="3" wire:model="togglingReason" />
            </x-ui.form.group>
        @endunless

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'set-tenant-active')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                :variant="$togglingTo ? 'primary' : 'destructive'"
                :icon="$togglingTo ? 'check-circle' : 'pause-circle'"
                wire:click="applySetActive"
                loading="applySetActive"
            >{{ $togglingTo ? __('Reopen workspace') : __('Suspend workspace') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
