{{--
    Notice to commence (App\Actions\Lifecycle\IssueCommencementNotice).

    WHITE-LABEL, ABSOLUTELY. Nothing in this file names a state, a ministry, an
    agency or this product. The issuing entity is $brand['entity'] — the tenant
    record's own name — and the footer credit is $brand['instance'] from
    config/platform.php. A constant here would be printed, signed and served on
    a contractor before anyone noticed it belonged to a different client.

    COLOURS: greyscale by name (black / dimgray / gainsboro), never a hex brand
    colour. This is a printed instrument that goes onto the entity's own
    letterhead paper — inventing a brand palette for it in code would both
    violate the design-token rule and look wrong on every client's stationery.
    dompdf also has no access to the app's CSS custom properties.
--}}
@php
    use App\Support\InstanceTime;

    $date = fn ($value) => $value === null ? '—' : InstanceTime::local($value)->translatedFormat('j F Y');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Notice to commence') }} — {{ $notice->reference }}</title>
    <style>
        @page { margin: 26mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: black; line-height: 1.5; }
        .entity { font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4pt; }
        .rule { border-bottom: 1.5pt solid black; margin: 6pt 0 14pt; }
        h1 { font-size: 12pt; text-transform: uppercase; letter-spacing: 0.6pt; margin: 0 0 2pt; }
        .meta { font-size: 9pt; color: dimgray; }
        table.facts { width: 100%; border-collapse: collapse; margin: 12pt 0; }
        table.facts th, table.facts td { border: 0.5pt solid gainsboro; padding: 5pt 7pt; text-align: left; vertical-align: top; }
        table.facts th { width: 34%; font-weight: normal; color: dimgray; }
        .scope { border: 0.5pt solid gainsboro; padding: 7pt; margin-bottom: 12pt; }
        .label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: dimgray; margin-bottom: 3pt; }
        .sign { margin-top: 26pt; }
        .sign .line { border-top: 0.5pt solid black; width: 62mm; margin-top: 26pt; padding-top: 3pt; }
        .foot { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8pt; color: dimgray; text-align: center; }
        .late { border: 0.5pt solid black; padding: 4pt 7pt; font-size: 9pt; margin-bottom: 10pt; }
    </style>
</head>
<body>
    <div class="entity">{{ $brand['entity'] ?? $notice->supervising_agency_name }}</div>
    <div class="meta">{{ __('Supervising agency') }}: {{ $notice->supervising_agency_name ?? '—' }}</div>
    <div class="rule"></div>

    <h1>{{ __('Notice to commence') }}</h1>
    <div class="meta">
        {{ __('Notice number') }}: {{ $notice->reference }}
        &nbsp;·&nbsp; {{ __('Issued') }}: {{ $date($notice->issued_at) }}
    </div>

    @if ($notice->issued_late)
        <p class="late">
            {{ __('Recorded as served after the statutory window, which closed on :date.', ['date' => $date($notice->due_at)]) }}
        </p>
    @endif

    <p>{{ __('To: :contractor', ['contractor' => $contractor->name]) }}@if ($contractor->rc_number), {{ __('RC :number', ['number' => $contractor->rc_number]) }}@endif.</p>

    <p>
        {{ __('You are hereby instructed to commence the works described below, under the contract referenced, on the terms recorded in this notice. Monitoring of this project begins from the date of service of this notice.') }}
    </p>

    <table class="facts">
        <tr>
            <th>{{ __('Project') }}</th>
            <td>{{ $project->title }} ({{ $project->reference }})</td>
        </tr>
        <tr>
            <th>{{ __('Contract number') }}</th>
            <td>{{ $contract->contract_number }}</td>
        </tr>
        <tr>
            <th>{{ __('Contract sum') }}</th>
            <td>{{ $notice->contract_sum->format($brand['currency']) }}</td>
        </tr>
        <tr>
            <th>{{ __('Date of award') }}</th>
            <td>{{ $date($contract->award_date) }}</td>
        </tr>
        <tr>
            <th>{{ __('Commencement date') }}</th>
            <td>{{ $date($notice->commencement_date) }}</td>
        </tr>
        <tr>
            <th>{{ __('Contract duration') }}</th>
            <td>
                @if ($notice->duration_days)
                    {{ trans_choice('{1} :count day|[2,*] :count days', $notice->duration_days, ['count' => $notice->duration_days]) }}
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <th>{{ __('Expected completion') }}</th>
            <td>{{ $date($notice->expected_completion_date) }}</td>
        </tr>
    </table>

    <div class="scope">
        <div class="label">{{ __('Scope of works') }}</div>
        {{ $notice->scope_of_works ?? __('As set out in the contract documents.') }}
    </div>

    @if ($notice->instructions)
        <div class="scope">
            <div class="label">{{ __('Further instructions') }}</div>
            {{ $notice->instructions }}
        </div>
    @endif

    <p>
        {{ __('Acknowledge receipt of this notice in writing. The works will be inspected on site, and progress returns are due on the reporting cadence set for this project.') }}
    </p>

    <div class="sign">
        <div class="line">
            {{ $issuedBy->name }}<br>
            <span class="meta">{{ __('For and on behalf of :entity', ['entity' => $brand['entity'] ?? $notice->supervising_agency_name]) }}</span>
        </div>
    </div>

    <div class="foot">
        {{ __('Generated by :instance on :date. This is a controlled record; the authoritative copy is held in the project file.', [
            'instance' => $brand['instance'],
            'date' => $date(now()),
        ]) }}
    </div>
</body>
</html>
