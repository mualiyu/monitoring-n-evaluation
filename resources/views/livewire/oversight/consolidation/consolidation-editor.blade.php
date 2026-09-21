{{--
    One state consolidation, end to end
    (App\Livewire\Oversight\Consolidation\ConsolidationEditor).

    Figures on the left, the chain on the right — "who has already touched
    this" is the first thing a director wants before signing, and the
    separation rule (the compiler may not approve) is stated in words on the
    panel rather than expressed by a button that silently is not there.

    FROZEN FIGURES: every number on this screen reads through the model's
    snapshot accessors, exactly as the PDF does. A screen showing live totals
    beside a document showing the snapshot is the defect the snapshot exists to
    prevent.
--}}
@php
    $report = $this->report;
    $period = $report->reportingPeriod;
    $totals = $this->figures;
    $entities = $this->entities;
    $blocked = $this->blockedReason();
    $frozen = $report->status->isFrozen();
    $coverage = $this->coverageRate();

    $money = fn ($value) => $value === null || $value === ''
        ? '—'
        : \App\Support\Money::fromDecimalString((string) $value)->format();

    $expected = (int) ($totals['obligations_expected'] ?? 0);
    $waived = (int) ($totals['obligations_waived'] ?? 0);
    $onTime = (int) ($totals['obligations_on_time'] ?? 0);
    $scored = $expected - $waived;
    $onTimeRate = $scored > 0 ? round($onTime * 100 / $scored, 1) : null;

    $workspaceUrl = route('oversight.consolidation.index');
@endphp

