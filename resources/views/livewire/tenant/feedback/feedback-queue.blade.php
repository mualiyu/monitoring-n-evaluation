{{--
    The MDA's feedback moderation queue (App\Livewire\Tenant\Feedback\FeedbackQueue).

    EVERYTHING A MEMBER OF THE PUBLIC TYPED IS RENDERED WITH {{ }} AND NOTHING
    ELSE. Subject, body, submitter name, spam reason — all of it is
    user-generated content submitted by an anonymous stranger on a public form.
    There is no {!! !!} in this file and there must never be one.

    The queue contains this workspace's projects only, and the narrowing is the
    `project` relation inside ListFeedbackForTenant — never a tenant clause here.
    Feedback with no project attached is invisible on this surface by design: it
    belongs to the state secretariat's queue.
--}}
@php
    /*
        <x-ui.badge>'s own map is keyed by other modules' vocabularies ('pending'
        there means "report not yet filed"), so the key is chosen here for its
        TONE only and the label + icon come from FeedbackStatus itself. Status is
        icon + text, never colour alone.
    */
    $tone = [
        'pending' => 'draft',       // neutral
        'published' => 'approved',  // positive
        'rejected' => 'on_hold',    // warning
        'spam' => 'rejected',       // critical
    ];
@endphp

<div>
    <x-ui.page-header
        :title="__('Public feedback')"
        :description="__('What people have said about this workspace\'s projects. Nothing appears on the public portal until someone here publishes it — and refusing to publish a comment is a decision that goes on the record with a reason.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-4" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" :title="__('That could not be done')" class="mb-4">{{ $failure }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (\App\Enums\FeedbackStatus::cases() as $case)
            <x-ui.stat
                :label="$case->label()"
                :value="number_format($this->counts[$case->value] ?? 0)"
                :icon="$case->icon()"
                :intent="$case === \App\Enums\FeedbackStatus::Pending && ($this->counts[$case->value] ?? 0) > 0 ? 'warning' : 'neutral'"
                :hint="$case === \App\Enums\FeedbackStatus::Pending ? __('waiting on this workspace') : null"
            />
        @endforeach
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
                        :placeholder="__('Subject or message…')"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Moderation state')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any state')"
                        :options="$this->statusOptions"
                        wire:model.live="status"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <x-ui.form.checkbox
                    name="flagged"
                    :label="__('Flagged by the spam heuristic only')"
                    :description="__('The filter marks, it never rejects — a human still rules on every one.')"
                    wire:model.live="flagged"
                />

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
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,status,flagged">
            @if ($this->feedback->isEmpty())
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No comment matches the filters you have set.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="chat-bubble"
                        :title="__('Nobody has written in yet')"
                        :description="__('Comments arrive from the public portal, and from staff recording what was said at a town hall or on the phone. Publishing a project is what gives people something to comment on.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="globe" :href="route('tenant.publishing.index')">
                                {{ __('Go to publishing') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            @else
                <x-ui.table
                    :caption="__('Public feedback about this workspace\'s projects, unmoderated first')"
                    class="p-4 sm:p-0"
                    :headings="[__('Comment'), __('Project'), __('Received'), __('State'), '']"
                >
                    @foreach ($this->feedback as $item)
                        <x-ui.table.row wire:key="feedback-{{ $item->ulid }}">
                            <x-ui.table.cell :label="__('Comment')" stacked primary>
                                {{ $item->subject }}

                                <p class="mt-1 text-sm font-normal text-ink-muted">
                                    {{ __('from :who', ['who' => $item->submitterLabel()]) }}
                                    · {{ $item->channel->label() }}
                                </p>

                                <details class="mt-2">
                                    <summary class="cursor-pointer rounded text-sm font-medium text-brand-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                        {{ __('Read the message') }}
                                    </summary>

                                    <p class="mt-2 text-sm leading-6 font-normal whitespace-pre-line text-ink">{{ $item->body }}</p>

                                    @foreach ($item->responses as $response)
                                        <div class="mt-2 rounded-lg border border-line bg-surface-sunken p-3">
                                            <p class="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-ink">
                                                <x-ui.icon :name="$response->is_public ? 'globe' : 'eye'" class="size-3.5" />
                                                {{ $response->is_public ? __('Public response') : __('Internal note') }}
                                                <span class="font-normal text-ink-muted">
                                                    · {{ $response->respondedBy?->name ?? __('unknown') }}
                                                    · {{ $response->responded_at->translatedFormat('j M Y') }}
                                                </span>
                                            </p>
                                            <p class="mt-1 text-sm leading-6 font-normal whitespace-pre-line text-ink">{{ $response->body }}</p>
                                        </div>
                                    @endforeach
                                </details>

                                @if ($item->flagged_as_spam)
                                    <p class="mt-2 inline-flex items-center gap-1.5 text-xs font-normal text-warning-ink">
                                        <x-ui.icon name="exclamation-triangle" class="size-3.5 shrink-0" />
                                        {{ __('Flagged: :reason', ['reason' => $item->spam_reason ?? __('automatic heuristic')]) }}
                                    </p>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Project')">
                                @if ($item->project)
                                    <a
                                        href="{{ route('tenant.projects.show', ['project' => $item->project->ulid]) }}"
                                        class="rounded text-ink-muted underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $item->project->title }}</a>
                                @else
                                    <span class="text-ink-subtle">{{ __('Not attached') }}</span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Received')">
                                <span class="text-ink-muted">{{ $item->created_at?->translatedFormat('j M Y') ?? '—' }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('State')">
                                <x-ui.badge
                                    :status="$tone[$item->status->value]"
                                    :label="$item->status->label()"
                                    :icon="$item->status->icon()"
                                    size="sm"
                                />
                                @if ($item->moderated_at)
                                    <span class="mt-1 block text-xs text-ink-muted">
                                        {{ __('by :who', ['who' => $item->moderatedBy?->name ?? __('unknown')]) }}
                                    </span>
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @can('moderate', $item)
                                        @if ($item->status->canTransitionTo(\App\Enums\FeedbackStatus::Published))
                                            <x-ui.button
                                                size="sm"
                                                icon="globe"
                                                wire:click="publish('{{ $item->ulid }}')"
                                                loading="publish('{{ $item->ulid }}')"
                                            >{{ __('Publish') }}</x-ui.button>
                                        @endif

                                        @if ($item->status->canTransitionTo(\App\Enums\FeedbackStatus::Rejected))
                                            <x-ui.button
                                                size="sm"
                                                variant="secondary"
                                                icon="x-circle"
                                                wire:click="startModeration('{{ $item->ulid }}', 'rejected')"
                                                loading="startModeration('{{ $item->ulid }}', 'rejected')"
                                            >{{ __('Do not publish') }}</x-ui.button>
                                        @endif

                                        @if ($item->status->canTransitionTo(\App\Enums\FeedbackStatus::Spam))
                                            <x-ui.button
                                                size="sm"
                                                variant="ghost"
                                                icon="exclamation-triangle"
                                                wire:click="startModeration('{{ $item->ulid }}', 'spam')"
                                                loading="startModeration('{{ $item->ulid }}', 'spam')"
                                            >{{ __('Spam') }}</x-ui.button>
                                        @endif
                                    @endcan

                                    @can('respond', $item)
                                        <x-ui.button
                                            size="sm"
                                            variant="ghost"
                                            icon="chat-bubble"
                                            wire:click="startResponse('{{ $item->ulid }}')"
                                            loading="startResponse('{{ $item->ulid }}')"
                                        >{{ __('Reply') }}</x-ui.button>
                                    @endcan
                                </div>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->feedback->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->feedback" :label="__('Feedback queue pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Refuse to publish / mark as spam                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="moderate-feedback"
        :title="__('Record a reason')"
        :description="__('Refusing to publish a member of the public\'s comment is a decision the audit trail keeps permanently, with your name on it.')"
        max-width="md"
    >
        <x-ui.form.group
            name="reason"
            :label="__('Reason')"
            :hint="__('Required. Read by anyone auditing how this entity handles public feedback.')"
            required
        >
            <x-ui.form.textarea
                name="reason"
                rows="3"
                maxlength="1000"
                has-hint
                :placeholder="__('e.g. The comment names a private individual and repeats an allegation the monitoring team could not verify on site.')"
                wire:model="reason"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelModeration">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="confirmModeration" loading="confirmModeration" icon="check">
                {{ __('Record it') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Reply                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal
        name="respond-feedback"
        :title="__('Answer this comment')"
        :description="__('A public reply appears under the comment on the portal. An unanswered complaints box teaches people not to write.')"
        max-width="md"
    >
        <x-ui.form.group
            name="responseBody"
            :label="__('Response')"
            :hint="__('Say what was checked and what happens next. Dates carry more than assurances.')"
            required
        >
            <x-ui.form.textarea
                name="responseBody"
                rows="4"
                maxlength="4000"
                has-hint
                :placeholder="__('e.g. The site was inspected on 4 March. The contractor has remobilised and work resumed on 11 March.')"
                wire:model="responseBody"
            />
        </x-ui.form.group>

        <div class="mt-4">
            <x-ui.form.checkbox
                name="responsePublic"
                :label="__('Publish this reply on the portal')"
                :description="__('Only possible once the comment itself is published — otherwise it stays an internal note.')"
                wire:model="responsePublic"
            />
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelResponse">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="submitResponse" loading="submitResponse" icon="paper-airplane">
                {{ __('Send') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
