{{--
    State-wide KPI row. Each tile reaches the screen that explains it.
    A user without oversight portfolio authority gets nothing here rather than
    an error page — the screens behind the tiles do the refusing.

    ONE element root, always: with @if at the root, Livewire's block marker
    lands before the tiles and the component's wire:id is injected into the
    first tile instead, so the lazy load morphs the wrong node ("Snapshot
    missing" in the console, and a KPI row that can never refresh).
--}}
@php
    $summary = $this->summary();
@endphp

<div>
    @if ($summary === null)
        <x-ui.card>
            <x-ui.empty-state
                compact
                icon="shield-check"
                :title="__('Portfolio figures are not available to your role')"
                :description="__('State-wide aggregates require oversight portfolio authority.')"
            />
        </x-ui.card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Entities reporting')"
                :value="$summary['tenants_reporting'].' / '.$summary['tenant_count']"
                icon="building-office"
                :hint="__('workspaces with projects under monitoring')"
                :href="route('oversight.portfolio.index')"
            />

            <x-ui.stat
                :label="__('Projects under monitoring')"
                :value="number_format($summary['project_count'])"
                icon="folder"
                :hint="__('across all sectors')"
                :href="route('oversight.portfolio.index')"
            />

            <x-ui.stat
                :label="__('Portfolio value')"
                :value="$this->money($summary['contract_value'])"
                icon="banknotes"
                :hint="__(':spent spent to date', ['spent' => $this->money($summary['expenditure'])])"
                :href="route('oversight.portfolio.index')"
            />

            <x-ui.stat
                :label="__('Reporting compliance')"
                :value="$summary['compliance_rate'] !== null ? $summary['compliance_rate'].'%' : '—'"
                icon="clipboard-check"
                :intent="$summary['compliance_rate'] !== null && $summary['compliance_rate'] < 80 ? 'warning' : 'positive'"
                :hint="$summary['period']
                    ? __(':period · :ontime% on time', [
                        'period' => $summary['period']->label,
                        'ontime' => $summary['on_time_rate'] ?? 0,
                    ])
                    : __('no closed reporting period yet')"
                :href="route('oversight.compliance.index')"
            />
        </div>
    @endif
</div>
