{{--
    Challenges on one project (App\Livewire\Tenant\Issues\ProjectIssuesPanel).

        <livewire:tenant.issues.project-issues-panel :project="$project" />

    Embeddable on any screen that shows a project. Read-only and short: it
    answers "what is blocking this project", not "manage the register" — the
    full list, the filters and every mutation live one click away on /issues.
--}}
@php
    $workspace = ['tenant' => app(\App\Tenancy\CurrentTenant::class)->getOrFail()->slug];
    $issueUrl = fn ($issue) => route('tenant.issues.show', [...$workspace, 'issue' => $issue]);

    $canCreate = auth()->user()?->can('create', \App\Models\Issue::class) ?? false;
    $registerUrl = route('tenant.issues.index', [...$workspace, 'project' => $this->project->ulid]);
@endphp

<div>
    <x-ui.card
        :title="__('Open challenges')"
        :subtitle="trans_choice('{0} Nothing is blocking this project|{1} :count challenge is open|[2,*] :count challenges are open', $this->openCount, ['count' => $this->openCount])"
        flush
    >
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="plus"
                    :href="route('tenant.issues.create', [...$workspace, 'project' => $this->project->ulid])"
                >{{ __('Raise') }}</x-ui.button>
            @endif
        </x-slot:actions>

        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="3" />
        </div>

        <div wire:loading.delay.long.remove>
            @if ($this->issues->isEmpty())
                <x-ui.empty-state
                    compact
                    icon="check-circle"
                    :title="__('Nothing is blocking this project')"
                    :description="__('Challenges recorded against it — on a return, at an inspection, or by anyone who sees a problem — appear here.')"
                />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->issues as $issue)
                        <li class="p-4" wire:key="panel-issue-{{ $issue->ulid }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <a
                                        href="{{ $issueUrl($issue) }}"
                                        class="rounded text-sm font-medium text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $issue->title }}</a>

                                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
                                        <span class="inline-flex items-center gap-1">
                                            <x-ui.icon :name="$issue->category->icon()" class="size-3.5" />
                                            {{ $issue->category->label() }}
                                        </span>

                                        <span>
                                            {{ $issue->owner
                                                ? __('Owner: :name', ['name' => $issue->owner->name])
                                                : __('Unassigned') }}
                                        </span>

                                        @if ($issue->due_date)
                                            <span @class(['text-critical-ink font-medium' => $issue->isOverdue()])>
                                                {{ $issue->isOverdue()
                                                    ? __('Overdue since :date', ['date' => $issue->due_date->translatedFormat('j M')])
                                                    : __('Due :date', ['date' => $issue->due_date->translatedFormat('j M')]) }}
                                            </span>
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                    <x-ui.badge
                                        size="sm"
                                        :status="$issue->severity->badgeStatus()"
                                        :label="$issue->severity->label()"
                                        :icon="$issue->severity->icon()"
                                    />
                                    <x-ui.badge
                                        size="sm"
                                        :status="$issue->status->badgeStatus()"
                                        :label="$issue->status->label()"
                                        :icon="$issue->status->icon()"
                                    />
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($this->openCount > $this->issues->count())
            <x-slot:footer>
                <a
                    href="{{ $registerUrl }}"
                    class="inline-flex items-center gap-1 rounded text-sm font-medium text-brand-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    {{ __('See all :count open challenges', ['count' => $this->openCount]) }}
                    <x-ui.icon name="chevron-right" class="size-4" />
                </a>
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
