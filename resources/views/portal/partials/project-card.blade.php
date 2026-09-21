{{--
    One published project, as a card. Used by the landing page and the browser.

    $project is an ARRAY produced by App\Support\Publishing\PublicProjectPayload —
    never a Project model. Every key read here is on that whitelist, and nothing
    else is reachable from this file: there is no `->` to follow.
--}}
@php
    $site = $project['primary_location'] ?? null;
    $place = collect([$site['site_name'] ?? null, $site['lga'] ?? null])->filter()->implode(', ');
@endphp

<article class="flex h-full flex-col rounded-xl border border-line bg-surface-raised p-4 shadow-e1 transition-colors hover:border-line-strong">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <x-ui.badge :status="$project['status']" :label="$project['status_label']" size="sm" />
        <span class="font-mono text-xs text-ink-subtle">{{ $project['reference'] }}</span>
    </div>

    <h3 class="mt-3 text-base font-semibold text-ink">
        <a
            href="{{ route('portal.projects.show', ['ulid' => $project['ulid']]) }}"
            class="rounded underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >{{ $project['title'] }}</a>
    </h3>

    <p class="mt-1 text-sm text-ink-muted">
        {{ collect([$project['entity'], $project['sector']])->filter()->implode(' · ') }}
    </p>

    @if ($place !== '')
        <p class="mt-1 inline-flex items-center gap-1.5 text-sm text-ink-muted">
            <x-ui.icon name="map-pin" class="size-4 shrink-0 text-ink-subtle" />
            {{ $place }}
        </p>
    @endif

    <dl class="mt-4 space-y-2 text-sm">
        <div>
            <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">{{ __('Physical progress') }}</dt>
            <dd class="mt-1">
                <x-ui.progress :value="$project['physical_progress']" :label="__('Physical progress')" size="sm" />
            </dd>
        </div>

        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-ink-muted">{{ __('Contract value') }}</dt>
            <dd class="font-semibold text-ink tabular-nums">{{ $project['contract_value_formatted'] ?? __('Not awarded') }}</dd>
        </div>

        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-ink-muted">{{ __('Paid to date') }}</dt>
            <dd class="text-ink tabular-nums">{{ $project['expenditure_formatted'] }}</dd>
        </div>
    </dl>

    <a
        href="{{ route('portal.projects.show', ['ulid' => $project['ulid']]) }}"
        class="mt-4 inline-flex items-center gap-1 self-start rounded text-sm font-medium text-brand-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
    >
        {{ __('See the detail') }}
        <x-ui.icon name="chevron-right" class="size-4" />
        <span class="sr-only">{{ __('for :project', ['project' => $project['title']]) }}</span>
    </a>
</article>
