{{-- Register shape by lifecycle state. Links through to the filtered list. --}}
<x-ui.card :title="__('Register by status')" :subtitle="__('Every project this workspace is accountable for')">
    <x-slot:actions>
        <x-ui.button variant="ghost" size="sm" trailing-icon="arrow-right" :href="route('tenant.projects.index')">
            {{ __('Open the register') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.chart
        type="bar"
        :rows="$this->rows()"
        :height="260"
        :title="__('Projects by lifecycle status')"
        :empty="__('No projects registered yet')"
    />
</x-ui.card>
