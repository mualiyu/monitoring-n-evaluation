{{--
    Evaluation register (App\Livewire\Tenant\Evaluation\EvaluationIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination.
--}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $showUrl = fn ($evaluation) => route('tenant.evaluations.show', [...$workspace, 'evaluation' => $evaluation]);
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);

    $canCreate = auth()->user()?->can('create', \App\Models\Evaluation::class) ?? false;
    $scoreMax = $this->scoreMax();
@endphp

<div>
    <x-ui.page-header
        :title="__('Evaluations')"
        :description="__('Commissioned studies of what this entity has delivered — why it was done, what it found, and what it recommended. Every recommendation raised here is tracked on the follow-up register until it is implemented or closed.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="clipboard-check"
                :href="route('tenant.recommendations.index', $workspace)"
            >{{ __('Follow-up register') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export') }}</x-ui.button>

            @if ($canCreate)
                <x-ui.button size="sm" icon="plus" :href="route('tenant.evaluations.create', $workspace)">
                    {{ __('Commission an evaluation') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Summary row — the state of the register, deliberately NOT filtered --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Evaluations under way')"
            :value="number_format($this->stats['live'])"
            icon="clipboard-check"
            :hint="__('commissioned and not yet settled')"
        />
        <x-ui.stat
            :label="__('Awaiting approval')"
            :value="number_format($this->stats['awaiting_approval'])"
            icon="eye"
            :intent="$this->stats['awaiting_approval'] > 0 ? 'warning' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Reports past their date')"
            :value="number_format($this->stats['overdue'])"
            icon="exclamation-triangle"
            :intent="$this->stats['overdue'] > 0 ? 'critical' : 'neutral'"
            :hint="$this->stats['overdue'] > 0 ? __('the report deadline has passed') : __('nothing overdue')"
        />
        <x-ui.stat
            :label="__('Findings settled')"
            :value="number_format($this->stats['settled'])"
            icon="check-circle"
            :hint="__('approved or published')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Title, subject or sponsor…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="type" :label="__('Evaluation type')">
                    <x-ui.form.select
                        name="type"
                        :placeholder="__('Any type')"
                        :options="$this->typeOptions"
                        wire:model.live="type"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->statusOptions"
                        wire:model.live="status"
                    />
                </x-ui.form.group>

                <div class="flex items-end pb-1">
                    <x-ui.form.checkbox
                        name="overdue"
                        :label="__('Report overdue only')"
                        :description="__('Past its report deadline and not yet approved.')"
                        wire:model.live="overdue"
                    />
                </div>
            </div>

            @if ($this->hasFilters())
                <div class="mt-3 flex justify-end">
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The register                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,type,overdue">
            @if ($this->evaluations->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No evaluations match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="clipboard-check"
                        :title="__('No evaluations commissioned yet')"
                        :description="__('An evaluation asks whether what was delivered was worth delivering. Commission one against a project at its half-way point, at completion, or against a programme as a whole.')"
                    >
                        <x-slot:actions>
                            @if ($canCreate)
                                <x-ui.button icon="plus" :href="route('tenant.evaluations.create', $workspace)">
                                    {{ __('Commission an evaluation') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Evaluations commissioned by this entity, with their status and headline score')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Evaluation'),
                        __('Type'),
                        __('Report due'),
                        ['label' => __('Overall score'), 'align' => 'right'],
                        __('Status'),
                        '',
                    ]"
                >
                    @foreach ($this->evaluations as $evaluation)
                        @php
                            $score = $evaluation->overallScore();
                            $isOverdue = $evaluation->isReportOverdue();
                        @endphp

                        <x-ui.table.row wire:key="evaluation-{{ $evaluation->ulid }}">
                            <x-ui.table.cell :label="__('Evaluation')" primary>
                                <a
                                    href="{{ $showUrl($evaluation) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $evaluation->title }}</a>

                                <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                    @if ($evaluation->project)
                                        <a
                                            href="{{ $projectUrl($evaluation->project) }}"
                                            class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        >{{ $evaluation->project->title }}</a>
                                    @else
                                        {{ $evaluation->subjectLabel() }}
                                    @endif
                                    @if ($evaluation->lead)
                                        &middot; {{ __('led by :name', ['name' => $evaluation->lead->displayName()]) }}
                                    @endif
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Type')">
                                <span class="text-ink-muted">{{ $evaluation->type->label() }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Report due')">
                                @if ($evaluation->report_due_on)
                                    <span @class(['font-medium text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                                        {{ $evaluation->report_due_on->translatedFormat('j M Y') }}
                                    </span>
                                    @if ($isOverdue)
                                        <span class="mt-0.5 block text-xs text-critical-ink">
                                            {{ __('report is overdue') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-subtle">&mdash;</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Overall score')" numeric>
                                @if ($score !== null)
                                    {{ $score }} / {{ $scoreMax }}
                                @else
                                    <span class="text-ink-subtle">{{ __('not scored') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge
                                    :status="$evaluation->status->badge()"
                                    :label="$evaluation->status->label()"
                                    :icon="$evaluation->status->icon()"
                                />

                                @if ($evaluation->recommendations_count > 0)
                                    <span class="mt-1 block text-xs text-ink-muted">
                                        {{ trans_choice(
                                            '{1} :count recommendation|[2,*] :count recommendations',
                                            $evaluation->recommendations_count,
                                            ['count' => $evaluation->recommendations_count],
                                        ) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    trailing-icon="chevron-right"
                                    :href="$showUrl($evaluation)"
                                >{{ __('Open') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->evaluations->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->evaluations" :label="__('Evaluation list pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
