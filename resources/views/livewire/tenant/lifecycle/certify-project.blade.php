{{--
    Certification screen (App\Livewire\Tenant\Lifecycle\CertifyProject).

    Preconditions first, form second, history third — in that order because
    the most common outcome of opening this screen is learning that the
    project is not ready, and burying that under a form invites an officer to
    fill it in and then be refused.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];

    $canIssue = auth()->user()?->can('issue', [\App\Models\Certificate::class, $this->project]) ?? false;
    $selectedType = \App\Enums\CertificateType::tryFrom($this->type) ?? \App\Enums\CertificateType::PracticalCompletion;
    $certificates = $this->certificates;
@endphp

<div>
    <x-ui.page-header
        :title="__('Completion certification')"
        :description="__('The signature that releases the works, starts or closes the defects-liability period and unlocks payment. It is recorded against the project and visible to state oversight.')"
        :back="route('tenant.projects.show', [...$workspace, 'project' => $project])"
        :back-label="__('Back to project')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => route('tenant.projects.index', $workspace)],
            ['label' => $project->title, 'href' => route('tenant.projects.show', [...$workspace, 'project' => $project])],
            ['label' => __('Certification')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="document-check"
                :href="route('tenant.certificates.index', $workspace)"
            >{{ __('Certificate register') }}</x-ui.button>
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

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ------------------------------------------------------------ --}}
        {{-- Preconditions                                                 --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card class="lg:col-span-1" :title="__('Before certifying')">
            <ul class="space-y-3">
                @foreach ($this->preconditions as $check)
                    <li class="flex gap-2.5" wire:key="check-{{ $check['key'] }}">
                        {{-- Icon AND text: status is never conveyed by colour alone. --}}
                        <x-ui.icon
                            :name="$check['met'] ? 'check-circle' : 'exclamation-triangle'"
                            @class([
                                'mt-0.5 size-5 shrink-0',
                                'text-positive-ink' => $check['met'],
                                'text-warning-ink' => ! $check['met'],
                            ])
                        />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">
                                {{ $check['label'] }}
                                <span class="sr-only">
                                    &mdash; {{ $check['met'] ? __('met') : __('not met') }}
                                </span>
                            </p>
                            <p class="text-xs text-ink-muted">{{ $check['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>

            @unless ($this->canCertify)
                <x-ui.alert variant="warning" class="mt-4">
                    {{ __('Certification is unavailable until the items above are met.') }}
                </x-ui.alert>
            @endunless
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- The certificate                                               --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card class="lg:col-span-2" :title="__('Issue a certificate')">
            @if (! $canIssue)
                <x-ui.empty-state
                    compact
                    icon="lock-closed"
                    :title="__('You cannot sign a completion certificate')"
                    :description="__('Certification is held by the entity administrator. You can read the register below.')"
                />
            @else
                <div class="space-y-4">
                    <x-ui.form.group
                        name="type"
                        :label="__('Certificate')"
                        :hint="$selectedType->description()"
                        required
                    >
                        <x-ui.form.select
                            name="type"
                            has-hint
                            :options="$this->typeOptions"
                            wire:model.live="type"
                        />
                    </x-ui.form.group>

                    @if ($selectedType->opensDefectsLiability())
                        <x-ui.form.group
                            name="defectsLiabilityEndsOn"
                            :label="__('Defects-liability period ends')"
                            :hint="__('Optional but strongly advised: it is the date until which the contractor can still be compelled back to site.')"
                        >
                            <x-ui.form.input
                                name="defectsLiabilityEndsOn"
                                type="date"
                                has-hint
                                wire:model="defectsLiabilityEndsOn"
                            />
                        </x-ui.form.group>
                    @endif

                    <x-ui.form.group
                        name="narrative"
                        :label="__('Remarks')"
                        :hint="__('Optional. Printed on the certificate — outstanding minor works, conditions of acceptance, anything the record should carry.')"
                    >
                        <x-ui.form.textarea
                            name="narrative"
                            rows="4"
                            maxlength="2000"
                            has-hint
                            wire:model="narrative"
                        />
                    </x-ui.form.group>

                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-ui.button
                            icon="document-check"
                            wire:click="certify"
                            loading="certify"
                            :disabled="! $this->canCertify"
                        >{{ __('Issue certificate') }}</x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- History                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mt-4" :title="__('Certificates on this project')" flush>
        @if ($certificates->isEmpty())
            <x-ui.empty-state
                compact
                icon="document-check"
                :title="__('Nothing certified yet')"
                :description="__('Certificates issued for this project appear here, withdrawn ones included — the register is the project’s certification history, not just its current state.')"
            />
        @else
            <x-ui.table
                class="p-4 sm:p-0"
                :caption="__('Completion certificates issued for this project')"
                :headings="[
                    __('Certificate'),
                    __('Type'),
                    __('Issued'),
                    __('Defects liability'),
                    __('Status'),
                    '',
                ]"
            >
                @foreach ($certificates as $certificate)
                    <x-ui.table.row wire:key="certificate-{{ $certificate->ulid }}">
                        <x-ui.table.cell :label="__('Certificate')" primary>
                            <span class="font-mono text-xs">{{ $certificate->reference }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Type')">
                            {{ $certificate->type->shortLabel() }}
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Issued')">
                            {{ $certificate->issued_at->translatedFormat('j M Y') }}
                            <span class="mt-0.5 block text-xs text-ink-muted">
                                {{ $certificate->issuedBy?->name ?? __('an account since removed') }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Defects liability')">
                            @if ($certificate->defects_liability_ends_on)
                                {{ $certificate->defects_liability_ends_on->translatedFormat('j M Y') }}
                                @if ($certificate->isUnderDefectsLiability())
                                    <span class="mt-0.5 block text-xs text-ink-muted">{{ __('still running') }}</span>
                                @endif
                            @else
                                &mdash;
                            @endif
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Status')">
                            @if ($certificate->isRevoked())
                                <x-ui.badge status="rejected" :label="__('Withdrawn')" />
                                <span class="mt-0.5 block text-xs text-ink-muted">
                                    {{ $certificate->revocation_reason }}
                                </span>
                            @else
                                <x-ui.badge status="certified" :label="__('In force')" />
                            @endif
                        </x-ui.table.cell>

                        <x-ui.table.cell align="right">
                            @if (! $certificate->isRevoked())
                                @can('revoke', $certificate)
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-circle"
                                        wire:click="startRevoke('{{ $certificate->ulid }}')"
                                        loading="startRevoke('{{ $certificate->ulid }}')"
                                    >{{ __('Withdraw') }}</x-ui.button>
                                @endcan
                            @endif
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    @foreach ($certificates as $certificate)
        @if (! $certificate->isRevoked())
            <div class="mt-4" wire:key="certificate-docs-{{ $certificate->ulid }}">
                <livewire:shared.document-panel
                    :model="$certificate"
                    collection="certificate"
                    :heading="__('Certificate :reference', ['reference' => $certificate->reference])"
                    :key="'cert-docs-'.$certificate->ulid"
                />
            </div>
        @endif
    @endforeach

    {{-- ---------------------------------------------------------------- --}}
    {{-- Withdrawal                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($canIssue)
        <x-ui.modal
            name="revoke-certificate"
            :title="__('Withdraw this certificate?')"
            :description="__('The certificate stays in the register, marked as withdrawn, and its number is never re-used. The project remains certified — issue a corrected certificate if one is needed.')"
            max-width="md"
        >
            <x-ui.form.group
                name="revocationReason"
                :label="__('Reason')"
                :hint="__('Required. Kept on the record and read by anyone auditing this project.')"
                required
            >
                <x-ui.form.textarea
                    name="revocationReason"
                    rows="3"
                    maxlength="1000"
                    has-hint
                    :placeholder="__('e.g. Issued against the wrong contract lot; corrected certificate to follow.')"
                    wire:model="revocationReason"
                />
            </x-ui.form.group>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelRevoke">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="confirmRevoke" loading="confirmRevoke" icon="x-circle">
                    {{ __('Withdraw certificate') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
