{{--
    Raise a challenge (App\Livewire\Tenant\Issues\IssueCreate).

    ONE SCREEN, NO WIZARD: the person filling this in is often on a site on a
    phone, and the register's value depends on the raise being cheap enough
    that it actually happens. Four required fields; the plan and the owner can
    come later from whoever picks it up.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
@endphp

<div>
    <x-ui.page-header
        :title="__('Raise a challenge')"
        :description="__('Record what is blocking delivery. Anyone who sees a problem can record it — clearing it is somebody else\'s job, and that separation is deliberate.')"
        :back="route('tenant.issues.index', $workspace)"
        :back-label="__('Back to the register')"
    />

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-4">
        <x-ui.card :title="__('What is blocking delivery')">
            <div class="space-y-4">
                <x-ui.form.group
                    name="projectUlid"
                    :label="__('Project')"
                    :hint="__('The project this obstruction is holding up.')"
                    required
                >
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('Choose a project…')"
                        :options="$this->projectOptions"
                        has-hint
                        wire:model="projectUlid"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="title"
                    :label="__('Title')"
                    :hint="__('One line. It is what every board and every escalation email shows.')"
                    required
                >
                    <x-ui.form.input
                        name="title"
                        maxlength="255"
                        has-hint
                        :placeholder="__('e.g. Access road impassable after culvert collapse')"
                        wire:model.blur="title"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="description"
                    :label="__('What is happening')"
                    :hint="__('Enough that somebody who was not there can act on it.')"
                    required
                >
                    <x-ui.form.textarea
                        name="description"
                        rows="4"
                        maxlength="5000"
                        has-hint
                        :placeholder="__('e.g. The culvert at chainage 2+150 failed after three days of rain and the haul route is cut. No material has reached the site since Monday.')"
                        wire:model.blur="description"
                    />
                </x-ui.form.group>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.group
                        name="category"
                        :label="__('Category')"
                        :hint="__('What kind of obstruction — this is what makes the register answerable across projects.')"
                        required
                    >
                        <x-ui.form.select
                            name="category"
                            :placeholder="__('Choose a category…')"
                            :options="$this->categoryOptions()"
                            has-hint
                            wire:model="category"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group
                        name="severity"
                        :label="__('Severity')"
                        :hint="__('Drives how long it may sit open before it escalates. An M&E officer can revise it.')"
                        required
                    >
                        <x-ui.form.select
                            name="severity"
                            :options="$this->severityOptions()"
                            has-hint
                            wire:model="severity"
                        />
                    </x-ui.form.group>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card
            :title="__('Corrective action')"
            :subtitle="__('Optional — fill it in now if you already know, or leave it for whoever picks the issue up.')"
        >
            <div class="space-y-4">
                <x-ui.form.group
                    name="ownerId"
                    :label="__('Owner')"
                    :hint="__('Who is accountable for clearing it. An issue with no owner is nobody\'s to clear.')"
                    optional
                >
                    <x-ui.form.select
                        name="ownerId"
                        :placeholder="__('Nobody yet')"
                        :options="$this->ownerOptions"
                        has-hint
                        wire:model="ownerId"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="correctiveAction" :label="__('What will be done')" optional>
                    <x-ui.form.textarea
                        name="correctiveAction"
                        rows="3"
                        maxlength="2000"
                        :placeholder="__('e.g. Temporary bailey crossing to be installed; LGA works department to mobilise.')"
                        wire:model.blur="correctiveAction"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="dueDate"
                    :label="__('Due by')"
                    :hint="__('An issue with no deadline is never flagged as overdue.')"
                    optional
                >
                    <x-ui.form.input
                        name="dueDate"
                        type="date"
                        :min="$this->today()"
                        has-hint
                        wire:model="dueDate"
                    />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <x-ui.button variant="secondary" :href="route('tenant.issues.index', $workspace)">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="submit" icon="plus" loading="save">
                {{ __('Raise the issue') }}
            </x-ui.button>
        </div>
    </form>
</div>
