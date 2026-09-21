{{--
    Lifecycle strip for a project
    (App\Livewire\Tenant\Lifecycle\ProjectLifecyclePanel) — embeddable:

        <livewire:tenant.lifecycle.project-lifecycle-panel :project="$project" />

    Two facts and two doors. Everything actionable lives behind the links.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $commencement = $this->commencement;
    $certificates = $this->certificates;
@endphp

<x-ui.card :title="__('Monitoring lifecycle')">
    <div class="grid gap-4 sm:grid-cols-2">
        {{-- Commencement ------------------------------------------------ --}}
        <div class="rounded-lg border border-line p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Commencement') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">
                        @if ($commencement['awards'] === 0)
                            {{ __('No award yet') }}
                        @else
                            {{ __(':served of :awards served', [
                                'served' => $commencement['served'],
                                'awards' => $commencement['awards'],
                            ]) }}
                        @endif
                    </p>
                </div>

                @if ($commencement['overdue'] > 0)
                    <x-ui.badge status="overdue" size="sm" :label="__('Overdue')" />
                @elseif ($commencement['awards'] > 0 && $commencement['served'] === $commencement['awards'])
                    <x-ui.badge status="approved" size="sm" :label="__('Served')" />
                @elseif ($commencement['awards'] > 0)
                    <x-ui.badge status="pending" size="sm" :label="__('Outstanding')" />
                @endif
            </div>

            @if ($commencement['overdue'] > 0)
                <p class="mt-1 text-xs text-critical-ink">
                    {{ trans_choice(
                        '{1} :count award is past its statutory notice window.|[2,*] :count awards are past their statutory notice window.',
                        $commencement['overdue'],
                        ['count' => $commencement['overdue']],
                    ) }}
                </p>
            @elseif ($commencement['next_due'])
                <p class="mt-1 text-xs text-ink-muted">
                    {{ __('Notice due :date', ['date' => $commencement['next_due']->translatedFormat('j M Y')]) }}
                </p>
            @endif

            <div class="mt-3">
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="paper-airplane"
                    :href="route('tenant.projects.commencement', [...$workspace, 'project' => $project])"
                >{{ __('Commencement notices') }}</x-ui.button>
            </div>
        </div>

        {{-- Certification ----------------------------------------------- --}}
        <div class="rounded-lg border border-line p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Certification') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">
                        @if ($certificates->isEmpty())
                            {{ __('Not certified') }}
                        @else
                            {{ $certificates->first()->type->shortLabel() }}
                        @endif
                    </p>
                </div>

                @if ($certificates->isNotEmpty())
                    <x-ui.badge status="certified" size="sm" :label="__('In force')" />
                @endif
            </div>

            @if ($certificates->isNotEmpty())
                <p class="mt-1 font-mono text-xs text-ink-muted">{{ $certificates->first()->reference }}</p>

                @if ($certificates->first()->isUnderDefectsLiability())
                    <p class="mt-1 text-xs text-ink-muted">
                        {{ __('Defects liability to :date', [
                            'date' => $certificates->first()->defects_liability_ends_on->translatedFormat('j M Y'),
                        ]) }}
                    </p>
                @endif
            @else
                <p class="mt-1 text-xs text-ink-muted">
                    {{ __('A certificate is issued once the works are recorded as complete.') }}
                </p>
            @endif

            <div class="mt-3">
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="document-check"
                    :href="route('tenant.projects.certify', [...$workspace, 'project' => $project])"
                >{{ __('Certification') }}</x-ui.button>
            </div>
        </div>
    </div>
</x-ui.card>
