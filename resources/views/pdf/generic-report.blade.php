{{--
    Ad-hoc report builder output (App\Support\Exporting\ReportExporter).

    WHITE-LABEL: no state, ministry or product name appears here. The identity
    line comes from instance config through App\Support\Exporting\PdfTheme, so
    a new client is a configuration change rather than a template fork.

    COLOURS: dompdf resolves neither CSS custom properties nor OKLCH, so the
    app's token system cannot reach a PDF. The design rule that matters —
    never type a raw hex colour in a Blade file — is kept by reading every
    value from $theme, which is overridable per deployment.

    Variables: $definition, $theme, $headings, $rows, $truncated, $rowLimit,
               $generatedAt, $generatedBy
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $definition->title }}</title>
    <style>
        @page { margin: 18mm 14mm 20mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: {{ $theme->color('ink') }}; }
        .masthead { border-bottom: 1.5pt solid {{ $theme->color('brand') }}; padding-bottom: 6pt; margin-bottom: 10pt; }
        .instance { font-size: 11pt; font-weight: bold; color: {{ $theme->color('brand') }}; }
        .doc-title { font-size: 15pt; font-weight: bold; margin: 6pt 0 2pt 0; }
        .meta { font-size: 8pt; color: {{ $theme->color('ink_muted') }}; }
        .filters { margin: 8pt 0 10pt 0; padding: 6pt 8pt; background-color: {{ $theme->color('brand_soft') }}; }
        .filters dt { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background-color: {{ $theme->color('brand_soft') }}; color: {{ $theme->color('ink') }};
             text-align: left; font-size: 8pt; padding: 4pt 5pt; border-bottom: 1pt solid {{ $theme->color('line') }}; }
        td { padding: 3.5pt 5pt; border-bottom: 0.5pt solid {{ $theme->color('line') }}; vertical-align: top; }
        .num { text-align: right; }
        .notice { margin-top: 10pt; padding: 6pt 8pt; font-size: 8pt;
                  color: {{ $theme->color('warning') }}; border: 0.75pt solid {{ $theme->color('warning') }}; }
        .empty { padding: 20pt; text-align: center; color: {{ $theme->color('ink_muted') }}; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0;
                  font-size: 7.5pt; color: {{ $theme->color('ink_subtle') }};
                  border-top: 0.5pt solid {{ $theme->color('line') }}; padding-top: 4pt; }
    </style>
</head>
<body>
    <div class="footer">
        {{ $theme->instanceShortName }} · {{ __('Generated :date by :user', [
            'date' => $generatedAt->format('j M Y, H:i'),
            'user' => $generatedBy,
        ]) }}
    </div>

    <div class="masthead">
        <div class="instance">{{ $theme->instanceName }}</div>
        <div class="doc-title">{{ $definition->title }}</div>
        <div class="meta">
            {{ $definition->dataset->label() }} · {{ $definition->dataset->description() }}
        </div>
    </div>

    @if ($definition->describedFilters() !== [])
        <div class="filters">
            <dl>
                @foreach ($definition->describedFilters() as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        </div>
    @endif

    @if ($rows === [])
        {{-- Designed empty state, in print too: a blank page invites the
             reader to assume the export broke. --}}
        <div class="empty">
            {{ __('No records matched the filters above. The question was asked and the answer is none.') }}
        </div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $index => $heading)
                        <th @class(['num' => $definition->isNumeric($definition->columns[$index] ?? '')])>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $index => $cell)
                            <td @class(['num' => $definition->isNumeric($definition->columns[$index] ?? '')])>{{ $cell ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="meta" style="margin-top: 8pt;">
            {{ trans_choice('{1} :count record|[2,*] :count records', count($rows), ['count' => number_format(count($rows))]) }}
        </p>
    @endif

    @if ($truncated)
        {{-- Truncation is stated, never silent: a reader must not take a
             partial table for the whole answer. --}}
        <div class="notice">
            {{ __('This document shows the first :limit rows. Export the same selection as a spreadsheet for the complete set.', [
                'limit' => number_format($rowLimit),
            ]) }}
        </div>
    @endif
</body>
</html>
