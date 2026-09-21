{{--
    THE ANNUAL PERFORMANCE REPORT (manual digest §4: each MDA files its annual
    M&E report within Q1 of the following year; the Secretariat consolidates
    them into ONE state report against the predetermined indicator list).

    This is the artifact the state is judged on, so it is laid out as a
    document rather than a dashboard print: cover, contents, chapters in the
    order the skeleton fixes, then the per-entity annex.

    ⚠ WHITE-LABEL. No state, ministry, agency or product name is typed in this
    file. Every identifying string comes from instance configuration through
    App\Support\Exporting\PdfTheme. Grep this template for a place name and you
    should find none — if you ever add one, you have broken the product.

    FROZEN FIGURES: everything reads through the snapshot accessors, which
    return the figures as they stood at approval. An APR that silently changes
    when an MDA edits last quarter's return is not an APR.

    Variables: $report, $theme, $generatedAt, $generatedBy
--}}
@php
    $money = fn (?string $value) => $value === null || $value === ''
        ? '—'
        : \App\Support\Money::fromDecimalString((string) $value)->format();
    $totals = $report->figures();
    $entities = $report->entityFigures();
    $narrative = $report->narrative();
    $period = $report->reportingPeriod;
    $frozen = $report->status->isFrozen();
    $expected = (int) ($totals['obligations_expected'] ?? 0);
    $filed = (int) ($totals['obligations_submitted'] ?? 0);
    $onTime = (int) ($totals['on_time'] ?? $totals['obligations_on_time'] ?? 0);
    $waived = (int) ($totals['obligations_waived'] ?? 0);
    $scored = $expected - $waived;
    $onTimeRate = $scored > 0 ? round($onTime * 100 / $scored, 1) : null;
    $reported = (int) ($totals['indicators_reported'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 22mm 18mm 20mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.5; color: {{ $theme->color('ink') }}; }

        .cover { height: 235mm; }
        .cover-rule { height: 4pt; background-color: {{ $theme->color('brand') }}; margin-bottom: 22pt; }
        .cover-instance { font-size: 13pt; font-weight: bold; color: {{ $theme->color('brand') }}; letter-spacing: 0.5pt; }
        .cover-kicker { margin-top: 90pt; font-size: 9pt; text-transform: uppercase;
                        letter-spacing: 1.5pt; color: {{ $theme->color('ink_muted') }}; }
        .cover-title { font-size: 30pt; font-weight: bold; line-height: 1.15; margin: 8pt 0 14pt 0; }
        .cover-period { font-size: 14pt; color: {{ $theme->color('ink_muted') }}; }
        .cover-foot { position: absolute; bottom: 0; font-size: 8.5pt; color: {{ $theme->color('ink_muted') }}; }

        h2 { font-size: 13pt; color: {{ $theme->color('brand') }}; margin: 18pt 0 6pt 0;
             border-bottom: 0.75pt solid {{ $theme->color('line') }}; padding-bottom: 4pt; }
        h3 { font-size: 10.5pt; margin: 12pt 0 4pt 0; }
        p { margin: 0 0 8pt 0; }
        .unwritten { color: {{ $theme->color('ink_subtle') }}; font-style: italic; }
        .meta { font-size: 8.5pt; color: {{ $theme->color('ink_muted') }}; }

        ol.contents { font-size: 10pt; margin: 0; padding-left: 16pt; }
        ol.contents li { margin-bottom: 3pt; }

        .kpis { width: 100%; border-collapse: collapse; margin: 12pt 0; }
        .kpis td { width: 25%; padding: 8pt; border: 0.5pt solid {{ $theme->color('line') }};
                   background-color: {{ $theme->color('surface_sunken') }}; }
        .kpi-label { font-size: 7.5pt; text-transform: uppercase; color: {{ $theme->color('ink_muted') }}; }
        .kpi-value { font-size: 15pt; font-weight: bold; }

        table.figures { width: 100%; border-collapse: collapse; font-size: 8pt; }
        table.figures thead { display: table-header-group; }
        table.figures th { background-color: {{ $theme->color('brand_soft') }}; text-align: left;
                           padding: 4pt 5pt; border-bottom: 1pt solid {{ $theme->color('line') }}; }
        table.figures td { padding: 3.5pt 5pt; border-bottom: 0.5pt solid {{ $theme->color('line') }}; }
        .num { text-align: right; }

        .draft-mark { margin: 0 0 12pt 0; padding: 7pt 9pt; font-weight: bold; font-size: 9pt;
                      color: {{ $theme->color('critical') }}; border: 1pt solid {{ $theme->color('critical') }}; }
        .provenance { margin-top: 18pt; padding: 8pt 10pt; font-size: 8.5pt;
                      background-color: {{ $theme->color('brand_soft') }}; }
        .page-break { page-break-after: always; }
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

    {{-- ---------------------------------------------------------------- --}}
    {{-- Cover                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="cover">
        <div class="cover-rule"></div>
        <div class="cover-instance">{{ $theme->instanceName }}</div>

        <div class="cover-kicker">{{ $report->type->label() }}</div>
        <div class="cover-title">{{ $report->title }}</div>
        <div class="cover-period">{{ $period->label }}</div>

        <div class="cover-foot">
            {{ __('Reference :reference', ['reference' => $report->reference]) }}<br>
            @if ($frozen)
                {{ __('Approved :date', ['date' => $report->approved_at?->format('j M Y') ?? '—']) }}
                @if ($report->published_at)
                    · {{ __('Published :date', ['date' => $report->published_at->format('j M Y')]) }}
                @endif
            @else
                {{ __('Working draft — :status', ['status' => $report->status->label()]) }}
            @endif
        </div>
    </div>
    <div class="page-break"></div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Contents + headline figures                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    @unless ($frozen)
        <div class="draft-mark">
            {{ __('DRAFT — :status. These figures are not final and may still change.', [
                'status' => $report->status->label(),
            ]) }}
        </div>
    @endunless

    <h2>{{ __('Contents') }}</h2>
    <ol class="contents">
        @foreach ($narrative as $section)
            <li>{{ $section['heading'] }}</li>
        @endforeach
        <li>{{ __('Annex: figures by entity') }}</li>
    </ol>

    <h2>{{ __('The year in figures') }}</h2>
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
                <div class="meta">{{ $money($totals['contract_value_total'] ?? null) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('Returns filed on time') }}</div>
                <div class="kpi-value">{{ $onTimeRate === null ? '—' : $onTimeRate.'%' }}</div>
                <div class="meta">{{ __(':filed of :expected filed', [
                    'filed' => number_format($filed),
                    'expected' => number_format($expected),
                ]) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('Indicators on track') }}</div>
                <div class="kpi-value">{{ number_format((int) ($totals['indicators_on_track'] ?? 0)) }}</div>
                <div class="meta">{{ __('of :total reported', ['total' => number_format($reported)]) }}</div>
            </td>
        </tr>
    </table>

    <p class="meta">
        {{ __('Portfolio figures state the register as at compilation; compliance, returns and indicator readings cover the window itself.') }}
    </p>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Chapters, in the order the skeleton fixes                         --}}
    {{-- ---------------------------------------------------------------- --}}
    @foreach ($narrative as $section)
        <h2>{{ $section['heading'] }}</h2>
        @if (filled($section['body']))
            @foreach (preg_split('/\R{2,}/', (string) $section['body']) ?: [] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        @else
            <p class="unwritten">{{ __('Not written.') }}</p>
        @endif
    @endforeach

    {{-- ---------------------------------------------------------------- --}}
    {{-- Annex                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="page-break"></div>
    <h2>{{ __('Annex: figures by entity') }}</h2>

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
                    <th class="num">{{ __('Avg progress') }}</th>
                    <th class="num">{{ __('Expected') }}</th>
                    <th class="num">{{ __('Filed') }}</th>
                    <th class="num">{{ __('On-time %') }}</th>
                    <th class="num">{{ __('On track') }}</th>
                    <th class="num">{{ __('At risk') }}</th>
                    <th class="num">{{ __('Off track') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entities as $entity)
                    <tr>
                        <td>{{ $entity['entity'] ?? __('Unknown entity') }}</td>
                        <td class="num">{{ number_format((int) $entity['projects_total']) }}</td>
                        <td class="num">{{ $money($entity['contract_value_total'] ?? null) }}</td>
                        <td class="num">{{ $money($entity['expenditure_total'] ?? null) }}</td>
                        <td class="num">{{ $entity['physical_progress_avg'] === null ? '—' : $entity['physical_progress_avg'].'%' }}</td>
                        <td class="num">{{ number_format((int) $entity['obligations_expected']) }}</td>
                        <td class="num">{{ number_format((int) $entity['obligations_submitted']) }}</td>
                        <td class="num">{{ $entity['on_time_rate'] === null ? '—' : $entity['on_time_rate'].'%' }}</td>
                        <td class="num">{{ number_format((int) $entity['indicators_on_track']) }}</td>
                        <td class="num">{{ number_format((int) $entity['indicators_at_risk']) }}</td>
                        <td class="num">{{ number_format((int) $entity['indicators_off_track']) }}</td>
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
            {{ __('Frozen at approval :date — these figures cannot change. A correction is issued as the next report, never as an edit to this one.', [
                'date' => $report->snapshot_taken_at?->format('j M Y, H:i') ?? '—',
            ]) }}
        @else
            {{ __('Not yet approved — figures are live and will move as entities file.') }}
        @endif
    </div>
</body>
</html>
