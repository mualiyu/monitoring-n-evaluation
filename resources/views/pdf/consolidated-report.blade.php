{{--
    A state consolidation rendered as a document — the quarterly, biannual and
    thematic roll-ups. The Annual Performance Report has its own layout
    (pdf/state-apr) because its shape is what a state is judged on.

    WHITE-LABEL: no state, ministry or product name appears here. Identity
    comes from instance config through PdfTheme.

    FROZEN FIGURES: everything printed reads through
    ConsolidatedReport::figures() / entityFigures() / narrative(), which return
    the snapshot taken at approval once the report is signed. A signed document
    that restates itself when an MDA edits an old return is not a document.

    Variables: $report, $theme, $generatedAt, $generatedBy
--}}
@php
    $money = fn (?string $value) => $value === null || $value === ''
        ? '—'
        : \App\Support\Money::fromDecimalString((string) $value)->format();
    $totals = $report->figures();
    $entities = $report->entityFigures();
    $period = $report->reportingPeriod;
    $frozen = $report->status->isFrozen();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 20mm 16mm 20mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; line-height: 1.45; color: {{ $theme->color('ink') }}; }
        .masthead { border-bottom: 1.5pt solid {{ $theme->color('brand') }}; padding-bottom: 8pt; margin-bottom: 14pt; }
        .instance { font-size: 11pt; font-weight: bold; color: {{ $theme->color('brand') }}; }
        .doc-title { font-size: 17pt; font-weight: bold; margin: 8pt 0 3pt 0; }
        .meta { font-size: 8.5pt; color: {{ $theme->color('ink_muted') }}; }
        h2 { font-size: 11.5pt; color: {{ $theme->color('brand') }}; margin: 16pt 0 5pt 0;
             border-bottom: 0.5pt solid {{ $theme->color('line') }}; padding-bottom: 3pt; }
        p { margin: 0 0 7pt 0; }
        .unwritten { color: {{ $theme->color('ink_subtle') }}; font-style: italic; }
        .kpis { width: 100%; border-collapse: collapse; margin: 10pt 0; }
        .kpis td { width: 25%; padding: 7pt 8pt; border: 0.5pt solid {{ $theme->color('line') }};
                   background-color: {{ $theme->color('surface_sunken') }}; }
        .kpi-label { font-size: 7.5pt; color: {{ $theme->color('ink_muted') }}; text-transform: uppercase; }
        .kpi-value { font-size: 13pt; font-weight: bold; }
        table.figures { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 6pt; }
        table.figures thead { display: table-header-group; }
        table.figures th { background-color: {{ $theme->color('brand_soft') }}; text-align: left;
                           padding: 4pt 5pt; border-bottom: 1pt solid {{ $theme->color('line') }}; }
        table.figures td { padding: 3.5pt 5pt; border-bottom: 0.5pt solid {{ $theme->color('line') }}; }
        .num { text-align: right; }
        .provenance { margin-top: 16pt; padding: 7pt 9pt; font-size: 8pt;
                      background-color: {{ $theme->color('brand_soft') }}; }
        .draft-mark { margin-bottom: 10pt; padding: 6pt 8pt; font-size: 8.5pt; font-weight: bold;
                      color: {{ $theme->color('critical') }}; border: 1pt solid {{ $theme->color('critical') }}; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt;
                  color: {{ $theme->color('ink_subtle') }};
                  border-top: 0.5pt solid {{ $theme->color('line') }}; padding-top: 4pt; }
    </style>
