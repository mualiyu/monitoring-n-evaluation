{{--
    Workspace onboarding wizard (App\Livewire\Oversight\Tenancy\TenantOnboarding).
    Three steps; the subdomain is checked as it is typed because it is the one
    field that cannot be changed afterwards.
--}}
<div>
    <x-ui.page-header
        :title="__('Onboard an entity')"
        :description="__('Three short steps. The entity gets its own subdomain, its own records and one administrator who can invite the rest of the team.')"
        :back="route('oversight.entities.index')"
        :back-label="__('Back to the register')"
    />

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('The workspace could not be created')">{{ $failure }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.steps
            :current="$step"
            :label="__('Onboarding progress')"
            class="mb-6"
            :steps="[
                ['label' => __('Identity'), 'description' => __('Name and subdomain')],
                ['label' => __('Who to call'), 'description' => __('Contact details')],
                ['label' => __('First administrator'), 'description' => __('Who runs the workspace')],
            ]"
        />

        {{-- Step 1 — identity ---------------------------------------- --}}
        @if ($step === 1)
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="name"
                    :label="__('Entity name')"
                    :hint="__('The full official name, as it appears on letterhead.')"
                    required
                    class="sm:col-span-2"
                >
                    <x-ui.form.input name="name" wire:model.live.debounce.400ms="name" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="shortName"
                    :label="__('Short name')"
                    :hint="__('Used in the sidebar and in breadcrumbs where space is tight.')"
                    optional
                >
                    <x-ui.form.input name="shortName" wire:model="shortName" />
                </x-ui.form.group>

                <x-ui.form.group name="type" :label="__('Entity type')" required>
                    <x-ui.form.select name="type" :options="$this->typeOptions" wire:model="type" />
                </x-ui.form.group>

                <x-ui.form.group
                    name="slug"
                    :label="__('Subdomain')"
                    :hint="__('Permanent. Lowercase letters, digits and hyphens only — this becomes the workspace address.')"
                    required
                    class="sm:col-span-2"
                >
                    <x-ui.form.input name="slug" wire:model.live.debounce.400ms="slug" />
                    <p class="mt-1.5 flex items-center gap-1.5 text-xs text-ink-muted">
                        <x-ui.icon name="globe" class="size-3.5" />
                        <span class="font-mono">{{ $this->previewUrl() }}</span>
                    </p>
                </x-ui.form.group>

                <x-ui.form.group
                    name="sectorId"
                    :label="__('Sector')"
                    :hint="__('How this entity is grouped on state-wide dashboards.')"
                    optional
                >
                    <x-ui.form.select name="sectorId" :placeholder="__('Not set')" :options="$this->sectorOptions" wire:model="sectorId" />
                </x-ui.form.group>
            </div>
        @endif

        {{-- Step 2 — contact ----------------------------------------- --}}
        @if ($step === 2)
            <x-ui.alert variant="info" class="mb-5" :title="__('Who does the state call?')">
                {{ __('These details are for the secretariat, not for sign-in. They appear on the entity record when a return is late or an exception escalates.') }}
            </x-ui.alert>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="contactName" :label="__('Contact name')" optional class="sm:col-span-2">
                    <x-ui.form.input name="contactName" wire:model="contactName" />
                </x-ui.form.group>

                <x-ui.form.group name="contactEmail" :label="__('Contact email')" optional>
                    <x-ui.form.input name="contactEmail" type="email" :placeholder="__('name@example.gov.ng')" wire:model="contactEmail" />
                </x-ui.form.group>

                <x-ui.form.group name="contactPhone" :label="__('Contact phone')" optional>
                    <x-ui.form.input name="contactPhone" type="tel" wire:model="contactPhone" />
                </x-ui.form.group>
            </div>
        @endif

        {{-- Step 3 — first administrator ------------------------------ --}}
        @if ($step === 3)
            <x-ui.alert variant="info" class="mb-5" :title="__('One invitation, then they staff it themselves')">
                {{ __('The address below is invited as :role. They can then invite M&E officers, consultants and field monitors into this workspace — the state does not have to.', ['role' => $this->administratorRoleLabel()]) }}
            </x-ui.alert>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="administratorEmail"
                    :label="__('Administrator email')"
                    :hint="__('Leave blank to provision the workspace now and appoint someone later.')"
                    optional
                    class="sm:col-span-2"
                >
                    <x-ui.form.input name="administratorEmail" type="email" :placeholder="__('name@example.gov.ng')" wire:model="administratorEmail" />
                </x-ui.form.group>
            </div>

            <dl class="mt-6 grid gap-3 rounded-lg border border-line bg-surface-sunken p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-ink-muted">{{ __('Entity') }}</dt>
                    <dd class="font-medium text-ink">{{ $name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-muted">{{ __('Workspace address') }}</dt>
                    <dd class="font-mono text-ink">{{ $this->previewUrl() }}</dd>
                </div>
            </dl>
        @endif

        <x-slot:footer>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-ui.button
                    variant="secondary"
                    icon="arrow-left"
                    wire:click="back"
                    :disabled="$step === 1"
                >{{ __('Back') }}</x-ui.button>

                @if ($step < 3)
                    <x-ui.button trailing-icon="arrow-right" wire:click="next" loading="next">{{ __('Continue') }}</x-ui.button>
                @else
                    <x-ui.button icon="building-office" wire:click="provision" loading="provision">
                        {{ __('Create the workspace') }}
                    </x-ui.button>
                @endif
            </div>
        </x-slot:footer>
    </x-ui.card>
</div>
