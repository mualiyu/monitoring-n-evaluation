{{--
    Challenges register (App\Livewire\Tenant\Issues\IssueIndex).

    The data-heavy pattern from the design system, in order:
    filter bar → stat summary → table (cards under sm:) → pagination.
--}}
@php
    // route(), not url(): the tenant surface lives on a {tenant} subdomain, so
    // every link carries its workspace explicitly. route() fails loudly on a
    // missing route or a wrong binding key; a hand-built string 404s silently.
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $issueUrl = fn ($issue) => route('tenant.issues.show', [...$workspace, 'issue' => $issue]);
    $projectUrl = fn ($project) => route('tenant.projects.show', [...$workspace, 'project' => $project]);
    $createUrl = route('tenant.issues.create', $workspace);

    $canCreate = auth()->user()?->can('create', \App\Models\Issue::class) ?? false;
@endphp

<div>
    <x-ui.page-header
        :title="__('Challenges register')"
        :description="__('Everything standing between this entity\'s projects and their delivery, who owns clearing each one, and by when. Issues left open past their severity allowance escalate automatically.')"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                size="sm"
                icon="exclamation-triangle"
                :href="route('tenant.exceptions.index', $workspace)"
            >{{ __('Exception reports') }}</x-ui.button>

            <x-ui.button
                variant="secondary"
                size="sm"
                icon="arrow-down-tray"
                wire:click="export"
                loading="export"
            >{{ __('Export') }}</x-ui.button>

            @if ($canCreate)
                <x-ui.button size="sm" icon="plus" :href="$createUrl">
                    {{ __('Raise an issue') }}
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
            :label="__('Open challenges')"
            :value="number_format($this->stats['open'])"
            icon="exclamation-circle"
            :hint="__('across every project in this workspace')"
        />
        <x-ui.stat
            :label="__('Critical')"
            :value="number_format($this->stats['critical'])"
            icon="exclamation-triangle"
            :intent="$this->stats['critical'] > 0 ? 'critical' : 'neutral'"
            :hint="$this->stats['critical'] > 0 ? __('escalate soonest') : __('none open')"
        />
        <x-ui.stat
            :label="__('Past their deadline')"
            :value="number_format($this->stats['overdue'])"
            icon="clock"
            :intent="$this->stats['overdue'] > 0 ? 'warning' : 'neutral'"
        />
        <x-ui.stat
            :label="__('Nobody owns them')"
            :value="number_format($this->stats['unowned'])"
            icon="users"
            :intent="$this->stats['unowned'] > 0 ? 'warning' : 'neutral'"
            :hint="__('an issue with no owner is nobody\'s to clear')"
        />
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filter bar                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <x-ui.form.group name="search" :label="__('Search')">
                    <x-ui.form.input
                        name="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Issue, project or reference…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->statusOptions()"
                        wire:model.live="status"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="severity" :label="__('Severity')">
                    <x-ui.form.select
                        name="severity"
                        :placeholder="__('Any severity')"
                        :options="$this->severityOptions()"
                        wire:model.live="severity"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="category" :label="__('Category')">
                    <x-ui.form.select
                        name="category"
                        :placeholder="__('Any category')"
                        :options="$this->categoryOptions()"
                        wire:model.live="category"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="projectUlid" :label="__('Project')">
                    <x-ui.form.select
                        name="projectUlid"
                        :placeholder="__('All projects')"
                        :options="$this->projectOptions"
                        wire:model.live="projectUlid"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-4">
                    <x-ui.form.checkbox
                        name="openOnly"
                        :label="__('Open only')"
                        wire:model.live="openOnly"
                    />
                    <x-ui.form.checkbox
                        name="overdue"
                        :label="__('Past their deadline')"
                        wire:model.live="overdue"
                    />
                </div>

                @if ($this->hasFilters())
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The register                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,severity,category,projectUlid,overdue,openOnly">
            @if ($this->issues->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No challenges match the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="check-circle"
                        :title="__('Nothing is blocking delivery')"
                        :description="__('Challenges raised on a progress return, found at an inspection, or recorded by anyone who sees a problem will appear here with an owner and a deadline.')"
                    >
                        <x-slot:actions>
                            @if ($canCreate)
                                <x-ui.button icon="plus" :href="$createUrl">
                                    {{ __('Raise an issue') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Challenges raised against this workspace\'s projects, worst first')"
                    class="p-4 sm:p-0"
                    :headings="[
                        __('Issue'),
                        __('Project'),
                        __('Severity'),
                        __('Status'),
                        __('Owner'),
                        __('Due'),
                        '',
                    ]"
                >
                    @foreach ($this->issues as $issue)
                        @php $isOverdue = $issue->isOverdue(); @endphp

                        <x-ui.table.row wire:key="issue-{{ $issue->ulid }}">
                            <x-ui.table.cell :label="__('Issue')" primary stacked>
                                <a
                                    href="{{ $issueUrl($issue) }}"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $issue->title }}</a>
                                <span class="mt-0.5 flex items-center gap-1 text-xs font-normal text-ink-muted">
                                    <x-ui.icon :name="$issue->category->icon()" class="size-3.5" />
                                    {{ $issue->category->label() }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                <a
                                    href="{{ $projectUrl($issue->project) }}"
                                    class="rounded text-ink-muted hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >{{ $issue->project->title }}</a>
                                <span class="mt-0.5 block font-mono text-xs text-ink-subtle">
                                    {{ $issue->project->reference }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Severity')">
                                <x-ui.badge
                                    :status="$issue->severity->badgeStatus()"
                                    :label="$issue->severity->label()"
                                    :icon="$issue->severity->icon()"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge
                                    :status="$issue->status->badgeStatus()"
                                    :label="$issue->status->label()"
                                    :icon="$issue->status->icon()"
                                />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Owner')">
                                @if ($issue->owner)
                                    <span class="text-ink">{{ $issue->owner->name }}</span>
                                @else
                                    <span class="text-warning-ink">{{ __('Unassigned') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Due')">
                                @if ($issue->due_date)
                                    <span @class(['font-medium text-critical-ink' => $isOverdue, 'text-ink' => ! $isOverdue])>
                                        {{ $issue->due_date->translatedFormat('j M Y') }}
                                    </span>
                                    @if ($isOverdue)
                                        {{-- Icon + text, never colour alone. --}}
                                        <span class="mt-1 block">
                                            <x-ui.badge status="overdue" size="sm" :label="__('Overdue')" />
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-subtle">&mdash;</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    trailing-icon="chevron-right"
                                    :href="$issueUrl($issue)"
                                >{{ __('Open') }}</x-ui.button>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->issues->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->issues" :label="__('Challenges register pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
