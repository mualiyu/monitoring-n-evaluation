{{--
    Schedule a site visit (App\Livewire\Tenant\Inspections\InspectionSchedule).

    One screen, not a wizard: this is six fields an officer fills between two
    meetings. The multi-step treatment belongs to the conduct form, which is
    filled in standing on a building site.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $selectedType = \App\Enums\InspectionType::tryFrom($type);
@endphp

<div>
    <x-ui.page-header
        :title="__('Schedule a site visit')"
        :description="__('A visit produces a Field Trip Report. Name who is going and when, and the platform will chase the report if it does not arrive.')"
        :back="route('tenant.inspections.index', $workspace)"
        :back-label="__('Back to inspections')"
    />

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be scheduled')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <form wire:submit="schedule" class="space-y-4">
        <x-ui.card :title="__('What is being inspected')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="projectUlid"
                    :label="__('Project')"
                    :hint="__('Only projects you may see, and only those past the draft stage.')"
                    required
                >
                    <x-ui.form.select
                        name="projectUlid"
                        has-hint
                        :placeholder="__('Choose a project…')"
                        :options="$this->projectOptions"
                        wire:model.live="projectUlid"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="locationId"
                    :label="__('Site')"
                    :hint="__('Multi-site projects: which site is being visited. Leave blank for the project as a whole.')"
                >
                    <x-ui.form.select
                        name="locationId"
                        has-hint
                        :placeholder="__('The project as a whole')"
                        :options="$this->locationOptions"
                        wire:model="locationId"
                    />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('What kind of visit')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="type"
                    :label="__('Visit type')"
                    :hint="$selectedType?->purpose()"
                    required
                >
                    <x-ui.form.select
                        name="type"
                        has-hint
                        :options="$this->typeOptions"
                        wire:model.live="type"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="templateId"
                    :label="__('Checklist')"
                    :hint="__('The state’s instrument for this kind of visit. Every MDA answers the same questions, which is what makes the answers comparable.')"
                >
                    <x-ui.form.select
                        name="templateId"
                        has-hint
                        :placeholder="__('No checklist')"
                        :options="$this->templateOptions"
                        wire:model="templateId"
                    />
                </x-ui.form.group>
            </div>

            @if (empty($this->templateOptions))
                <x-ui.alert variant="neutral" class="mt-4">
                    {{ __('No checklist has been published for this kind of visit. The inspector will still file a full Field Trip Report.') }}
                </x-ui.alert>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Who is going, and when')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.group
                    name="scheduledDate"
                    :label="__('Visit date')"
                    required
                >
                    <x-ui.form.input
                        name="scheduledDate"
                        type="date"
                        wire:model="scheduledDate"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="leadInspectorId"
                    :label="__('Lead inspector')"
                    :hint="__('Only officers who can conduct a visit are listed — and the person who inspects can never sign off their own report.')"
                    required
                >
                    <x-ui.form.select
                        name="leadInspectorId"
                        has-hint
                        :placeholder="__('Choose an inspector…')"
                        :options="$this->inspectorOptions"
                        wire:model="leadInspectorId"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-4 space-y-4">
                <x-ui.form.group
                    name="team"
                    :label="__('Accompanying team')"
                    :hint="__('Names and designations of everyone attending, including people who are not platform users.')"
                >
                    <x-ui.form.textarea
                        name="team"
                        rows="2"
                        maxlength="1000"
                        has-hint
                        :placeholder="__('e.g. Engr. A. Bello (works), Mrs T. Okon (M&amp;E), community representative')"
                        wire:model="team"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="objectives"
                    :label="__('Objectives')"
                    :hint="__('Section 1 of the Field Trip Report. Left blank, the purpose of this visit type is used.')"
                >
                    <x-ui.form.textarea
                        name="objectives"
                        rows="3"
                        maxlength="2000"
                        has-hint
                        :placeholder="$selectedType?->purpose()"
                        wire:model="objectives"
                    />
                </x-ui.form.group>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button variant="secondary" :href="route('tenant.inspections.index', $workspace)">
                {{ __('Cancel') }}
            </x-ui.button>

            <x-ui.button type="submit" icon="calendar-days" loading="schedule">
                {{ __('Schedule the visit') }}
            </x-ui.button>
        </div>
    </form>
</div>
