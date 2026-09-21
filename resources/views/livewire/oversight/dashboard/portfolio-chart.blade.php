{{-- Who is carrying the portfolio. Links through to the cross-MDA register. --}}
<x-ui.card :title="__('Projects by entity')" :subtitle="__('Across every workspace on this instance')">
    <x-slot:actions>
        <x-ui.button variant="ghost" size="sm" trailing-icon="arrow-right" :href="route('oversight.portfolio.index')">
            {{ __('Open the portfolio') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.chart
        type="bar"
        :rows="$this->rows()"
        :height="280"
        :title="__('Projects under monitoring, by entity')"
        :empty="__('No entity has registered a project yet')"
    />
</x-ui.card>
