{{--
    One entity's record (App\Livewire\Oversight\Tenancy\TenantSettings):
    profile, branding overrides, the activation switch, and the audit trail.
--}}
<div>
    <x-ui.page-header
        :title="$tenant->name"
        :description="__('Everything the state holds about this entity, and the switch that opens or closes its workspace.')"
        :back="route('oversight.entities.index')"
        :back-label="__('Back to the register')"
    >
        <x-slot:actions>
            @if ($tenant->is_active)
                <x-ui.badge status="approved" :label="__('Open')" />
            @else
                <x-ui.badge status="on_hold" :label="__('Suspended')" />
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That change could not be saved')">{{ $failure }}</x-ui.alert>
    @endif

    @unless ($tenant->is_active)
        <x-ui.alert variant="warning" class="mb-5" :title="__('This workspace is suspended')">
            <p>{{ __('Its subdomain does not resolve and its staff cannot sign in. Nothing has been deleted.') }}</p>
            @if ($tenant->deactivated_reason)
                <p class="mt-1">{{ __('Reason recorded: :reason', ['reason' => $tenant->deactivated_reason]) }}</p>
            @endif
        </x-ui.alert>
    @endunless

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form wire:submit="save">
                <x-ui.card :title="__('Entity record')" :subtitle="__('Who this entity is and who the state calls about it.')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.group name="name" :label="__('Entity name')" required class="sm:col-span-2">
                            <x-ui.form.input name="name" wire:model="name" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group name="shortName" :label="__('Short name')" optional>
                            <x-ui.form.input name="shortName" wire:model="shortName" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group name="type" :label="__('Entity type')" required>
                            <x-ui.form.select name="type" :options="$this->typeOptions" wire:model="type" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group name="sectorId" :label="__('Sector')" optional>
                            <x-ui.form.select name="sectorId" :placeholder="__('Not set')" :options="$this->sectorOptions" wire:model="sectorId" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="subdomain"
                            :label="__('Subdomain')"
                            :hint="__('Permanent. Every bookmark, emailed link and signed download in this entity depends on it, so it is not editable here.')"
                        >
                            <x-ui.form.input name="subdomain" :value="$tenant->slug" disabled readonly />
                        </x-ui.form.group>

                        <x-ui.form.group name="contactName" :label="__('Contact name')" optional>
                            <x-ui.form.input name="contactName" wire:model="contactName" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group name="contactEmail" :label="__('Contact email')" optional>
                            <x-ui.form.input name="contactEmail" type="email" wire:model="contactEmail" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group name="contactPhone" :label="__('Contact phone')" optional class="sm:col-span-2">
                            <x-ui.form.input name="contactPhone" type="tel" wire:model="contactPhone" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>
                    </div>
                </x-ui.card>

                <x-ui.card class="mt-4" :title="__('Branding')" :subtitle="__('What this entity\'s staff see in the sidebar and the browser tab.')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.group
                            name="displayName"
                            :label="__('Display name')"
                            :hint="__('Overrides the entity name in the workspace lockup. Leave blank to use the name above.')"
                            optional
                        >
                            <x-ui.form.input name="displayName" wire:model="displayName" :disabled="! $this->actorCanManage()" />
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="brandColor"
                            :label="__('Brand colour')"
                            :hint="__('Six-digit hex. Sets the workspace\'s interactive colour, its strong step and its tint. Blank keeps the platform palette.')"
                            optional
                        >
                            <div class="flex items-center gap-2">
                                <x-ui.form.input name="brandColor" wire:model.live="brandColor" placeholder="#0f5132" class="flex-1" :disabled="! $this->actorCanManage()" />
                                <input
                                    type="color"
                                    aria-label="{{ __('Pick the brand colour') }}"
                                    wire:model.live="brandColor"
                                    @disabled(! $this->actorCanManage())
                                    class="size-11 shrink-0 cursor-pointer rounded-lg border border-line bg-surface-raised p-1"
                                />
                            </div>
                        </x-ui.form.group>

                        <x-ui.form.group
                            name="logo"
                            :label="__('Workspace logo')"
                            :hint="__('PNG, JPEG or WebP, up to 2 MB. Shown beside the workspace name.')"
                            optional
                            class="sm:col-span-2"
                        >
                            <div class="flex flex-wrap items-center gap-4">
                                @if ($logo)
                                    <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo preview') }}" class="size-14 rounded-lg border border-line object-contain" />
                                @elseif ($this->currentLogoUrl())
                                    <img src="{{ $this->currentLogoUrl() }}" alt="{{ __('Current logo') }}" class="size-14 rounded-lg border border-line object-contain" />
                                @else
                                    <span class="flex size-14 items-center justify-center rounded-lg border border-dashed border-line text-ink-subtle" aria-hidden="true">
                                        <x-ui.icon name="photo" class="size-6" />
                                    </span>
                                @endif

                                <input
                                    type="file"
                                    name="logo"
                                    accept="image/png,image/jpeg,image/webp"
                                    wire:model="logo"
                                    @disabled(! $this->actorCanManage())
                                    class="block w-full max-w-xs text-sm text-ink-muted file:mr-3 file:rounded-lg file:border file:border-line file:bg-surface-raised file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink"
                                />

                                <span wire:loading wire:target="logo" class="text-xs text-ink-muted">{{ __('Uploading…') }}</span>
                            </div>
                        </x-ui.form.group>
                    </div>

                    @if ($this->actorCanManage())
                        <x-slot:footer>
                            <div class="flex justify-end">
                                <x-ui.button type="submit" icon="check" loading="save">{{ __('Save changes') }}</x-ui.button>
                            </div>
                        </x-slot:footer>
                    @endif
                </x-ui.card>
            </form>

            <livewire:shared.activity-timeline :model="$tenant" :heading="__('Workspace history')" />
        </div>

        <div class="space-y-4">
            <x-ui.card :title="__('Workspace address')">
                <p class="font-mono text-sm break-all text-ink">{{ $tenant->url() }}</p>
                <p class="mt-2 text-xs text-ink-muted">
                    {{ __('Staff of this entity sign in here. A suspended workspace stops resolving.') }}
                </p>
            </x-ui.card>

            @if ($this->actorCanManage())
                <x-ui.card :title="$tenant->is_active ? __('Suspend this workspace') : __('Reopen this workspace')">
                    <p class="text-sm text-ink-muted">
                        {{ $tenant->is_active
                            ? __('Closes the subdomain and signs the entity out. Projects, returns, evidence and the audit trail are all kept, and reopening restores the workspace exactly as it is now.')
                            : __('Reopens the subdomain. Nothing was removed while it was closed.') }}
                    </p>

                    <x-slot:footer>
                        <x-ui.button
                            :variant="$tenant->is_active ? 'destructive' : 'primary'"
                            :icon="$tenant->is_active ? 'pause-circle' : 'check-circle'"
                            wire:click="confirmSetActive"
                        >{{ $tenant->is_active ? __('Suspend workspace') : __('Reopen workspace') }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            @endif

            <x-ui.card :title="__('Its own rules')" :subtitle="__('State settings this entity has retuned for itself.')" flush>
                @if ($this->overrides->isEmpty())
                    <x-ui.empty-state
                        variant="empty"
                        compact
                        icon="cog"
                        :title="__('Following the state')"
                        :description="__('This entity uses every instance setting as the state sets it.')"
                    />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($this->overrides as $override)
                            <li class="px-4 py-3" wire:key="override-{{ $override['definition']->id() }}">
                                <p class="text-sm font-medium text-ink">{{ $override['definition']->label }}</p>
                                <p class="mt-0.5 text-xs text-ink-muted">
                                    {{ __('Uses :value') }}
                                    <span class="font-medium text-ink">{{ $override['definition']->display($override['value']) }}</span>
                                    <span class="text-ink-subtle">{{ __('· state default :default', ['default' => $override['definition']->display($override['inherited'])]) }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal
        name="set-tenant-active"
        :title="$tenant->is_active ? __('Suspend this workspace?') : __('Reopen this workspace?')"
        :description="$tenant->is_active
            ? __(':name\'s subdomain stops resolving and its staff cannot sign in. Nothing is deleted.', ['name' => $tenant->name])
            : __(':name becomes reachable again and its staff can sign in.', ['name' => $tenant->name])"
        max-width="lg"
    >
        @if ($tenant->is_active)
            <x-ui.form.group
                name="deactivationReason"
                :label="__('Why is it being suspended?')"
                :hint="__('Recorded in the audit log against this workspace.')"
                required
            >
                <x-ui.form.textarea name="deactivationReason" rows="3" wire:model="deactivationReason" />
            </x-ui.form.group>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'set-tenant-active')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                :variant="$tenant->is_active ? 'destructive' : 'primary'"
                :icon="$tenant->is_active ? 'pause-circle' : 'check-circle'"
                wire:click="applySetActive"
                loading="applySetActive"
            >{{ $tenant->is_active ? __('Suspend workspace') : __('Reopen workspace') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
