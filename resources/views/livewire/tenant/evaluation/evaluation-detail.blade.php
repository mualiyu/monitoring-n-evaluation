{{--
    Evaluation detail (App\Livewire\Tenant\Evaluation\EvaluationDetail).

    The commission, the scorecard, the eleven-section report builder, the team,
    the document vault, the recommendations it raised, and the lifecycle
    controls — one screen, because an evaluator scores a criterion in the same
    minute as they write the finding behind it.

    NO CHART WRAPPER. The repository has no ApexCharts component, and inventing
    one for a five-row scorecard would be a design-system decision made in a
    module. The scorecard is a real table with a labelled bar per criterion:
    it reads correctly in greyscale on a printed board pack, it is navigable by
    keyboard, and every number is printed beside its bar rather than implied by
    its length.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $evaluation = $this->evaluation;
    $scoreMax = $this->scoreMax();
    $overall = $evaluation->overallScore();
    $overallPercent = $evaluation->overallScorePercent($scoreMax);
    $canEdit = $this->canEdit();
    $blocked = $this->blockedReason();
    $missingSections = $evaluation->missingRequiredSections();
@endphp

<div>
    <x-ui.page-header
        :title="$evaluation->title"
        :back="route('tenant.evaluations.index', $workspace)"
        :back-label="__('Back to evaluations')"
        :description="__(':type of :subject, commissioned by :sponsor.', [
            'type' => $evaluation->type->label(),
            'subject' => $evaluation->subjectLabel(),
            'sponsor' => $evaluation->sponsor,
        ])"
        :breadcrumbs="[
            ['label' => __('Evaluations'), 'href' => route('tenant.evaluations.index', $workspace)],
            ['label' => $evaluation->subjectLabel()],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge
                :status="$evaluation->status->badge()"
                :label="$evaluation->status->label()"
                :icon="$evaluation->status->icon()"
            />

            @if ($this->canDownloadReport())
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="arrow-down-tray"
                    wire:click="downloadReport"
                    loading="downloadReport"
                >{{ __('Download report (PDF)') }}</x-ui.button>
            @endif
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

    @if ($evaluation->isReportOverdue())
        <x-ui.alert variant="warning" :title="__('The report deadline has passed')" class="mb-4">
            {{ __('This evaluation was due to report on :date and has not been approved.', [
                'date' => $evaluation->report_due_on->translatedFormat('j F Y'),
            ]) }}
        </x-ui.alert>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Headline figures                                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Overall score')"
            :value="$overall === null ? __('Not scored') : $overall.' / '.$scoreMax"
            icon="chart-bar"
            :hint="$overallPercent === null ? __('no criterion answered yet') : __(':percent% of the maximum', ['percent' => $overallPercent])"
        />
        <x-ui.stat
            :label="__('Report sections written')"
            :value="$evaluation->sections->filter->isWritten()->count().' / '.$evaluation->sections->count()"
            icon="document-text"
            :intent="$missingSections === [] ? 'positive' : 'neutral'"
            :hint="$missingSections === [] ? __('every required section is written') : trans_choice('{1} :count required section still to write|[2,*] :count required sections still to write', count($missingSections), ['count' => count($missingSections)])"
        />
        <x-ui.stat
            :label="__('Recommendations raised')"
            :value="number_format($this->recommendations->count())"
            icon="clipboard-check"
            :href="route('tenant.recommendations.index', $workspace)"
        />
        <x-ui.stat
            :label="__('Report due')"
            :value="$evaluation->report_due_on?->translatedFormat('j M Y') ?? __('Not set')"
            icon="calendar-days"
            :intent="$evaluation->isReportOverdue() ? 'critical' : 'neutral'"
        />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            {{-- ------------------------------------------------------------ --}}
            {{-- The commission                                                --}}
            {{-- ------------------------------------------------------------ --}}
            <x-ui.card :title="__('The commission')">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-medium text-ink">{{ __('Purpose') }}</dt>
                        <dd class="mt-0.5 max-w-prose whitespace-pre-line text-ink-muted">{{ $evaluation->purpose }}</dd>
                    </div>

                    @if ($evaluation->evaluation_questions)
                        <div>
                            <dt class="font-medium text-ink">{{ __('Evaluation questions') }}</dt>
                            <dd class="mt-0.5 max-w-prose whitespace-pre-line text-ink-muted">{{ $evaluation->evaluation_questions }}</dd>
                        </div>
                    @endif

                    @if ($evaluation->methodology_summary)
                        <div>
                            <dt class="font-medium text-ink">{{ __('Methodology') }}</dt>
                            <dd class="mt-0.5 max-w-prose whitespace-pre-line text-ink-muted">{{ $evaluation->methodology_summary }}</dd>
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt class="font-medium text-ink">{{ __('Subject') }}</dt>
                            <dd class="mt-0.5 text-ink-muted">
                                @if ($evaluation->project)
                                    <a
                                        href="{{ route('tenant.projects.show', [...$workspace, 'project' => $evaluation->project]) }}"
                                        class="rounded underline hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $evaluation->project->title }}</a>
                                @else
                                    {{ $evaluation->subjectLabel() }}
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink">{{ __('Field period') }}</dt>
                            <dd class="mt-0.5 text-ink-muted">
                                @if ($evaluation->starts_on && $evaluation->ends_on)
                                    {{ $evaluation->starts_on->translatedFormat('j M Y') }}
                                    &ndash; {{ $evaluation->ends_on->translatedFormat('j M Y') }}
                                @else
                                    {{ __('Not set') }}
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink">{{ __('Budget') }}</dt>
                            <dd class="mt-0.5 text-ink-muted">
                                {{ $evaluation->budget?->format() ?? __('Not set') }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </x-ui.card>

            {{-- ------------------------------------------------------------ --}}
            {{-- The criteria scorecard                                        --}}
            {{-- ------------------------------------------------------------ --}}
            <x-ui.card
                :title="__('Criteria scorecard')"
                :subtitle="__('Each criterion is scored out of :max with the reasoning that makes the number defensible. The overall score is the weighted mean of these rows — it is never stored separately.', ['max' => $scoreMax])"
                flush
            >
                <div class="px-4 py-4 sm:px-5">
                    @if ($evaluation->criterionScores->isEmpty())
                        <x-ui.empty-state
                            compact
                            icon="chart-bar"
                            :title="__('No criteria configured')"
                            :description="__('This instance has no evaluation criteria set. An administrator configures them once, and every evaluation is scored against the same list.')"
                        />
                    @else
                        <x-ui.table
                            :caption="__('Evaluation criteria, their scores out of the instance maximum, and the reasoning behind each')"
                            :headings="[
                                __('Criterion'),
                                ['label' => __('Score'), 'align' => 'right'],
                                __('Against the maximum'),
                                __('Reasoning'),
                            ]"
                        >
                            @foreach ($evaluation->criterionScores as $score)
                                <x-ui.table.row wire:key="score-{{ $score->criterion }}">
                                    <x-ui.table.cell :label="__('Criterion')" primary>
                                        {{ $score->label() }}
                                        @if ((float) $score->weight !== 1.0)
                                            <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                                {{ __('weighted ×:weight', ['weight' => rtrim(rtrim($score->weight, '0'), '.')]) }}
                                            </span>
                                        @endif
                                    </x-ui.table.cell>

                                    <x-ui.table.cell :label="__('Score')" numeric>
                                        @if ($score->isScored())
                                            {{ rtrim(rtrim($score->score, '0'), '.') }} / {{ $scoreMax }}
                                        @else
                                            <span class="text-ink-subtle">{{ __('not scored') }}</span>
                                        @endif
                                    </x-ui.table.cell>

                                    <x-ui.table.cell :label="__('Against the maximum')">
                                        <x-ui.progress
                                            :value="$score->percentOf($scoreMax)"
                                            :label="__(':criterion score', ['criterion' => $score->label()])"
                                            size="sm"
                                        />
                                    </x-ui.table.cell>

                                    <x-ui.table.cell :label="__('Reasoning')">
                                        @if ($score->justification)
                                            <span class="block max-w-prose text-ink-muted">{{ $score->justification }}</span>
                                            @if ($score->evidence_reference)
                                                <span class="mt-0.5 block text-xs text-ink-subtle">
                                                    {{ __('Evidence: :reference', ['reference' => $score->evidence_reference]) }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="inline-flex items-center gap-1 text-ink-subtle">
                                                <x-ui.icon name="exclamation-circle" class="size-4" />
                                                {{ __('awaiting a judgement') }}
                                            </span>
                                        @endif
                                    </x-ui.table.cell>
                                </x-ui.table.row>
                            @endforeach
                        </x-ui.table>
                    @endif
                </div>

                @if ($canEdit && $evaluation->criterionScores->isNotEmpty())
                    <x-slot:footer>
                        <div class="space-y-4">
                            <p class="text-sm font-medium text-ink">{{ __('Record a score') }}</p>

                            @foreach ($evaluation->criterionScores as $score)
                                <div
                                    class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-12"
                                    wire:key="score-form-{{ $score->criterion }}"
                                >
                                    <div class="sm:col-span-2">
                                        <x-ui.form.group
                                            :name="'scores.'.$score->criterion.'.score'"
                                            :label="$score->label()"
                                        >
                                            <x-ui.form.input
                                                :name="'scores.'.$score->criterion.'.score'"
                                                type="number"
                                                step="0.25"
                                                min="0"
                                                :max="$scoreMax"
                                                :suffix="'/ '.$scoreMax"
                                                wire:model="scores.{{ $score->criterion }}.score"
                                            />
                                        </x-ui.form.group>
                                    </div>

                                    <div class="sm:col-span-6">
                                        <x-ui.form.group
                                            :name="'scores.'.$score->criterion.'.justification'"
                                            :label="__('Reasoning')"
                                        >
                                            <x-ui.form.textarea
                                                :name="'scores.'.$score->criterion.'.justification'"
                                                rows="2"
                                                maxlength="5000"
                                                :placeholder="__('What the evidence showed, and why it produces this score.')"
                                                wire:model="scores.{{ $score->criterion }}.justification"
                                            />
                                        </x-ui.form.group>
                                    </div>

                                    <div class="sm:col-span-3">
                                        <x-ui.form.group
                                            :name="'scores.'.$score->criterion.'.evidence'"
                                            :label="__('Evidence reference')"
                                        >
                                            <x-ui.form.input
                                                :name="'scores.'.$score->criterion.'.evidence'"
                                                maxlength="255"
                                                :placeholder="__('e.g. Findings §4.2')"
                                                wire:model="scores.{{ $score->criterion }}.evidence"
                                            />
                                        </x-ui.form.group>
                                    </div>

                                    <div class="flex items-end sm:col-span-1">
                                        <x-ui.button
                                            size="sm"
                                            variant="secondary"
                                            icon="check"
                                            wire:click="saveScore('{{ $score->criterion }}')"
                                            loading="saveScore('{{ $score->criterion }}')"
                                        >{{ __('Save') }}</x-ui.button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            {{-- ------------------------------------------------------------ --}}
            {{-- The report builder                                            --}}
            {{-- ------------------------------------------------------------ --}}
            <x-ui.card
                :title="__('Evaluation report')"
                :subtitle="__('The report template as rows, so a state can change its own format without a release. Required sections must be written before the draft can be marked complete.')"
            >
                @if ($evaluation->sections->isEmpty())
                    <x-ui.empty-state
                        compact
                        icon="document-text"
                        :title="__('No report template')"
                        :description="__('This evaluation was created without a report skeleton. Commission it again, or ask an administrator to check the report template setting.')"
                    />
                @elseif ($canEdit)
                    <form wire:submit="saveReport" class="space-y-4">
                        @foreach ($evaluation->sections as $section)
                            <x-ui.form.group
                                :name="'sections.'.$section->key"
                                :label="$section->ordinal.'. '.$section->heading"
                                :hint="$section->is_required ? __('Required before the draft is complete.') : __('Optional.')"
                                :required="$section->is_required"
                                wire:key="section-{{ $section->key }}"
                            >
                                <x-ui.form.textarea
                                    :name="'sections.'.$section->key"
                                    rows="4"
                                    has-hint
                                    wire:model="sections.{{ $section->key }}"
                                />
                            </x-ui.form.group>
                        @endforeach

                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="check" loading="saveReport">
                                {{ __('Save report') }}
                            </x-ui.button>
                        </div>
                    </form>
                @else
                    <div class="space-y-5">
                        @foreach ($evaluation->sections as $section)
                            <section wire:key="section-read-{{ $section->key }}">
                                <h3 class="text-sm font-semibold text-ink">
                                    {{ $section->ordinal }}. {{ $section->heading }}
                                </h3>

                                @if ($section->isWritten())
                                    <p class="mt-1 max-w-prose text-sm whitespace-pre-line text-ink-muted">{{ $section->body }}</p>
                                @else
                                    <p class="mt-1 inline-flex items-center gap-1 text-sm text-ink-subtle">
                                        <x-ui.icon name="minus" class="size-4" />
                                        {{ __('Not written') }}
                                    </p>
                                @endif
                            </section>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- ------------------------------------------------------------ --}}
            {{-- Recommendations raised                                        --}}
            {{-- ------------------------------------------------------------ --}}
            <x-ui.card
                :title="__('Recommendations raised')"
                :subtitle="__('Every recommendation here is tracked on the follow-up register until it is implemented or closed with a reason. An evaluation nobody acts on is a document.')"
                flush
            >
                <x-slot:actions>
                    @if ($this->canRecommend() && ! $raising)
                        <x-ui.button size="sm" icon="plus" wire:click="startRecommendation">
                            {{ __('Raise a recommendation') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>

                <div class="px-4 py-4 sm:px-5">
                    @if ($this->recommendations->isEmpty() && ! $raising)
                        <x-ui.empty-state
                            compact
                            icon="clipboard-check"
                            :title="__('No recommendations yet')"
                            :description="__('The manual asks for recommendations that are prioritized, costed and timetabled. Raise them here and they become trackable obligations rather than paragraphs.')"
                        />
                    @elseif ($this->recommendations->isNotEmpty())
                        <ul class="divide-y divide-line">
                            @foreach ($this->recommendations as $recommendation)
                                <li class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0" wire:key="rec-{{ $recommendation->ulid }}">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <p class="min-w-0 text-sm font-medium text-ink">{{ $recommendation->title }}</p>

                                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                            <x-ui.badge
                                                size="sm"
                                                :status="$recommendation->priority->badge()"
                                                :label="$recommendation->priority->label()"
                                                :icon="$recommendation->priority->icon()"
                                            />
                                            <x-ui.badge
                                                size="sm"
                                                :status="$recommendation->status->badge()"
                                                :label="$recommendation->status->label()"
                                                :icon="$recommendation->status->icon()"
                                            />
                                        </div>
                                    </div>

                                    <p class="max-w-prose text-sm text-ink-muted">{{ $recommendation->body }}</p>

                                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-subtle">
                                        <span>{{ __('For: :addressee', ['addressee' => $recommendation->addresseeLabel()]) }}</span>
                                        @if ($recommendation->due_on)
                                            <span>{{ __('Due :date', ['date' => $recommendation->due_on->translatedFormat('j M Y')]) }}</span>
                                        @endif
                                        @if ($recommendation->estimated_cost)
                                            <span>{{ __('Est. :cost', ['cost' => $recommendation->estimated_cost->format()]) }}</span>
                                        @endif
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($raising)
                        <form wire:submit="saveRecommendation" class="mt-4 space-y-4 rounded-lg border border-line p-4">
                            <x-ui.form.group name="recommendationTitle" :label="__('Title')" required>
                                <x-ui.form.input
                                    name="recommendationTitle"
                                    maxlength="255"
                                    :placeholder="__('e.g. Re-sequence the drainage works ahead of the wet season')"
                                    wire:model="recommendationTitle"
                                />
                            </x-ui.form.group>

                            <x-ui.form.group
                                name="recommendationBody"
                                :label="__('Recommendation')"
                                :hint="__('What should be done, and why. Written so that someone who did not read the report can act on it.')"
                                required
                            >
                                <x-ui.form.textarea
                                    name="recommendationBody"
                                    rows="3"
                                    maxlength="5000"
                                    has-hint
                                    wire:model="recommendationBody"
                                />
                            </x-ui.form.group>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.form.group
                                    name="recommendationAddresseeId"
                                    :label="__('Addressed to (a colleague)')"
                                    :hint="__('They are notified, and chased if the date passes.')"
                                >
                                    <x-ui.form.select
                                        name="recommendationAddresseeId"
                                        :placeholder="__('Choose a colleague…')"
                                        :options="$this->addresseeOptions"
                                        has-hint
                                        wire:model.live="recommendationAddresseeId"
                                    />
                                </x-ui.form.group>

                                <x-ui.form.group
                                    name="recommendationAddresseeBody"
                                    :label="__('Addressed to (a body)')"
                                    :hint="__('When the addressee has no account here.')"
                                >
                                    <x-ui.form.input
                                        name="recommendationAddresseeBody"
                                        maxlength="255"
                                        has-hint
                                        :placeholder="__('e.g. Directorate of Works')"
                                        wire:model.live="recommendationAddresseeBody"
                                    />
                                </x-ui.form.group>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <x-ui.form.group name="recommendationPriority" :label="__('Priority')" required>
                                    <x-ui.form.select
                                        name="recommendationPriority"
                                        :options="$this->priorityOptions"
                                        wire:model="recommendationPriority"
                                    />
                                </x-ui.form.group>

                                <x-ui.form.group name="recommendationCost" :label="__('Estimated cost')" optional>
                                    <x-ui.form.input
                                        name="recommendationCost"
                                        type="text"
                                        inputmode="decimal"
                                        :prefix="__('₦')"
                                        wire:model="recommendationCost"
                                    />
                                </x-ui.form.group>

                                <x-ui.form.group name="recommendationTimeline" :label="__('Timeline')" optional>
                                    <x-ui.form.input
                                        name="recommendationTimeline"
                                        maxlength="255"
                                        :placeholder="__('e.g. Before the next rainy season')"
                                        wire:model="recommendationTimeline"
                                    />
                                </x-ui.form.group>

                                <x-ui.form.group name="recommendationDueOn" :label="__('Due date')" optional>
                                    <x-ui.form.input name="recommendationDueOn" type="date" wire:model="recommendationDueOn" />
                                </x-ui.form.group>
                            </div>

                            <div class="flex flex-wrap justify-end gap-2">
                                <x-ui.button variant="secondary" wire:click="cancelRecommendation">
                                    {{ __('Cancel') }}
                                </x-ui.button>
                                <x-ui.button type="submit" icon="plus" loading="saveRecommendation">
                                    {{ __('Raise recommendation') }}
                                </x-ui.button>
                            </div>
                        </form>
                    @endif
                </div>
            </x-ui.card>
        </div>

        {{-- ---------------------------------------------------------------- --}}
        {{-- Sidebar: decisions, team, documents, timeline                     --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="space-y-4">
            <x-ui.card :title="__('Where this stands')">
                @if ($blocked)
                    <p class="text-sm text-ink-muted">{{ $blocked }}</p>
                @endif

                <div class="mt-3 flex flex-col gap-2">
                    @if ($this->canStartFieldwork())
                        <x-ui.button icon="arrow-path" wire:click="startFieldwork" loading="startFieldwork">
                            {{ __('Begin fieldwork') }}
                        </x-ui.button>
                    @endif

                    @if ($this->canMarkDrafted())
                        <x-ui.button icon="document-text" wire:click="markDrafted" loading="markDrafted">
                            {{ __('Mark the draft complete') }}
                        </x-ui.button>

                        @if ($missingSections !== [])
                            <p class="text-xs text-warning-ink">
                                {{ __('Still to write: :sections', ['sections' => implode(', ', $missingSections)]) }}
                            </p>
                        @endif
                    @endif

                    @if ($this->canSubmit())
                        <x-ui.button icon="paper-airplane" wire:click="submitForReview" loading="submitForReview">
                            {{ __('Send up for review') }}
                        </x-ui.button>
                        <p class="text-xs text-ink-muted">
                            {{ __('Findings freeze once the report is under review.') }}
                        </p>
                    @endif

                    @if ($this->canApprove())
                        <x-ui.button icon="check-circle" wire:click="approve" loading="approve">
                            {{ __('Approve the findings') }}
                        </x-ui.button>
                    @endif

                    @if ($this->canSendBack() && ! $sendingBack)
                        <x-ui.button
                            variant="secondary"
                            icon="arrow-uturn-left"
                            wire:click="startSendBack"
                        >{{ __('Send back for revision') }}</x-ui.button>
                    @endif

                    @if ($this->canPublish())
                        <x-ui.button icon="globe" wire:click="publish" loading="publish">
                            {{ __('Publish') }}
                        </x-ui.button>
                        <p class="text-xs text-ink-muted">
                            {{ __('Publication is permanent — findings are corrected by a superseding evaluation.') }}
                        </p>
                    @endif

                    @if ($this->canCancel() && ! $cancelling)
                        <x-ui.button
                            variant="ghost"
                            icon="x-mark"
                            wire:click="startCancel"
                        >{{ __('Cancel this commission') }}</x-ui.button>
                    @endif
                </div>

                @if ($sendingBack || $cancelling)
                    <div class="mt-4 space-y-3 rounded-lg border border-line p-3">
                        <x-ui.form.group
                            name="decisionReason"
                            :label="__('Reason')"
                            :hint="$cancelling
                                ? __('Kept on the permanent record of this commission.')
                                : __('The team reads this and acts on it.')"
                            required
                        >
                            <x-ui.form.textarea
                                name="decisionReason"
                                rows="3"
                                maxlength="2000"
                                has-hint
                                wire:model="decisionReason"
                            />
                        </x-ui.form.group>

                        <div class="flex flex-wrap justify-end gap-2">
                            <x-ui.button
                                variant="secondary"
                                size="sm"
                                wire:click="{{ $cancelling ? 'cancelCancel' : 'cancelSendBack' }}"
                            >{{ __('Never mind') }}</x-ui.button>

                            <x-ui.button
                                size="sm"
                                :variant="$cancelling ? 'destructive' : 'primary'"
                                wire:click="{{ $cancelling ? 'confirmCancel' : 'confirmSendBack' }}"
                                loading="{{ $cancelling ? 'confirmCancel' : 'confirmSendBack' }}"
                            >{{ $cancelling ? __('Cancel commission') : __('Send back') }}</x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('The evaluation team')">
                @if ($evaluation->teamMembers->isEmpty())
                    <p class="text-sm text-ink-muted">{{ __('No team has been named yet.') }}</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($evaluation->teamMembers as $member)
                            <li class="flex items-start gap-2 text-sm" wire:key="member-{{ $member->id }}">
                                <x-ui.icon
                                    :name="$member->isLead() ? 'shield-check' : 'user-circle'"
                                    class="mt-0.5 size-4 shrink-0 text-ink-subtle"
                                />
                                <span class="min-w-0">
                                    <span class="block font-medium text-ink">{{ $member->displayName() }}</span>
                                    <span class="block text-xs text-ink-muted">
                                        {{ $member->isLead() ? __('Evaluation lead') : __('Team member') }}
                                        @if ($member->affiliation())
                                            &middot; {{ $member->affiliation() }}
                                        @endif
                                        @if ($member->expertise)
                                            &middot; {{ $member->expertise }}
                                        @endif
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-3 text-xs text-ink-subtle">
                        {{ __('The lead signs for the findings and cannot approve them.') }}
                    </p>
                @endif
            </x-ui.card>

            {{-- The shared document vault: terms of reference, instruments,
                 validation-workshop minutes, the signed report. --}}
            <livewire:shared.document-panel
                :model="$evaluation"
                collection="evaluation_documents"
                :readonly="! $canEdit"
                :heading="__('Evaluation documents')"
                wire:key="evaluation-documents-{{ $evaluation->ulid }}"
            />

            <x-ui.card :title="__('Lifecycle')">
                @if ($this->timeline->isEmpty())
                    <p class="text-sm text-ink-muted">{{ __('Nothing recorded yet.') }}</p>
                @else
                    <ol class="space-y-3">
                        @foreach ($this->timeline as $event)
                            <li class="flex gap-3 text-sm" wire:key="event-{{ $event->id }}">
                                <x-ui.icon
                                    :name="$event->to_status->icon()"
                                    class="mt-0.5 size-4 shrink-0 text-ink-subtle"
                                />
                                <div class="min-w-0">
                                    <p class="font-medium text-ink">
                                        @if ($event->isCommissioning())
                                            {{ __('Commissioned') }}
                                        @else
                                            {{ $event->to_status->label() }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ $event->actor?->name }} &middot;
                                        {{ $event->occurred_at->translatedFormat('j M Y, H:i') }}
                                    </p>
                                    @if ($event->reason)
                                        <p class="mt-1 text-xs text-ink-muted">{{ $event->reason }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
