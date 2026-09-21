{{-- Dashboard widget — latest returns (App\Livewire\Tenant\Reporting\RecentReportsCard). --}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $reportUrl = fn ($report) => route('tenant.reports.show', [...$workspace, 'report' => $report]);
@endphp

<div>
    <x-ui.card :title="__('Recent progress reports')" :subtitle="__('The last five returns filed in this workspace')">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" trailing-icon="chevron-right" :href="route('tenant.reports.index', $workspace)">
                {{ __('View all') }}
            </x-ui.button>
        </x-slot:actions>

        @if ($this->reports->isEmpty())
            <x-ui.empty-state
                compact
                icon="document-text"
                :title="__('No returns filed yet')"
                :description="__('Once a return is filed against a reporting window, the most recent ones appear here for review.')"
            >
                <x-slot:actions>
                    @can('create', \App\Models\ProgressReport::class)
                        <x-ui.button size="sm" icon="plus" :href="route('tenant.reports.create', $workspace)">
                            {{ __('Start a report') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-line">
                @foreach ($this->reports as $report)
                    <li class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <a
                                href="{{ $reportUrl($report) }}"
                                class="rounded text-sm font-medium text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            >{{ $report->project->title }}</a>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ $report->reportingPeriod->label }}
                                · {{ $report->physical_progress_claimed }}%
                                · {{ $report->period_expenditure->format() }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <x-ui.badge :status="$report->status->value" size="sm" />
                            @if ($report->submitted_late)
                                <span class="mt-1 block">
                                    <x-ui.badge status="overdue" size="sm" :label="__('Late')" />
                                </span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
