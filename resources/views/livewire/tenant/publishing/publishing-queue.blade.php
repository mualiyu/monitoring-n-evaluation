{{--
    The MDA's publishing queue (App\Livewire\Tenant\Publishing\PublishingQueue).

    EVERY ROW IS THE PAYLOAD, not the record. Each project is projected through
    App\Support\Publishing\PublicProjectPayload before a single value is printed,
    so what an MDA admin reads on this screen is exactly what a citizen would
    read on the portal — same class, same whitelist, no drift. A queue that
    showed the Eloquent row would be showing the decider one thing and the
    public another, which is the whole failure this module exists to prevent.
    The relations the payload needs are already eager-loaded by
    ListPublishingCandidates, so the projection costs no queries.

    No tenancy bypass anywhere: TenantScope confines the candidate query to this
    workspace, which is the only difference from the oversight twin.
--}}
@php
    use App\Support\Publishing\PublicProjectPayload;
@endphp

<div>
    <x-ui.page-header
        :title="__('Publishing')"
        :description="__('Which of this workspace\'s projects the public can see. Publishing puts the record below on the public portal exactly as previewed; withdrawing removes it from every public surface immediately.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @error('publishing')
        <x-ui.alert variant="critical" class="mb-4" :title="__('That could not be published')">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <x-ui.stat
            :label="__('Eligible projects')"
            :value="number_format($this->projects->total())"
            icon="folder"
            :hint="$this->hasFilters() ? __('matching your filters') : __('awarded or further along')"
        />
        <x-ui.stat
            :label="__('Selected')"
            :value="number_format(count($selected))"
            icon="check-circle"
            :hint="__('for a bulk decision')"
        />
    </div>

    {{-- Filter bar --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Project title or reference…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="state" :label="__('Publication state')">
                    <x-ui.form.select
                        name="state"
                        :placeholder="__('Published and unpublished')"
                        :options="['unpublished' => __('Not published'), 'published' => __('Published')]"
                        wire:model.live="state"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                @if (filled($selected))
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.button size="sm" icon="globe" wire:click="publish" loading="publish">
                            {{ __('Publish selected') }}
                        </x-ui.button>
                        <x-ui.button size="sm" variant="destructive" icon="eye" wire:click="confirmWithdraw" loading="confirmWithdraw">
                            {{ __('Withdraw selected') }}
                        </x-ui.button>
                    </div>
                @else
                    <span></span>
                @endif

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,state">
            @if ($this->projects->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No eligible project matches the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="folder"
                        :title="__('Nothing is eligible for publication yet')"
                        :description="__('A project becomes publishable once it has been awarded. Draft and cancelled projects are never offered here: their figures are a plan, and a plan on a public portal reads as a commitment.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="folder" :href="route('tenant.projects.index')">
                                {{ __('Go to projects') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Projects eligible for publication, unpublished first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        '',
                        __('Project'),
                        __('Status'),
                        ['label' => __('Physical progress'), 'align' => 'right'],
                        ['label' => __('Contract value'), 'align' => 'right'],
                        __('On the portal'),
                        '',
                    ]"
                >
                    @foreach ($this->projects as $project)
                        @php $payload = PublicProjectPayload::for($project)->toArray(); @endphp

                        <x-ui.table.row wire:key="publishing-{{ $payload['ulid'] }}">
                            <x-ui.table.cell :label="__('Select')">
                                {{-- Hand-written rather than <x-ui.form.checkbox>: the
                                     label here has to be screen-reader-only (the row
                                     already names the project), which that component
                                     deliberately does not offer. Same token classes. --}}
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        value="{{ $payload['ulid'] }}"
                                        wire:model.live="selected"
                                        class="size-5 shrink-0 cursor-pointer accent-brand"
                                    />
                                    <span class="sr-only">{{ __('Select :project', ['project' => $payload['title']]) }}</span>
                                </label>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')" primary>
                                {{ $payload['title'] }}
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-subtle">
                                    {{ $payload['reference'] }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$payload['status']" :label="$payload['status_label']" size="sm" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Physical progress')" numeric>
                                <x-ui.progress :value="$payload['physical_progress']" :label="__('Physical progress')" size="sm" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contract value')" numeric>
                                {{ $payload['contract_value_formatted'] ?? '—' }}
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('On the portal')">
                                @if ($payload['published_at'])
                                    <span class="inline-flex items-center gap-1.5 text-positive-ink">
                                        <x-ui.icon name="globe" class="size-4 shrink-0" />
                                        {{ __('Public since :date', ['date' => \Illuminate\Support\Carbon::parse($payload['published_at'])->translatedFormat('j M Y')]) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-ink-muted">
                                        <x-ui.icon name="eye" class="size-4 shrink-0" />
                                        {{ __('Not published') }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        icon="eye"
                                        wire:click="showPreview('{{ $payload['ulid'] }}')"
                                        loading="showPreview('{{ $payload['ulid'] }}')"
                                    >{{ __('Preview') }}</x-ui.button>

                                    @if ($payload['published_at'])
                                        <x-ui.button
                                            size="sm"
                                            variant="secondary"
                                            icon="arrow-uturn-left"
                                            wire:click="confirmWithdraw('{{ $payload['ulid'] }}')"
                                            loading="confirmWithdraw('{{ $payload['ulid'] }}')"
                                        >{{ __('Withdraw') }}</x-ui.button>
                                    @else
                                        <x-ui.button
                                            size="sm"
                                            icon="globe"
                                            wire:click="publish('{{ $payload['ulid'] }}')"
                                            loading="publish('{{ $payload['ulid'] }}')"
                                        >{{ __('Publish') }}</x-ui.button>
                                    @endif
                                </div>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->projects->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->projects" :label="__('Publishing queue pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- What would become public                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="publish-preview"
        :title="__('Exactly what the public would see')"
        :description="__('Every field below, and nothing else. This is the same projection the portal renders — not a mock-up of it.')"
        max-width="xl"
    >
        @if ($this->preview)
            @include('livewire.tenant.publishing.partials.payload-preview', ['payload' => $this->preview])
        @else
            <p class="text-sm text-ink-muted">{{ __('Select a project to preview.') }}</p>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closePreview">{{ __('Close') }}</x-ui.button>

            @if ($this->preview && ! $this->preview['published_at'])
                <x-ui.button icon="globe" wire:click="publish('{{ $this->preview['ulid'] }}')" loading="publish">
                    {{ __('Publish this') }}
                </x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Withdraw                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="confirm-withdraw"
        :title="__('Withdraw from the public portal?')"
        :description="__('The project leaves the list, the detail page, the map and the counters immediately. Photograph links that were shared stop working in the same instant.')"
        max-width="md"
    >
        <x-ui.form.group
            name="reason"
            :label="__('Why is this being withdrawn?')"
            :hint="__('Required. The public saw this record, and the audit trail has to say why they no longer do.')"
            required
        >
            <x-ui.form.textarea
                name="reason"
                rows="3"
                maxlength="1000"
                has-hint
                :placeholder="__('e.g. The published contract value predates the approved variation and is being corrected.')"
                wire:model="reason"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="$dispatch('close-modal', 'confirm-withdraw')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="destructive" icon="arrow-uturn-left" wire:click="withdraw" loading="withdraw">
                {{ __('Withdraw') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
