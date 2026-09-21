{{--
    <x-ui.chart /> — the platform's only chart.

    ONE highlight colour, neutral context, no 3D, no gradients (design system
    §Dashboards). It is deliberately single-series: a government dashboard's job
    is "which of these is biggest and which needs attention", and eight hues
    competing for attention answers neither. A second measure gets a second
    chart, never a second y-axis — the alignment of two scales is arbitrary and
    invents a correlation that is not in the data.

    Props
      type        bar (horizontal, for long category names) | column | line | area
      title       names what is plotted; with one series this replaces a legend
      description optional sub-line
      rows        [['label' => 'In progress', 'value' => 12, 'href' => '/…', 'highlight' => true], …]
      format      number | percent | money   (money values are pre-formatted strings in `display`)
      height      plot height in px
      empty       message shown instead of a plot when every value is zero

    Every chart ships a TABLE VIEW of the same numbers. That is not a nicety:
    it is the relief channel that makes the neutral context steps legal (they
    sit at 2.7:1 on the light surface, below the 3:1 mark threshold), it is what
    a screen reader reads, and it is what renders when JavaScript does not.

        <x-ui.chart
            :title="__('Register by status')"
            :rows="$rows"
            :href="route('tenant.projects.index')"
        />
--}}
@props([
    'type' => 'bar',
    'title' => null,
    'description' => null,
    'rows' => [],
    'format' => 'number',
    'height' => 280,
    'empty' => null,
])

@php
    $rows = collect($rows)
        ->map(fn ($row) => [
            'label' => (string) ($row['label'] ?? ''),
            'value' => (float) ($row['value'] ?? 0),
            'display' => $row['display'] ?? null,
            'highlight' => (bool) ($row['highlight'] ?? false),
        ])
        ->values();

    $hasData = $rows->sum('value') > 0;
    $chartId = 'chart-'.\Illuminate\Support\Str::random(8);
    $tableId = $chartId.'-table';
@endphp

<figure {{ $attributes->class('min-w-0') }}>
    @if ($title)
        <figcaption class="mb-1 text-sm font-semibold text-ink">{{ $title }}</figcaption>
    @endif

    @if ($description)
        <p class="mb-3 text-xs text-ink-muted">{{ $description }}</p>
    @endif

    @if (! $hasData)
        <x-ui.empty-state
            compact
            icon="chart-bar"
            :title="$empty ?? __('Nothing to plot yet')"
            :description="__('Figures appear here as records are registered.')"
        />
    @else
        {{--
            The plot is progressive enhancement over the table below: without
            JavaScript the <div> stays empty and the table is already open.
        --}}
        <div
            wire:ignore
            x-data="uiChart(@js([
                'type' => $type,
                'height' => (int) $height,
                'format' => $format,
                'labels' => $rows->pluck('label')->all(),
                'values' => $rows->pluck('value')->all(),
                'displays' => $rows->map(fn ($row) => $row['display'] ?? null)->all(),
                'highlights' => $rows->pluck('highlight')->all(),
                'title' => (string) $title,
            ]))"
            x-init="render()"
            class="min-w-0"
        >
            <div x-ref="plot" role="img" aria-describedby="{{ $tableId }}" aria-label="{{ $title }}"></div>
        </div>

        <details class="group mt-2" id="{{ $tableId }}">
            <summary class="cursor-pointer list-none text-xs font-medium text-ink-muted underline-offset-2 hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                <span class="group-open:hidden">{{ __('Show the figures as a table') }}</span>
                <span class="hidden group-open:inline">{{ __('Hide the table') }}</span>
            </summary>

            <table class="mt-2 w-full text-sm">
                <caption class="sr-only">{{ $title }}</caption>
                <thead>
                    <tr class="border-b border-line text-left text-xs font-semibold text-ink-muted">
                        <th scope="col" class="py-1.5 pr-3">{{ __('Category') }}</th>
                        <th scope="col" class="py-1.5 text-right">{{ __('Value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-line/60 last:border-0">
                            <th scope="row" class="py-1.5 pr-3 font-normal text-ink">{{ $row['label'] }}</th>
                            <td class="py-1.5 text-right tabular-nums text-ink">
                                {{ $row['display'] ?? number_format($row['value']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</figure>
