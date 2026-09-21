{{--
    Commencement notices for one project
    (App\Livewire\Tenant\Lifecycle\ProjectCommencement).

    A card per award rather than a table: a project usually has ONE contract,
    and the question on this screen is not "compare these rows" but "has this
    contractor been told to start, and if not, tell them". Each card therefore
    carries the award facts, the notice state, the action, and the served
    document itself.
--}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly and fails loudly if the
    // route or its binding key is wrong.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];

    $contracts = $this->contracts;
    $served = $contracts->filter(fn ($c) => $this->noticeFor($c)?->status->isServed() ?? false)->count();
    $overdue = $contracts->filter(fn ($c) => $this->noticeFor($c)?->isOverdue() ?? false)->count();

    $canIssue = auth()->user()?->can('issue', [\App\Models\CommencementNotice::class, $this->project]) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Commencement notices')"
        :description="__('The instruction to start work. Every award must be served on its contractor within :days of the award date — monitoring runs from the date of service.', ['days' => trans_choice('{1} :count day|[2,*] :count days', $this->noticeDays, ['count' => $this->noticeDays])])"
        :back="route('tenant.projects.show', [...$workspace, 'project' => $project])"
        :back-label="__('Back to project')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => route('tenant.projects.index', $workspace)],
            ['label' => $project->title, 'href' => route('tenant.projects.show', [...$workspace, 'project' => $project])],
            ['label' => __('Commencement')],
        ]"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">
            {{ $failure }}
        </x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat
            :label="__('Awards on this project')"
            :value="number_format($contracts->count())"
            icon="clipboard-document-check"
            :hint="__('variations excluded')"
        />
        <x-ui.stat
            :label="__('Notices served')"
            :value="number_format($served)"
            icon="paper-airplane"
            :intent="$served === $contracts->count() && $contracts->isNotEmpty() ? 'positive' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Past the statutory window')"
            :value="number_format($overdue)"
            icon="exclamation-triangle"
            :intent="$overdue > 0 ? 'critical' : 'neutral'"
            :hint="$overdue > 0 ? __('reported to the entity administrator') : __('nothing outstanding')"
        />
    </div>

    <div wire:loading.delay.long.flex class="hidden">
        <x-ui.skeleton variant="table" :rows="3" />
    </div>

    <div wire:loading.delay.long.remove class="space-y-4">
        @forelse ($contracts as $contract)
            @php
                $notice = $this->noticeFor($contract);
                $isOverdue = $notice?->isOverdue() ?? false;
            @endphp

            <x-ui.card wire:key="contract-{{ $contract->ulid }}">
                <x-slot:title>{{ $contract->contractor->name }}</x-slot:title>
                <x-slot:subtitle>
                    {{ __('Contract :number · awarded :date', [
                        'number' => $contract->contract_number,
                        'date' => $contract->award_date->translatedFormat('j M Y'),
                    ]) }}
                </x-slot:subtitle>

                <x-slot:actions>
                    @if ($notice === null || ! $notice->status->isServed())
                        <x-ui.badge
                            :status="$isOverdue ? 'overdue' : 'pending'"
                            :label="$isOverdue ? __('Overdue') : __('Not yet issued')"
                        />
                        @if ($canIssue)
                            <x-ui.button
                                size="sm"
                                icon="paper-airplane"
                                wire:click="startIssue('{{ $contract->ulid }}')"
                                loading="startIssue('{{ $contract->ulid }}')"
                            >{{ __('Issue notice') }}</x-ui.button>
                        @endif
                    @else
                        <x-ui.badge :status="$notice->status->badge()" :label="$notice->status->label()" />

                        @if ($notice->status !== \App\Enums\CommencementNoticeStatus::Acknowledged)
                            @can('acknowledge', $notice)
                                <x-ui.button
                                    variant="secondary"
                                    size="sm"
                                    icon="check-circle"
                                    wire:click="startAcknowledge('{{ $notice->ulid }}')"
                                    loading="startAcknowledge('{{ $notice->ulid }}')"
                                >{{ __('Record receipt') }}</x-ui.button>
                            @endcan
                        @endif
                    @endif
                </x-slot:actions>

                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Contract sum') }}</dt>
                        <dd class="mt-0.5 font-medium text-ink">{{ $contract->sum->format() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Notice due') }}</dt>
                        <dd @class(['mt-0.5 font-medium', 'text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                            @if ($notice)
                                {{ $notice->due_at->translatedFormat('j M Y') }}
                            @else
                                {{ $contract->award_date->copy()->addDays($this->noticeDays)->translatedFormat('j M Y') }}
                            @endif
                        </dd>
                        @if ($isOverdue)
                            <dd class="text-xs text-critical-ink">
                                {{ trans_choice('{1} :count day late|[2,*] :count days late', $notice->daysLate(), ['count' => $notice->daysLate()]) }}
                            </dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Commencement') }}</dt>
                        <dd class="mt-0.5 text-ink">
                            {{ $notice?->commencement_date?->translatedFormat('j M Y')
                                ?? $contract->commencement_date?->translatedFormat('j M Y')
                                ?? __('Not set') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Expected completion') }}</dt>
                        <dd class="mt-0.5 text-ink">
                            {{ $notice?->expected_completion_date?->translatedFormat('j M Y')
                                ?? $contract->expected_completion_date?->translatedFormat('j M Y')
                                ?? __('Not set') }}
                        </dd>
                    </div>
                </dl>

                @if ($notice && $notice->status->isServed())
                    <div class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                        <p class="text-ink-muted">
                            {{ __('Served :date by :name.', [
                                'date' => $notice->issued_at?->translatedFormat('j M Y') ?? '—',
                                'name' => $notice->issuedBy?->name ?? __('an account since removed'),
                            ]) }}
                            @if ($notice->issued_late)
                                <span class="text-critical-ink">{{ __('Served after the statutory window.') }}</span>
                            @endif
                        </p>

                        @if ($notice->acknowledged_by_contractor_at)
                            <p class="text-ink-muted">
                                {{ __('Receipt recorded :date by :name.', [
                                    'date' => $notice->acknowledged_by_contractor_at->translatedFormat('j M Y'),
                                    'name' => $notice->acknowledgedBy?->name ?? __('an account since removed'),
                                ]) }}
                            </p>
                            @if ($notice->acknowledgement_note)
                                <p class="text-ink">{{ $notice->acknowledgement_note }}</p>
                            @endif
                        @endif

                        @if ($notice->instructions)
                            <div>
                                <span class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Instructions') }}</span>
                                <p class="text-ink">{{ $notice->instructions }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4">
                        <livewire:shared.document-panel
                            :model="$notice"
                            collection="commencement_notice"
                            :heading="__('The served notice')"
                            :key="'notice-docs-'.$notice->ulid"
                        />
                    </div>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card flush>
                <x-ui.empty-state
                    icon="clipboard-document-check"
                    :title="__('No contract has been awarded yet')"
                    :description="__('A commencement notice instructs a contractor to begin work it has been awarded. Award the contract first and the notice will be due here.')"
                >
                    <x-slot:actions>
                        <x-ui.button
                            variant="secondary"
                            icon="arrow-left"
                            :href="route('tenant.projects.show', [...$workspace, 'project' => $project])"
                        >{{ __('Back to project') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        @endforelse
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Issue a notice                                                    --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canIssue)
        <x-ui.modal
            name="issue-notice"
            :title="__('Issue the commencement notice?')"
            :description="__('The contractor is instructed to begin, the notice is generated and filed against the contract, and the assigned contractor accounts are notified. The date of service goes on the record.')"
            max-width="lg"
        >
            <div class="space-y-4">
                <x-ui.form.group
                    name="commencementDate"
                    :label="__('Commencement date')"
                    :hint="__('The date the contractor is instructed to start. It cannot precede the award.')"
                    required
                >
                    <x-ui.form.input
                        name="commencementDate"
                        type="date"
                        has-hint
                        wire:model="commencementDate"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="instructions"
                    :label="__('Further instructions')"
                    :hint="__('Optional. Printed on the notice — site access, reporting arrangements, anything the contractor must know before starting.')"
                >
                    <x-ui.form.textarea
                        name="instructions"
                        rows="3"
                        maxlength="2000"
                        has-hint
                        wire:model="instructions"
                    />
                </x-ui.form.group>
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelIssue">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="issue" loading="issue" icon="paper-airplane">
                    {{ __('Issue notice') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Record receipt                                                    --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="acknowledge-notice"
        :title="__('Record the contractor’s receipt?')"
        :description="__('Confirms that the notice reached the contractor. Receipt is recorded once and cannot be undone.')"
        max-width="md"
    >
        <x-ui.form.group
            name="acknowledgementNote"
            :label="__('Note')"
            :hint="__('Optional. How receipt was confirmed — a signed hard copy returned, an email, a site meeting.')"
        >
            <x-ui.form.textarea
                name="acknowledgementNote"
                rows="3"
                maxlength="1000"
                has-hint
                wire:model="acknowledgementNote"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelAcknowledge">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="confirmAcknowledge" loading="confirmAcknowledge" icon="check-circle">
                {{ __('Record receipt') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
