{{--
    One site visit, whole (App\Livewire\Tenant\Inspections\InspectionDetail).

    Two columns on desktop, stacked on mobile: the Field Trip Report and the
    checklist on the left because that is what people come to read, the state
    of the visit and what you may do about it on the right. The lifecycle
    timeline and the evidence vault sit under the actions.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $inspection = $this->record;
    $previous = $this->previousVisit;
    $blocked = $this->reviewBlockedReason();

    // The seven sections of the manual's Field Trip Report (digest §3), in the
    // manual's own order. Rendered from one array so a section can never be
    // quietly dropped by an edit to the markup.
    $reportSections = [
        ['label' => __('1. Objectives'), 'value' => $inspection->objectives],
        ['label' => __('2. People and groups met'), 'value' => $inspection->people_met],
        ['label' => __('3. Methods used'), 'value' => $inspection->methods],
        ['label' => __('4. Findings'), 'value' => $inspection->findings],
        ['label' => __('5. Comparison with previous visits'), 'value' => $inspection->comparison_with_previous],
        ['label' => __('6. Conclusions'), 'value' => $inspection->conclusions],
        ['label' => __('7. Recommendations'), 'value' => $inspection->recommendations],
    ];
@endphp

<div>
    <x-ui.page-header
        :title="$inspection->type->label()"
        :description="$inspection->project->title.' · '.$inspection->project->reference"
        :back="route('tenant.inspections.index', $workspace)"
        :back-label="__('Back to inspections')"
    >
        <x-slot:actions>
            @can('conduct', $inspection)
                @if ($inspection->status->isOpen())
                    <x-ui.button
                        size="sm"
                        icon="pencil-square"
                        :href="route('tenant.inspections.conduct', [...$workspace, 'inspection' => $inspection])"
                    >{{ $inspection->status === \App\Enums\InspectionStatus::InProgress ? __('Continue the visit') : __('Start the visit') }}</x-ui.button>
                @endif
            @endcan

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="folder"
                :href="route('tenant.projects.show', [...$workspace, 'project' => $inspection->project])"
            >{{ __('Open the project') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    @if ($inspection->geofence_breached)
        {{-- Icon + text, never colour alone: this is read on a cheap panel in
             sunlight and printed in greyscale for board packs. --}}
        <x-ui.alert variant="warning" :title="__('Position recorded away from the site')" class="mb-4">
            {{ __('The inspector’s recorded position was :distance m from this project’s registered site. That is not proof of anything on its own — a project boundary can be large and a phone fix can be poor — but the evidence is flagged for the reviewer.', ['distance' => number_format((float) $inspection->geofence_distance_metres)]) }}
        </x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ------------------------------------------------------------ --}}
        {{-- Left: the report and the checklist                            --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card
                :title="__('Field Trip Report')"
                :subtitle="__('The seven sections every field visit must produce')"
            >
                @if (! $inspection->status->isFiled())
                    <x-ui.alert variant="neutral" class="mb-4">
                        {{ __('This report has not been filed yet. What is shown below is the inspector’s working draft.') }}
                    </x-ui.alert>
                @endif

                <dl class="space-y-4">
                    @foreach ($reportSections as $section)
                        <div>
                            <dt class="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                                {{ $section['label'] }}
                            </dt>
                            <dd class="mt-1 text-sm whitespace-pre-line text-ink">
                                @if (filled($section['value']))
                                    {{ $section['value'] }}
                                @else
                                    <span class="text-ink-subtle">{{ __('Not recorded') }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            @if ($this->responses->isNotEmpty())
                <x-ui.card
                    :title="__('Checklist')"
                    :subtitle="$inspection->template?->name ?? __('The instrument answered on site')"
                    flush
                >
                    <x-ui.table
                        :caption="__('Checklist items as they were answered on site')"
                        class="p-4 sm:p-0"
                        :headings="[__('Question'), __('Answer'), __('Note')]"
                    >
                        @foreach ($this->responses as $response)
                            <x-ui.table.row wire:key="response-{{ $response->id }}">
                                <x-ui.table.cell :label="__('Question')" primary>
                                    {{-- The SNAPSHOTTED prompt, never the live
                                         template: a report must show what was
                                         actually asked on the day. --}}
                                    <span class="font-normal">{{ $response->prompt }}</span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Answer')">
                                    @php
                                        $answer = $response->answer();
                                    @endphp

                                    @if ($response->response_type === \App\Enums\ChecklistResponseType::YesNo)
                                        @if ($answer === null)
                                            <span class="text-ink-subtle">{{ __('Unanswered') }}</span>
                                        @else
                                            <x-ui.badge
                                                :status="$answer ? 'on_track' : 'overdue'"
                                                :icon="$answer ? 'check-circle' : 'x-circle'"
                                                :label="$answer ? __('Yes') : __('No')"
                                                size="sm"
                                            />
                                        @endif
                                    @elseif (filled($answer))
                                        <span class="text-ink">
                                            {{ $answer }}@if ($response->item?->unit) {{ ' '.$response->item->unit }}@endif
                                            @if ($response->response_type === \App\Enums\ChecklistResponseType::Rating && $response->item?->rating_scale)
                                                <span class="text-ink-muted">/ {{ $response->item->rating_scale }}</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-ink-subtle">{{ __('Unanswered') }}</span>
                                    @endif

                                    @if ($response->is_finding)
                                        <span class="mt-1 block">
                                            <x-ui.badge status="overdue" size="sm" :label="__('Finding')" />
                                        </span>
                                    @endif
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Note')">
                                    @if (filled($response->note))
                                        <span class="text-sm text-ink-muted">{{ $response->note }}</span>
                                    @else
                                        <span class="text-ink-subtle">&mdash;</span>
                                    @endif
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif

            {{-- The evidence vault. Photographs carry EXIF GPS and capture
                 time, lifted into custom properties on upload — this panel is
                 the shared component, not a second implementation. --}}
            <livewire:shared.document-panel
                :model="$inspection"
                collection="inspection_photos"
                :readonly="! $inspection->isEditable()"
                :heading="__('Photographic evidence')"
                :key="'photos-'.$inspection->ulid"
            />

            <livewire:shared.document-panel
                :model="$inspection"
                collection="inspection_documents"
                :readonly="! $inspection->isEditable()"
                :heading="__('Attachments')"
                :key="'documents-'.$inspection->ulid"
            />
        </div>

        {{-- ------------------------------------------------------------ --}}
        {{-- Right: state, actions, timeline                               --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="space-y-4">
            <x-ui.card :title="__('This visit')">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Status') }}</dt>
                        <dd><x-ui.badge :status="$inspection->status->badge()" :label="$inspection->status->label()" /></dd>
                    </div>

                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Outcome') }}</dt>
                        <dd>
                            @if ($inspection->outcome)
                                <x-ui.badge :status="$inspection->outcome->badge()" :label="$inspection->outcome->label()" />
                            @else
                                <span class="text-ink-subtle">&mdash;</span>
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Scheduled') }}</dt>
                        <dd class="text-ink">{{ $inspection->scheduled_date->translatedFormat('j M Y') }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Conducted') }}</dt>
                        <dd class="text-ink">
                            {{ $inspection->conducted_at?->translatedFormat('j M Y, H:i') ?? '—' }}
                        </dd>
                    </div>

                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Lead inspector') }}</dt>
                        <dd class="text-ink">{{ $inspection->leadInspector->name }}</dd>
                    </div>

                    @if (filled($inspection->team))
                        <div>
                            <dt class="text-ink-muted">{{ __('Team') }}</dt>
                            <dd class="mt-1 text-ink">{{ $inspection->team }}</dd>
                        </div>
                    @endif

                    @if ($inspection->physical_progress_observed !== null)
                        <div>
                            <dt class="mb-1 text-ink-muted">{{ __('Progress observed on site') }}</dt>
                            <dd>
                                <x-ui.progress :value="$inspection->physical_progress_observed" />
                                <span class="mt-1 block text-xs text-ink-muted">
                                    {{ __('project record: :value%', ['value' => $inspection->project->physical_progress]) }}
                                </span>
                            </dd>
                        </div>
                    @endif

                    @if ($inspection->latitude !== null)
                        <div>
                            <dt class="text-ink-muted">{{ __('Recorded position') }}</dt>
                            <dd class="mt-1 font-mono text-xs text-ink">
                                {{ $inspection->latitude }}, {{ $inspection->longitude }}
                                @if ($inspection->gps_accuracy_metres)
                                    <span class="text-ink-muted">(±{{ $inspection->gps_accuracy_metres }} m)</span>
                                @endif
                            </dd>
                        </div>
                    @endif

                    @if ($inspection->isProposed())
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-ink-muted">{{ __('Origin') }}</dt>
                            <dd><x-ui.badge status="pending" icon="cog" size="sm" :label="__('Proposed by the platform')" /></dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            @if ($previous)
                <x-ui.card :title="__('Previous visit')" :subtitle="__('What the last inspection of this project found')">
                    <p class="text-sm text-ink-muted">
                        {{ $previous->type->label() }} ·
                        {{ ($previous->conducted_at ?? $previous->scheduled_date)->translatedFormat('j M Y') }}
                    </p>

                    @if ($previous->outcome)
                        <p class="mt-2">
                            <x-ui.badge :status="$previous->outcome->badge()" :label="$previous->outcome->label()" size="sm" />
                        </p>
                    @endif

                    <x-ui.button
                        variant="ghost"
                        size="sm"
                        class="mt-3"
                        trailing-icon="chevron-right"
                        :href="route('tenant.inspections.show', [...$workspace, 'inspection' => $previous])"
                    >{{ __('Open it') }}</x-ui.button>
                </x-ui.card>
            @endif

            {{-- --------------------------------------------------------- --}}
            {{-- What you may do about it                                   --}}
            {{-- --------------------------------------------------------- --}}
            @if ($inspection->status === \App\Enums\InspectionStatus::Submitted || $inspection->status->isOpen())
                <x-ui.card :title="__('Actions')">
                    <div class="flex flex-col gap-2">
                        @if ($this->canReview())
                            <x-ui.button
                                icon="shield-check"
                                x-on:click="$dispatch('open-modal', 'review-inspection')"
                            >{{ __('Sign off this report') }}</x-ui.button>
                        @endif

                        @can('cancel', $inspection)
                            @if ($inspection->status->isOpen())
                                <x-ui.button
                                    variant="secondary"
                                    icon="x-circle"
                                    x-on:click="$dispatch('open-modal', 'cancel-inspection')"
                                >{{ __('Cancel this visit') }}</x-ui.button>
                            @endif
                        @endcan
                    </div>

                    @if ($blocked)
                        <p class="mt-3 flex items-start gap-1.5 text-xs text-ink-muted">
                            <x-ui.icon name="information-circle" class="mt-0.5 size-4 shrink-0" />
                            <span>{{ $blocked }}</span>
                        </p>
                    @endif
                </x-ui.card>
            @endif

            @if (filled($inspection->review_notes))
                <x-ui.card :title="__('Sign-off notes')" :subtitle="$inspection->reviewedBy?->name">
                    <p class="text-sm whitespace-pre-line text-ink">{{ $inspection->review_notes }}</p>
                </x-ui.card>
            @endif

            @if (filled($inspection->cancellation_reason))
                <x-ui.card :title="__('Why this visit was cancelled')" :subtitle="$inspection->cancelledBy?->name">
                    <p class="text-sm whitespace-pre-line text-ink">{{ $inspection->cancellation_reason }}</p>
                </x-ui.card>
            @endif

            {{-- --------------------------------------------------------- --}}
            {{-- Lifecycle timeline, from the append-only ledger             --}}
            {{-- --------------------------------------------------------- --}}
            <x-ui.card :title="__('History')" :subtitle="__('Every step, in order, with who signed for it')">
                @if ($this->chain->isEmpty())
                    <p class="text-sm text-ink-muted">{{ __('Nothing has happened to this visit yet.') }}</p>
                @else
                    <ol class="space-y-4">
                        @foreach ($this->chain as $event)
                            <li class="flex gap-3">
                                <div class="flex flex-col items-center">
                                    <span
                                        @class([
                                            'flex size-7 shrink-0 items-center justify-center rounded-full ring-1 ring-inset',
                                            'bg-positive-soft text-positive-ink ring-positive/30' => $event->to_status->value === 'reviewed',
                                            'bg-warning-soft text-warning-ink ring-warning/40' => $event->to_status->value === 'cancelled',
                                            'bg-brand-soft text-brand-ink ring-brand/30' => ! in_array($event->to_status->value, ['reviewed', 'cancelled'], true),
                                        ])
                                    >
                                        <x-ui.icon
                                            :name="match ($event->to_status->value) {
                                                'in_progress' => 'map-pin',
                                                'submitted' => 'paper-airplane',
                                                'reviewed' => 'shield-check',
                                                'cancelled' => 'x-circle',
                                                default => 'calendar-days',
                                            }"
                                            class="size-4"
                                        />
                                    </span>

                                    @unless ($loop->last)
                                        <span class="mt-1 w-px flex-1 bg-line" aria-hidden="true"></span>
                                    @endunless
                                </div>

                                <div class="min-w-0 flex-1 pb-1">
                                    <p class="text-sm font-medium text-ink">
                                        {{ $event->isCreation() ? __('Put in the diary') : $event->to_status->label() }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ $event->actor?->name ?? __('System') }}
                                        · {{ $event->occurred_at->translatedFormat('j M Y, H:i') }}
                                    </p>
                                    @if ($event->reason)
                                        <p class="mt-1 rounded-lg bg-neutral-soft px-3 py-2 text-xs text-neutral-ink">
                                            {{ $event->reason }}
                                        </p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Sign off                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($this->canReview())
        <x-ui.modal
            name="review-inspection"
            :title="__('Sign off this inspection report?')"
            :description="__('The report becomes part of the project’s permanent monitoring record. Sign-off does not change what the inspector observed — if you disagree with the findings, say so in your notes and order another visit.')"
            max-width="md"
        >
            <x-ui.form.group
                name="reviewNotes"
                :label="__('Sign-off notes')"
                :hint="__('Optional, and read by anyone auditing this project. This is where a disagreement with the findings belongs.')"
            >
                <x-ui.form.textarea
                    name="reviewNotes"
                    rows="3"
                    maxlength="2000"
                    has-hint
                    :placeholder="__('e.g. Findings accepted. Housekeeping item carried to the issues register.')"
                    wire:model="reviewNotes"
                />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'review-inspection')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="review" loading="review" icon="shield-check">
                    {{ __('Sign off') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Cancel the visit                                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    @can('cancel', $inspection)
        <x-ui.modal
            name="cancel-inspection"
            :title="__('Cancel this site visit?')"
            :description="__('The visit leaves the diary and stops being chased. The reason is permanent, attributed to you, and visible to state oversight — a visit that silently disappears is indistinguishable from one nobody bothered to make.')"
            max-width="md"
        >
            <x-ui.form.group
                name="cancelReason"
                :label="__('Reason')"
                :hint="__('Required. Kept on the monitoring record.')"
                required
            >
                <x-ui.form.textarea
                    name="cancelReason"
                    rows="3"
                    maxlength="1000"
                    has-hint
                    :placeholder="__('e.g. Access road impassable after three days of rain; visit deferred to the next cycle.')"
                    wire:model="cancelReason"
                />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'cancel-inspection')">
                    {{ __('Keep it') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="cancel" loading="cancel" icon="x-circle">
                    {{ __('Cancel the visit') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</div>
