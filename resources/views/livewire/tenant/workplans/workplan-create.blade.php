{{--
    Open a work plan (App\Livewire\Tenant\Workplans\WorkplanCreate).

    Small on purpose: the plan's substance is its activities, which are added
    on the builder screen where the running budget total is visible.
--}}
<div>
    <x-ui.page-header
        :title="__('Open an annual work plan')"
        :description="__('Name the year and who answers for it. You will add the activities, budget lines and output indicators next.')"
        :back="route('tenant.workplans.index')"
        :backLabel="__('Back to work plans')"
        :breadcrumbs="[
            ['label' => __('Work plans'), 'href' => route('tenant.workplans.index')],
            ['label' => __('New plan')],
        ]"
    />

    <form wire:submit="save" class="max-w-3xl space-y-4">
        <x-ui.card :title="__('The year')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group name="title" :label="__('Plan title')" required class="sm:col-span-2">
                    <x-ui.form.input name="title" wire:model="title" autocomplete="off" />
                </x-ui.form.group>

                <x-ui.form.group name="year" :label="__('Year')" required :hint="__('The year the plan covers.')">
                    <x-ui.form.input name="year" type="number" min="2000" max="2100" has-hint wire:model.live="year" />
                </x-ui.form.group>

                <x-ui.form.group name="yearBasis" :label="__('Year basis')" required>
                    <x-ui.form.select
                        name="yearBasis"
                        :options="$this->basisOptions"
                        wire:model.live="yearBasis"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="periodStart"
                    :label="__('Period starts')"
                    required
                    :hint="__('Derived from the year and basis — change it if this entity runs a different window.')"
                >
                    <x-ui.form.input name="periodStart" type="date" has-hint wire:model="periodStart" />
                </x-ui.form.group>

                <x-ui.form.group name="periodEnd" :label="__('Period ends')" required>
                    <x-ui.form.input name="periodEnd" type="date" wire:model="periodEnd" />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Accountability')">
            <div class="space-y-4">
                <x-ui.form.group
                    name="ownerId"
                    :label="__('Plan owner')"
                    required
                    :hint="__('The officer who answers for delivery of this plan.')"
                >
                    <x-ui.form.select
                        name="ownerId"
                        :placeholder="__('Choose an officer')"
                        :options="$this->memberOptions"
                        has-hint
                        wire:model="ownerId"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="narrative"
                    :label="__('Narrative')"
                    optional
                    :hint="__('Strategy, assumptions and how the plan connects to the results framework.')"
                >
                    <x-ui.form.textarea name="narrative" rows="5" maxlength="5000" has-hint wire:model="narrative" />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit" icon="check" loading="save">{{ __('Open work plan') }}</x-ui.button>
            <x-ui.button variant="ghost" :href="route('tenant.workplans.index')">{{ __('Cancel') }}</x-ui.button>
        </div>
    </form>
</div>
