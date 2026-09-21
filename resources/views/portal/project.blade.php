{{--
    One published project, in public.

    $project is an ARRAY from App\Support\Publishing\PublicProjectPayload — the
    whitelist, not a model. There is no `$project->` anywhere in this file and
    there must never be: the payload is the only reason a column added to
    `projects` next quarter cannot reach a citizen's browser by accident.

    $feedback is a Collection of published Feedback with their public responses
    already loaded. It is USER-GENERATED CONTENT: every one of those values goes
    through `{{ }}`. There is no `{!! !!}` on this surface, for anything, ever.
    `publicResponses` is eager-loaded; `respondedBy` deliberately is NOT — the
    individual officer who answered is not published (see the payload's notes on
    naming civil servants), and touching the relation would also trip
    preventLazyLoading.
--}}
@php
    $title = $project['title'];

    $dates = array_filter([
        ['label' => __('Started'), 'value' => $project['start_date']],
        ['label' => __('Due'), 'value' => $project['revised_end_date'] ?? $project['expected_end_date']],
        ['label' => __('Finished'), 'value' => $project['actual_end_date']],
    ], fn (array $date): bool => $date['value'] !== null);

    $locations = $project['locations'];
    $photos = $project['photos'];
@endphp

@extends('layouts.portal')

@section('content')
    <x-ui.page-header
        :title="$project['title']"
        :breadcrumbs="[
            ['label' => __('Home'), 'href' => route('portal.home')],
            ['label' => __('Published projects'), 'href' => route('portal.projects.index')],
            ['label' => $project['reference']],
        ]"
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('portal.feedback.create', ['project' => $project['ulid']])"
                variant="secondary"
                icon="chat-bubble"
            >{{ __('Comment on this project') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.badge :status="$project['status']" :label="$project['status_label']" />
        <span class="font-mono text-xs text-ink-subtle">{{ $project['reference'] }}</span>
        @if ($project['published_at'])
            <span class="text-xs text-ink-muted">
                {{ __('Published :date', ['date' => \Illuminate\Support\Carbon::parse($project['published_at'])->translatedFormat('j M Y')]) }}
            </span>
        @endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- What it is --}}
            <x-ui.card :title="__('About this project')">
                @if ($project['description'])
                    <p class="text-sm leading-6 text-ink">{{ $project['description'] }}</p>
                @endif

                @if ($project['goal'])
                    <h3 class="mt-5 text-sm font-semibold text-ink">{{ __('Goal') }}</h3>
                    <p class="mt-1 text-sm leading-6 text-ink-muted">{{ $project['goal'] }}</p>
                @endif

                @if ($project['objectives'])
                    <h3 class="mt-5 text-sm font-semibold text-ink">{{ __('Objectives') }}</h3>
                    <p class="mt-1 text-sm leading-6 whitespace-pre-line text-ink-muted">{{ $project['objectives'] }}</p>
                @endif

                @if (! $project['description'] && ! $project['goal'] && ! $project['objectives'])
                    <p class="text-sm text-ink-muted">{{ __('No description has been published for this project yet.') }}</p>
                @endif
            </x-ui.card>

            {{-- Where it is --}}
            <x-ui.card :title="__('Sites')" :subtitle="__('Where the work is being done')" flush>
                @if (blank($locations))
                    <x-ui.empty-state
                        compact
                        icon="map-pin"
                        :title="__('No site has been published')"
                        :description="__('The entity has not published a location for this project.')"
                    />
                @else
                    <x-ui.table
                        :caption="__('Published sites for this project')"
                        class="p-4 sm:p-0"
                        :headings="[__('Site'), __('Local government area'), __('Ward'), __('Coordinates')]"
                    >
                        @foreach ($locations as $location)
                            <x-ui.table.row>
                                <x-ui.table.cell :label="__('Site')" primary>
                                    {{ $location['site_name'] ?? __('Main site') }}
                                    @if ($location['is_primary'])
                                        <span class="ml-1 text-xs font-normal text-ink-muted">{{ __('(primary)') }}</span>
                                    @endif
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Local government area')">
                                    <span class="text-ink-muted">{{ $location['lga'] ?? '—' }}</span>
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Ward')">
                                    <span class="text-ink-muted">{{ $location['ward'] ?? '—' }}</span>
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Coordinates')">
                                    @if ($location['latitude'] !== null && $location['longitude'] !== null)
                                        <span class="font-mono text-xs text-ink-muted">{{ $location['latitude'] }}, {{ $location['longitude'] }}</span>
                                    @else
                                        <span class="text-ink-subtle">—</span>
                                    @endif
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            {{-- Site photography --}}
            @if (filled($photos))
                <x-ui.card :title="__('Site photographs')" :subtitle="__('Taken during monitoring visits')">
                    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($photos as $photo)
                            <li>
                                <figure>
                                    {{-- The re-encoded conversion, served through the
                                         portal's own route: no disk path, no original,
                                         no EXIF, no GPS fix of the field monitor. --}}
                                    <img
                                        src="{{ route('portal.projects.photo', ['ulid' => $project['ulid'], 'uuid' => $photo['uuid']]) }}"
                                        alt="{{ $photo['caption'] }}"
                                        loading="lazy"
                                        class="aspect-4/3 w-full rounded-lg border border-line object-cover"
                                    />
                                    <figcaption class="mt-1 text-xs text-ink-muted">{{ $photo['caption'] }}</figcaption>
                                </figure>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            {{-- What the public said --}}
            <x-ui.card
                :title="__('What people have said')"
                :subtitle="__('Comments published after review by the monitoring team')"
                flush
            >
                <x-slot:actions>
                    <x-ui.button
                        :href="route('portal.feedback.create', ['project' => $project['ulid']])"
                        size="sm"
                        variant="secondary"
                        icon="chat-bubble"
                    >{{ __('Add yours') }}</x-ui.button>
                </x-slot:actions>

                @if ($feedback->isEmpty())
                    <x-ui.empty-state
                        compact
                        icon="chat-bubble"
                        :title="__('No comments published yet')"
                        :description="__('Comments appear here once the monitoring team has reviewed them. Nothing is published automatically.')"
                    >
                        <x-slot:actions>
                            <x-ui.button
                                :href="route('portal.feedback.create', ['project' => $project['ulid']])"
                                variant="secondary"
                                icon="chat-bubble"
                            >{{ __('Be the first to comment') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($feedback as $comment)
                            <li class="px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ $comment->subject }}</h3>
                                    <p class="text-xs text-ink-muted">
                                        {{ $comment->submitterLabel() }}
                                        @if ($comment->created_at)
                                            · {{ $comment->created_at->translatedFormat('j M Y') }}
                                        @endif
                                    </p>
                                </div>

                                <p class="mt-2 text-sm leading-6 whitespace-pre-line text-ink-muted">{{ $comment->body }}</p>

                                @foreach ($comment->publicResponses as $response)
                                    <div class="mt-3 rounded-lg border border-line bg-surface-sunken p-3">
                                        <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-ink">
                                            <x-ui.icon name="shield-check" class="size-3.5" />
                                            {{ __('Official response') }}
                                            <span class="font-normal text-ink-muted">
                                                · {{ $response->responded_at->translatedFormat('j M Y') }}
                                            </span>
                                        </p>
                                        <p class="mt-1.5 text-sm leading-6 whitespace-pre-line text-ink">{{ $response->body }}</p>
                                    </div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        {{-- Facts --}}
        <div class="space-y-6">
            <x-ui.card :title="__('Delivery')">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Physical progress') }}</dt>
                        <dd class="mt-1.5">
                            <x-ui.progress :value="$project['physical_progress']" :label="__('Physical progress')" />
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Financial progress') }}</dt>
                        <dd class="mt-1.5">
                            <x-ui.progress :value="$project['financial_progress']" :label="__('Financial progress')" />
                        </dd>
                    </div>

                    @foreach ($dates as $date)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-ink-muted">{{ $date['label'] }}</dt>
                            <dd class="text-ink">{{ \Illuminate\Support\Carbon::parse($date['value'])->translatedFormat('j M Y') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card :title="__('Money')">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Contract value') }}</dt>
                        <dd class="font-semibold text-ink tabular-nums">{{ $project['contract_value_formatted'] ?? __('Not awarded') }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-ink-muted">{{ __('Paid to date') }}</dt>
                        <dd class="text-ink tabular-nums">{{ $project['expenditure_formatted'] }}</dd>
                    </div>
                </dl>

                <p class="mt-4 flex items-start gap-1.5 text-xs text-ink-muted">
                    <x-ui.icon name="information-circle" class="mt-0.5 size-3.5 shrink-0" />
                    {{ __('Contract value is the awarded sum, which is public record. Budget appropriation lines are not published on this portal.') }}
                </p>
            </x-ui.card>

            <x-ui.card :title="__('Who is responsible')">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Delivering entity') }}</dt>
                        <dd class="mt-0.5 text-ink">{{ $project['entity'] ?? __('Not recorded') }}</dd>
                    </div>
                    @if ($project['supervising_agency'])
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Supervising agency') }}</dt>
                            <dd class="mt-0.5 text-ink">{{ $project['supervising_agency'] }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Contractor') }}</dt>
                        <dd class="mt-0.5 text-ink">{{ $project['contractor'] ?? __('Not recorded') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Sector') }}</dt>
                        <dd class="mt-0.5 text-ink">{{ $project['sector'] ?? __('Not recorded') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Type') }}</dt>
                        <dd class="mt-0.5 text-ink">{{ $project['type'] }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>
    </div>
@endsection
