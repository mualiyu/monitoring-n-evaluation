{{--
    One contract (App\Livewire\Tenant\Projects\ContractDetail): terms as
    awarded, the amendment register on top of them, and the instrument's own
    documents.

    No edit affordance anywhere, deliberately — the award terms are immutable
    at the model and a correction is a new row in the register. The only
    mutation offered is "Record a variation".
--}}
@php
    $project = $this->project;
    $contract = $this->record;
    $head = $this->head;
    $variations = $this->variations;
    $isVariation = $contract->isVariation();
@endphp

<div>
    <x-ui.page-header
        :title="$contract->contract_number"
        :description="$project->title"
        :back="$this->contractsTabUrl"
        :back-label="__('Back to contracts')"
        :breadcrumbs="[
            ['label' => __('Projects'), 'href' => $this->projectsUrl],
            ['label' => $project->reference, 'href' => $this->projectUrl],
            ['label' => __('Contracts'), 'href' => $this->contractsTabUrl],
            ['label' => $contract->contract_number],
        ]"
    >
        <x-slot:actions>
            @if ($this->canRecordVariation)
                <x-ui.button size="sm" icon="arrows-right-left" :href="$this->recordVariationUrl">
                    {{ __('Record a variation') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    {{-- Identity strip --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        <x-ui.badge :status="$contract->status->value" :label="$contract->status->label()" />
        {{-- Procurement category, not a lifecycle state: an unmapped status
             key degrades to the neutral pill by design, and the label and icon
             are given explicitly. --}}
        <x-ui.badge status="contract-type" icon="banknotes" :label="$contract->type->label()" />
        @if ($isVariation)
            <x-ui.badge status="under_review" icon="arrows-right-left" :label="__('Variation')" />
        @endif
        <span class="text-sm text-ink-muted">{{ $contract->contractor?->name }}</span>
        @if ($contract->contractor?->is_blacklisted)
            <x-ui.badge status="rejected" size="sm" :label="__('Blacklisted firm')" />
        @endif
    </div>

    @if ($isVariation)
        <x-ui.alert variant="neutral" icon="arrows-right-left" class="mb-5" :title="__('This is a variation, not an award')">
            {{ __('It amends contract :number. The award itself is unchanged — that is what the amendment register is for.', ['number' => $head->contract_number]) }}
            <a href="{{ $this->headUrl }}" class="ml-1 rounded font-medium text-brand-ink hover:underline">
                {{ __('Open the award') }}
            </a>
        </x-ui.alert>
    @endif

    {{-- Money at a glance --}}
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <x-ui.stat
            :label="__('Award sum')"
            :value="$head->sum->format()"
            :hint="__('Immutable once recorded')"
            icon="banknotes"
        />
        <x-ui.stat
            :label="__('Variations')"
            :value="(string) $variations->count()"
            :hint="trans_choice('{0}No amendments recorded|{1}One amendment recorded|[2,*]:count amendments recorded', $variations->count(), ['count' => $variations->count()])"
            icon="arrows-right-left"
        />
        <x-ui.stat
            :label="__('Value today')"
            :value="$this->revisedValue->format()"
            :hint="__('Award sum plus every variation')"
            icon="clipboard-check"
        />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card
                :title="__('Terms')"
                :subtitle="__('The sum, date, contractor and scope cannot be edited — corrections are recorded as variations.')"
            >
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Contractor') }}</dt>
                        <dd class="mt-1 text-sm text-ink">
                            {{ $contract->contractor?->name ?? '—' }}
                            @if ($contract->contractor?->rc_number)
                                <span class="block font-mono text-xs text-ink-muted">{{ $contract->contractor->rc_number }}</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">
                            {{ $isVariation ? __('Variation amount') : __('Award sum') }}
                        </dt>
                        <dd class="mt-1 text-sm text-ink tabular-nums">{{ $contract->sum->format() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">
                            {{ $isVariation ? __('Date of variation') : __('Award date') }}
                        </dt>
                        <dd class="mt-1 text-sm text-ink">{{ $contract->award_date?->translatedFormat('j M Y') ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Commencement date') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $contract->commencement_date?->translatedFormat('j M Y') ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Expected completion') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $contract->expected_completion_date?->translatedFormat('j M Y') ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Duration') }}</dt>
                        <dd class="mt-1 text-sm text-ink tabular-nums">
                            {{ $contract->duration_days ? trans_choice('{1}:count day|[2,*]:count days', $contract->duration_days, ['count' => $contract->duration_days]) : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Retention') }}</dt>
                        <dd class="mt-1 text-sm text-ink tabular-nums">
                            {{ $contract->retention_percentage !== null ? rtrim(rtrim((string) $contract->retention_percentage, '0'), '.').'%' : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Recorded by') }}</dt>
                        <dd class="mt-1 text-sm text-ink">
                            {{ $contract->createdBy?->name ?? '—' }}
                            <span class="block text-xs text-ink-muted">
                                {{ $contract->created_at?->timezone(config('platform.instance.timezone'))->translatedFormat('j M Y, H:i') }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <div class="mt-5 space-y-3 border-t border-line pt-4">
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-ink-muted uppercase">
                            {{ $isVariation ? __('Varied works') : __('Scope of works') }}
                        </h3>
                        <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ $contract->scope_of_works }}</p>
                    </div>

                    @if ($contract->variation_reason)
                        <div>
                            <h3 class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Reason for the variation') }}</h3>
                            <p class="mt-1 rounded-lg bg-surface-sunken px-3 py-2 text-sm whitespace-pre-line text-ink-muted">
                                {{ $contract->variation_reason }}
                            </p>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card
                flush
                :title="__('Amendment register')"
                :subtitle="__('Every variation raised against this award, in the order it was issued.')"
            >
                <x-slot:actions>
                    @if ($this->canRecordVariation)
                        <x-ui.button size="sm" variant="secondary" icon="plus" :href="$this->recordVariationUrl">
                            {{ __('Record a variation') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>

                @if ($variations->isEmpty())
                    <x-ui.empty-state
                        compact
                        icon="arrows-right-left"
                        :title="__('No variations recorded')"
                        :description="__('The contract still stands at its award sum. A change in scope or price is recorded here as its own instrument, leaving the original figures readable.')"
                    />
                @else
                    <x-ui.table
                        :caption="__('Variations raised against this contract')"
                        class="p-4 sm:p-0"
                        :headings="[__('Variation'), __('Reason'), ['label' => __('Amount'), 'align' => 'right'], __('Recorded by')]"
                    >
                        @foreach ($variations as $variation)
                            <x-ui.table.row wire:key="variation-{{ $variation->ulid }}">
                                <x-ui.table.cell :label="__('Variation')" primary>
                                    <a
                                        href="{{ $this->contractUrl($variation) }}"
                                        class="rounded font-medium text-brand-ink hover:underline"
                                    >{{ $variation->contract_number }}</a>
                                    <span class="block text-xs font-normal text-ink-muted">
                                        {{ $variation->award_date?->translatedFormat('j M Y') }}
                                    </span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Reason')" stacked>
                                    <span class="text-sm text-ink-muted">{{ \Illuminate\Support\Str::limit((string) $variation->variation_reason, 140) }}</span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Amount')" numeric>
                                    <span @class(['font-medium', 'text-critical-ink' => ! $variation->sum->isPositive()])>
                                        {{ $variation->sum->isPositive() ? '+' : '' }}{{ $variation->sum->format() }}
                                    </span>
                                    <span class="block text-xs font-normal text-ink-muted">
                                        {{ $variation->sum->isPositive() ? __('Additional works') : __('Omission') }}
                                    </span>
                                </x-ui.table.cell>

                                <x-ui.table.cell :label="__('Recorded by')">
                                    {{ $variation->createdBy?->name ?? '—' }}
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach

                        <x-slot:footer>
                            <tr class="block sm:table-row">
                                <td class="block px-4 py-3 text-sm text-ink-muted sm:table-cell" colspan="2">
                                    {{ __('Value today') }}
                                </td>
                                <td class="block px-4 py-3 text-sm font-semibold text-ink tabular-nums sm:table-cell sm:text-right" colspan="2">
                                    {{ $this->revisedValue->format() }}
                                </td>
                            </tr>
                        </x-slot:footer>
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            {{-- The contract's own paper trail: award letter, BOQ, variation
                 instruments. Private disk, signed downloads, policy-checked. --}}
            <livewire:shared.document-panel
                :key="'contract-documents-'.$contract->ulid"
                :model="$contract"
                collection="contract_documents"
            />
        </div>
    </div>
</div>
