{{--
    Live workspace KPIs. Every tile links to the list that explains it — the
    design system's rule, and the difference between a number and an answer.
--}}
@php
    $summary = $this->summary();
@endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-ui.stat
        :label="__('Active projects')"
        :value="number_format($summary['active_projects'])"
        icon="folder"
        :hint="__(':total in the register', ['total' => number_format($summary['project_count'])])"
        :href="route('tenant.projects.index', ['status' => 'in_progress'])"
    />

    <x-ui.stat
        :label="__('Contract value monitored')"
        :value="$summary['contract_value']->format()"
        icon="banknotes"
        :hint="__(':spent spent to date', ['spent' => $summary['expenditure']->format()])"
        :href="route('tenant.projects.index')"
    />

    <x-ui.stat
        :label="__('Reports awaiting action')"
        :value="number_format($summary['awaiting_review'] + $summary['awaiting_approval'])"
        icon="document-text"
        :intent="($summary['awaiting_review'] + $summary['awaiting_approval']) > 0 ? 'warning' : 'neutral'"
        :hint="__(':review to review · :approve to approve', [
            'review' => number_format($summary['awaiting_review']),
            'approve' => number_format($summary['awaiting_approval']),
        ])"
        :href="route('tenant.reports.index')"
    />

    <x-ui.stat
        :label="__('Overdue submissions')"
        :value="number_format($summary['obligations_overdue'])"
        icon="exclamation-triangle"
        :intent="$summary['obligations_overdue'] > 0 ? 'critical' : 'positive'"
        :hint="__(':outstanding returns outstanding', ['outstanding' => number_format($summary['obligations_outstanding'])])"
        :href="route('tenant.reports.index')"
    />
</div>