</head>
<body>
    <div class="footer">
        {{ $report->reference }} · {{ $theme->instanceShortName }} ·
        {{ __('Generated :date', ['date' => $generatedAt->format('j M Y, H:i')]) }}
    </div>

    <div class="masthead">
        <div class="instance">{{ $theme->instanceName }}</div>
        <div class="doc-title">{{ $report->title }}</div>
        <div class="meta">
            {{ $report->type->label() }} · {{ $period->label }} ·
            {{ __('Reference :reference', ['reference' => $report->reference]) }}
        </div>
    </div>

    @unless ($frozen)
        {{-- Status is icon-free here but never colour alone: the word DRAFT
             carries it, because a board pack gets photocopied in greyscale. --}}
        <div class="draft-mark">
            {{ __('DRAFT — :status. These figures are not final and may still change.', [
                'status' => $report->status->label(),
            ]) }}
        </div>
    @endunless

    <table class="kpis">
        <tr>
            <td>
                <div class="kpi-label">{{ __('Entities reporting') }}</div>
                <div class="kpi-value">{{ number_format($report->entity_count) }}</div>
                <div class="meta">{{ __('of :total', ['total' => number_format($report->denominator)]) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('Projects') }}</div>
                <div class="kpi-value">{{ number_format((int) ($totals['projects_total'] ?? 0)) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('Contract sum') }}</div>
                <div class="kpi-value">{{ $money($totals['contract_value_total'] ?? null) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('Returns filed') }}</div>
                <div class="kpi-value">{{ number_format((int) ($totals['obligations_submitted'] ?? 0)) }}</div>
                <div class="meta">{{ __('of :total expected', ['total' => number_format((int) ($totals['obligations_expected'] ?? 0))]) }}</div>
            </td>
        </tr>
    </table>

    @foreach ($report->narrative() as $section)
        <h2>{{ $section['heading'] }}</h2>
        @if (filled($section['body']))
            @foreach (preg_split('/\R{2,}/', (string) $section['body']) ?: [] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        @else
            {{-- An empty chapter is stated, not hidden: the omission is the
                 finding a reader is entitled to see. --}}
            <p class="unwritten">{{ __('Not written.') }}</p>
        @endif
    @endforeach

    <h2>{{ __('Figures by entity') }}</h2>

    @if ($entities === [])
        <p class="unwritten">{{ __('No entity figures were compiled for this window.') }}</p>
    @else
        <table class="figures">
            <thead>
                <tr>
                    <th>{{ __('Entity') }}</th>
                    <th class="num">{{ __('Projects') }}</th>
                    <th class="num">{{ __('Contract sum') }}</th>
                    <th class="num">{{ __('Expenditure') }}</th>
                    <th class="num">{{ __('Expected') }}</th>
                    <th class="num">{{ __('Filed') }}</th>
                    <th class="num">{{ __('On-time %') }}</th>
                    <th class="num">{{ __('Indicators on track') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entities as $entity)
                    <tr>
                        <td>{{ $entity['entity'] ?? __('Unknown entity') }}</td>
                        <td class="num">{{ number_format((int) $entity['projects_total']) }}</td>
                        <td class="num">{{ $money($entity['contract_value_total'] ?? null) }}</td>
                        <td class="num">{{ $money($entity['expenditure_total'] ?? null) }}</td>
                        <td class="num">{{ number_format((int) $entity['obligations_expected']) }}</td>
                        <td class="num">{{ number_format((int) $entity['obligations_submitted']) }}</td>
                        <td class="num">{{ $entity['on_time_rate'] === null ? '—' : $entity['on_time_rate'].'%' }}</td>
                        <td class="num">
                            {{ number_format((int) $entity['indicators_on_track']) }}
                            / {{ number_format((int) $entity['indicators_reported']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="provenance">
        <strong>{{ __('Provenance') }}</strong><br>
        {{ __('Window: :label (:start – :end)', [
            'label' => $period->label,
            'start' => $period->period_start->format('j M Y'),
            'end' => $period->period_end->format('j M Y'),
        ]) }}<br>
        {{ __('Figures compiled :date', ['date' => $report->compiled_at?->format('j M Y, H:i') ?? '—']) }}<br>
        @if ($frozen)
            {{ __('Frozen at approval :date — these figures cannot change.', [
                'date' => $report->snapshot_taken_at?->format('j M Y, H:i') ?? '—',
            ]) }}
        @else
            {{ __('Not yet approved — figures are live and will move as entities file.') }}
        @endif
    </div>
</body>
</html>