<div>
    <x-ui.page-header
        :title="$report->title"
        :description="$report->type->description()"
        :back="$workspaceUrl"
        :back-label="__('State consolidations')"
        :breadcrumbs="[
            ['label' => __('State consolidations'), 'href' => $workspaceUrl],
            ['label' => $report->reference],
        ]"
    >
        <x-slot:actions>
            @if ($this->canExport())
                <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray" wire:click="export('pdf')" loading="export">
                    {{ __('PDF') }}
                </x-ui.button>
                <x-ui.button size="sm" variant="secondary" icon="squares" wire:click="export('xlsx')" loading="export">
                    {{ __('Excel annex') }}
                </x-ui.button>
                <x-ui.button size="sm" variant="secondary" icon="document-text" wire:click="export('csv')" loading="export">
                    {{ __('CSV') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($notice)
        <x-ui.alert variant="info" class="mb-4" dismissible>{{ $notice }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-4" :title="__('That step could not be taken')">{{ $failure }}</x-ui.alert>
    @endif

    <div class="mb-5 flex flex-wrap items-center gap-2">
        {{-- Status is icon + text, never colour alone. --}}
        <x-ui.badge :status="$report->status->badge()" :label="$report->status->label()" :icon="$report->status->icon()" />
        <x-ui.badge status="draft" :label="$report->type->label()" :icon="$report->type->icon()" />
        <span class="font-mono text-sm text-ink-muted">{{ $report->reference }}</span>
        <span class="text-sm text-ink-muted" aria-hidden="true">·</span>
        <span class="text-sm text-ink-muted">{{ $period->label }} · {{ $period->cadence->label() }}</span>
    </div>

    @if ($frozen)
        <x-ui.alert variant="neutral" icon="shield-check" class="mb-5" :title="__('These figures are frozen')">
            {{ __('Signed on :date. Everything below is the snapshot taken at that moment — a later edit to an entity’s return cannot move it.', [
                'date' => $report->snapshot_taken_at?->translatedFormat('j M Y, H:i') ?? $report->approved_at?->translatedFormat('j M Y, H:i') ?? __('approval'),
            ]) }}
        </x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ------------------------------------------------------------ --}}
        {{-- Figures, narrative and annex                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4 lg:col-span-2">
            @if (! $report->hasFigures())
                <x-ui.card flush>
                    <x-ui.empty-state
                        icon="chart-bar"
                        :title="__('Nothing has been rolled up yet')"
                        :description="__('A consolidation begins by pulling every entity’s returns for this window. The narrative can then be written around figures rather than around a blank page.')"
                    >
                        <x-slot:actions>
                            @if ($this->canCompile())
                                <x-ui.button icon="arrow-path" wire:click="compile" loading="compile">
                                    {{ __('Compile the figures') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.stat
                        :label="__('Entities reporting')"
                        :value="number_format((int) ($totals['entities_reporting'] ?? $report->entity_count))"
                        icon="building-office"
                        :intent="$coverage !== null && $coverage < 60 ? 'warning' : 'neutral'"
                        :hint="$coverage === null
                            ? null
                            : __(':rate% of :total expected', ['rate' => $coverage, 'total' => number_format($report->denominator)])"
                    />
                    <x-ui.stat
                        :label="__('Projects in the register')"
                        :value="number_format((int) ($totals['projects_total'] ?? 0))"
                        icon="folder"
                        :href="route('oversight.portfolio.index')"
                    />
                    <x-ui.stat
                        :label="__('Contract sum')"
                        :value="$money($totals['contract_value_total'] ?? null)"
                        icon="banknotes"
                        :hint="__('Spent to date :amount', ['amount' => $money($totals['expenditure_total'] ?? null)])"
                    />
                    <x-ui.stat
                        :label="__('Returns filed on time')"
                        :value="$onTimeRate === null ? '—' : $onTimeRate.'%'"
                        icon="clock"
                        :intent="$onTimeRate !== null && $onTimeRate < 60 ? 'critical' : 'neutral'"
                        :hint="__(':count of :base returns', [
                            'count' => number_format($onTime),
                            'base' => number_format(max($scored, 0)),
                        ])"
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.stat
                        :label="__('Progress returns')"
                        :value="number_format((int) ($totals['reports_filed'] ?? 0))"
                        icon="document-text"
                        :hint="__(':count approved', ['count' => number_format((int) ($totals['reports_approved'] ?? 0))])"
                    />
                    <x-ui.stat
                        :label="__('Spend in the window')"
                        :value="$money($totals['period_expenditure_total'] ?? null)"
                        icon="banknotes"
                    />
                    <x-ui.stat
                        :label="__('Indicators reported')"
                        :value="number_format((int) ($totals['indicators_reported'] ?? 0))"
                        icon="chart-bar"
                        :hint="__(':count on track', ['count' => number_format((int) ($totals['indicators_on_track'] ?? 0))])"
                    />
                    <x-ui.stat
                        :label="__('Indicators off track')"
                        :value="number_format((int) ($totals['indicators_off_track'] ?? 0))"
                        icon="exclamation-triangle"
                        :intent="(int) ($totals['indicators_off_track'] ?? 0) > 0 ? 'critical' : 'neutral'"
                        :hint="__(':count at risk', ['count' => number_format((int) ($totals['indicators_at_risk'] ?? 0))])"
                    />
                </div>
            @endif

            {{-- -------------------------------------------------------- --}}
            {{-- The narrative                                             --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card
                :title="__('Narrative')"
                :subtitle="__('The chapters this report type is required to carry. A chapter may be left empty — the reader is entitled to see that it is, rather than not to know it was expected.')"
            >
                @if ($this->canEditNarrative())
                    <x-slot:actions>
                        <x-ui.button size="sm" variant="secondary" icon="check" wire:click="saveNarrative" loading="saveNarrative">
                            {{ __('Save every chapter') }}
                        </x-ui.button>
                    </x-slot:actions>
                @endif

                <div class="space-y-6">
                    @foreach ($report->type->sectionSkeleton() as $key => $heading)
                        @php
                            $required = $key === $report->type->requiredSectionKey();
                            $written = trim((string) ($sections[$key] ?? '')) !== '';
                        @endphp

                        <div wire:key="section-{{ $key }}">
                            @if ($this->canEditNarrative())
                                {{-- The <label> lives inside the group, so the
                                     chapter heading IS the control's label
                                     rather than a decorative h3 beside it. --}}
                                <x-ui.form.group
                                    :name="'sections.'.$key"
                                    :label="$heading"
                                    :required="$required"
                                    :hint="$required ? __('This chapter must carry text before the roll-up can go up the chain.') : null"
                                >
                                    <x-ui.form.textarea
                                        :name="'sections.'.$key"
                                        rows="6"
                                        :has-hint="$required"
                                        wire:model.blur="sections.{{ $key }}"
                                    >{{ $sections[$key] ?? '' }}</x-ui.form.textarea>
                                </x-ui.form.group>

                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                    <span class="inline-flex items-center gap-1 text-xs text-ink-muted">
                                        <x-ui.icon :name="$written ? 'check-circle' : 'minus'" class="size-3.5" />
                                        {{ $written ? __('Written') : __('Empty') }}
                                    </span>

                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        icon="check"
                                        wire:click="saveSection('{{ $key }}')"
                                        wire:loading.attr="disabled"
                                    >{{ __('Save this chapter') }}</x-ui.button>
                                </div>
                            @else
                                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ $heading }}</h3>

                                    <span class="inline-flex items-center gap-1 text-xs text-ink-muted">
                                        <x-ui.icon :name="$written ? 'check-circle' : 'minus'" class="size-3.5" />
                                        {{ $written ? __('Written') : __('Empty') }}
                                    </span>
                                </div>

                                @if ($written)
                                    <p class="text-sm leading-6 whitespace-pre-line text-ink">{{ $sections[$key] }}</p>
                                @else
                                    <p class="text-sm text-ink-subtle italic">
                                        {{ __('This chapter was left empty.') }}
                                    </p>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- -------------------------------------------------------- --}}
            {{-- Per-entity annex                                          --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card flush :title="__('Annex: figures by entity')">
                @if ($entities === [])
                    <x-ui.empty-state
                        compact
                        icon="building-office"
                        :title="__('No entity figures yet')"
                        :description="__('Compile the roll-up and every entity on the instance appears here — including the ones that filed nothing, because that absence is the finding.')"
                    />
                @else
                    <x-ui.table
                        :caption="__('Per-entity figures inside :reference', ['reference' => $report->reference])"
                        class="p-4 sm:p-0"
                        :headings="[
                            __('Entity'),
                            ['label' => __('Projects'), 'align' => 'right'],
                            ['label' => __('Contract sum'), 'align' => 'right'],
                            ['label' => __('Returns filed'), 'align' => 'right'],
                            ['label' => __('On-time rate'), 'align' => 'right'],
                            ['label' => __('Indicators on track'), 'align' => 'right'],
                        ]"
                    >
                        @foreach ($entities as $entity)
                            <x-ui.table.row wire:key="entity-{{ $entity['subject_tenant_id'] }}">
                                <x-ui.table.cell :label="__('Entity')" primary>
                                    @if (! empty($entity['entity_slug']))
                                        <a
                                            href="{{ route('oversight.portfolio.tenant', ['tenant' => $entity['entity_slug']]) }}"
                                            class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        >{{ $entity['entity'] ?? __('Unknown entity') }}</a>
                                    @else
                                        {{ $entity['entity'] ?? __('Unknown entity') }}
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Projects')" numeric>
                                    {{ number_format((int) ($entity['projects_total'] ?? 0)) }}
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Contract sum')" numeric>
                                    {{ $money($entity['contract_value_total'] ?? null) }}
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Returns filed')" numeric>
                                    {{ number_format((int) ($entity['obligations_submitted'] ?? 0)) }}
                                    <span class="text-xs text-ink-muted">
                                        / {{ number_format((int) ($entity['obligations_expected'] ?? 0)) }}
                                    </span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('On-time rate')" numeric>
                                    @if (($entity['on_time_rate'] ?? null) === null)
                                        <span class="text-sm text-ink-subtle">{{ __('Nothing scored') }}</span>
                                    @else
                                        {{ $entity['on_time_rate'] }}%
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Indicators on track')" numeric>
                                    {{ number_format((int) ($entity['indicators_on_track'] ?? 0)) }}
                                    <span class="text-xs text-ink-muted">
                                        / {{ number_format((int) ($entity['indicators_reported'] ?? 0)) }}
                                    </span>
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>

        {{-- ------------------------------------------------------------ --}}
        {{-- Decisions, chain, artifacts                                   --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4">
            <x-ui.card :title="__('Where it goes next')">
                @if ($blocked)
                    <x-ui.alert variant="neutral" icon="information-circle" class="mb-3">{{ $blocked }}</x-ui.alert>
                @endif

                <div class="flex flex-col gap-2">
                    @if ($this->canCompile())
                        <x-ui.button icon="arrow-path" wire:click="compile" loading="compile">
                            {{ $report->hasFigures() ? __('Recompile the figures') : __('Compile the figures') }}
                        </x-ui.button>
                        <p class="text-xs text-ink-muted">
                            {{ __('Safe to run twice: every entity’s row is rewritten in place, so a replay lands on the same numbers.') }}
                        </p>
                    @endif

                    @if ($this->canSubmit())
                        <x-ui.button variant="secondary" icon="paper-airplane" wire:click="submitForReview" loading="submitForReview">
                            {{ __('Send up the chain') }}
                        </x-ui.button>
                    @endif

                    @if ($this->canApprove())
                        <x-ui.button icon="check-circle" wire:click="approve" loading="approve">
                            {{ __('Approve and freeze') }}
                        </x-ui.button>
                        <p class="text-xs text-ink-muted">
                            {{ __('Approval copies the figures, the annex and the narrative onto the record. They cannot move afterwards.') }}
                        </p>
                    @endif

                    @if ($this->canReturn())
                        @if ($returning)
                            <div class="rounded-lg border border-line p-3">
                                <x-ui.form.group
                                    name="returnReason"
                                    :label="__('Why is it going back?')"
                                    :hint="__('The secretariat sees this verbatim.')"
                                    required
                                >
                                    <x-ui.form.textarea name="returnReason" rows="3" has-hint wire:model="returnReason" />
                                </x-ui.form.group>

                                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:justify-end">
                                    <x-ui.button size="sm" variant="secondary" wire:click="cancelReturn">
                                        {{ __('Cancel') }}
                                    </x-ui.button>
                                    <x-ui.button size="sm" icon="arrow-uturn-left" wire:click="confirmReturn" loading="confirmReturn">
                                        {{ __('Return it') }}
                                    </x-ui.button>
                                </div>
                            </div>
                        @else
                            <x-ui.button variant="secondary" icon="arrow-uturn-left" wire:click="startReturn">
                                {{ __('Return for rework') }}
                            </x-ui.button>
                        @endif
                    @endif

                    @if ($this->canPublish())
                        <x-ui.button icon="globe" wire:click="publish" loading="publish">
                            {{ __('Publish') }}
                        </x-ui.button>
                        <p class="text-xs text-ink-muted">
                            {{ __('Publication is terminal. A figure quoted outside the platform is corrected by the next consolidation, never by editing this one.') }}
                        </p>
                    @endif
                </div>

                @if ($report->return_reason && $report->status === \App\Enums\ConsolidationStatus::Compiling)
                    <x-ui.alert variant="warning" class="mt-3" :title="__('Returned for rework')">
                        {{ $report->return_reason }}
                    </x-ui.alert>
                @endif
            </x-ui.card>

            {{-- -------------------------------------------------------- --}}
            {{-- The append-only chain                                     --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card :title="__('Chain')" :subtitle="__('Every step, in order, with who signed for it')">
                @if ($this->timeline->isEmpty())
                    <p class="text-sm text-ink-muted">{{ __('Nothing has happened to this consolidation yet.') }}</p>
                @else
                    <ol class="space-y-4">
                        @foreach ($this->timeline as $event)
                            <li class="flex gap-3" wire:key="event-{{ $event->id }}">
                                <div class="flex flex-col items-center">
                                    <span
                                        @class([
                                            'flex size-7 shrink-0 items-center justify-center rounded-full ring-1 ring-inset',
                                            'bg-positive-soft text-positive-ink ring-positive/30' => $event->to_status->isFrozen(),
                                            'bg-warning-soft text-warning-ink ring-warning/40' => $event->reason !== null,
                                            'bg-brand-soft text-brand-ink ring-brand/30' => ! $event->to_status->isFrozen() && $event->reason === null,
                                        ])
                                    >
                                        <x-ui.icon :name="$event->to_status->icon()" class="size-4" />
                                    </span>

                                    @unless ($loop->last)
                                        <span class="mt-1 w-px flex-1 bg-line" aria-hidden="true"></span>
                                    @endunless
                                </div>

                                <div class="min-w-0 flex-1 pb-1">
                                    <p class="text-sm font-medium text-ink">
                                        @if ($event->from_status === null)
                                            {{ __('Opened') }}
                                        @else
                                            {{ __(':from → :to', [
                                                'from' => $event->from_status->label(),
                                                'to' => $event->to_status->label(),
                                            ]) }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ __(':user · :date', [
                                            'user' => $event->actor?->name ?? __('an officer since removed'),
                                            'date' => $event->occurred_at->translatedFormat('j M Y, H:i'),
                                        ]) }}
                                    </p>
                                    @if ($event->reason)
                                        <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ $event->reason }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>

            {{-- -------------------------------------------------------- --}}
            {{-- Artifacts generated from this consolidation               --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card :title="__('Artifacts')" :subtitle="__('Every copy handed out of this consolidation')">
                <x-slot:actions>
                    <x-ui.button size="sm" variant="ghost" icon="arrow-right" :href="route('oversight.exports.index')">
                        {{ __('Register') }}
                    </x-ui.button>
                </x-slot:actions>

                @if ($this->artifacts->isEmpty())
                    <p class="text-sm text-ink-muted">
                        {{ __('Nothing has been generated from this consolidation yet. Every export is recorded here with the officer who asked for it.') }}
                    </p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($this->artifacts as $artifact)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0" wire:key="artifact-{{ $artifact->ulid }}">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-1.5 text-sm font-medium text-ink">
                                        <x-ui.icon :name="$artifact->format->icon()" class="size-4 shrink-0 text-ink-subtle" />
                                        {{ $artifact->format->label() }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ __(':user · :date', [
                                            'user' => $artifact->generatedBy?->name ?? __('an officer since removed'),
                                            'date' => $artifact->created_at?->translatedFormat('j M Y, H:i'),
                                        ]) }}
                                    </p>
                                </div>

                                @if ($artifact->isDownloadable())
                                    <x-ui.button
                                        size="sm"
                                        variant="secondary"
                                        icon="arrow-down-tray"
                                        wire:click="download('{{ $artifact->ulid }}')"
                                    >{{ __('Download') }}</x-ui.button>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs text-ink-muted">
                                        <x-ui.icon :name="$artifact->hasFailed() ? 'exclamation-triangle' : 'clock'" class="size-3.5" />
                                        {{ $artifact->hasFailed() ? __('Failed') : ($artifact->isPending() ? __('Generating') : __('No longer held')) }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
