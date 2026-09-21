{{-- Dashboard widget — what is owed next (App\Livewire\Tenant\Reporting\UpcomingDeadlinesCard). --}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $fileUrl = fn ($obligation) => route('tenant.reports.create', [
        ...$workspace,
        'project' => $obligation->project->ulid,
        'period' => $obligation->reporting_period_id,
    ]);
@endphp

<div>
    <x-ui.card
        :title="__('Upcoming deadlines')"
        :subtitle="__('Reporting obligations due in the next 30 days — and anything already past its deadline')"
    >
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" trailing-icon="chevron-right" :href="route('tenant.reports.calendar', $workspace)">
                {{ __('Full list') }}
            </x-ui.button>
        </x-slot:actions>

        @if ($this->obligations->isEmpty())
            <x-ui.empty-state
                compact
                icon="calendar-days"
                :title="__('Nothing due in the next 30 days')"
                :description="__('Obligations are generated nightly from the state reporting calendar for every project under execution.')"
            />
        @else
            <ul class="divide-y divide-line">
                @foreach ($this->obligations as $obligation)
                    @php
                        $daysToDue = $obligation->daysToDue();
                        $isOverdue = $obligation->isOverdue();
                    @endphp

                    <li class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            @if ($obligation->project)
                                <a
                                    href="{{ $fileUrl($obligation) }}"
                                    class="rounded text-sm font-medium text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $obligation->project->title }}</a>
                            @else
                                <span class="text-sm font-medium text-ink">{{ __('Entity-level return') }}</span>
                            @endif

                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ $obligation->reportingPeriod->label }}
                                · {{ __('due :date', ['date' => $obligation->due_at->translatedFormat('j M Y')]) }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            {{-- Status by icon + text, never colour alone. --}}
                            @if ($isOverdue)
                                <x-ui.badge status="overdue" size="sm" />
                            @elseif ($daysToDue <= 7)
                                <x-ui.badge
                                    status="pending"
                                    size="sm"
                                    icon="clock"
                                    :label="$daysToDue === 0
                                        ? __('Due today')
                                        : trans_choice('{1} :count day left|[2,*] :count days left', $daysToDue, ['count' => $daysToDue])"
                                />
                            @else
                                <span class="text-xs text-ink-muted">
                                    {{ trans_choice('{1} in :count day|[2,*] in :count days', $daysToDue, ['count' => $daysToDue]) }}
                                </span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
