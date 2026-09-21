{{--
    Completion certificate (App\Actions\Lifecycle\IssueCompletionCertificate).

    WHITE-LABEL, ABSOLUTELY — see the note in commencement-notice.blade.php.
    The issuing entity is the tenant record's own name; the only fixed string
    on the page is the product credit in the footer, which comes from
    config/platform.php.

    COLOURS: greyscale by name. A completion certificate is printed on the
    entity's own stationery and is routinely photocopied into contract files;
    it has to survive a black-and-white copier, and it must not carry a brand
    palette this codebase invented.
--}}
@php
    use App\Support\InstanceTime;

    $date = fn ($value) => $value === null ? '—' : InstanceTime::local($value)->translatedFormat('j F Y');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->type->label() }} — {{ $certificate->reference }}</title>
    <style>
        @page { margin: 24mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: black; line-height: 1.55; }
        .frame { border: 1.5pt solid black; padding: 14pt 16pt; }
        .entity { font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4pt; text-align: center; }
        .kind { text-align: center; font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1pt; margin: 14pt 0 2pt; }
        .number { text-align: center; font-size: 9.5pt; color: dimgray; margin-bottom: 14pt; }
        table.facts { width: 100%; border-collapse: collapse; margin: 10pt 0 12pt; }
        table.facts th, table.facts td { border: 0.5pt solid gainsboro; padding: 5pt 7pt; text-align: left; vertical-align: top; }
        table.facts th { width: 34%; font-weight: normal; color: dimgray; }
        .label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: dimgray; margin-bottom: 3pt; }
        .narrative { border: 0.5pt solid gainsboro; padding: 7pt; margin-bottom: 12pt; }
        .sign { margin-top: 24pt; }
        .sign .line { border-top: 0.5pt solid black; width: 62mm; padding-top: 3pt; margin-top: 26pt; }
        .meta { font-size: 9pt; color: dimgray; }
        .foot { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8pt; color: dimgray; text-align: center; }
    </style>
</head>
<body>
    <div class="frame">
        <div class="entity">{{ $brand['entity'] }}</div>

        <div class="kind">{{ $certificate->type->label() }}</div>
        <div class="number">
            {{ __('Certificate number') }}: {{ $certificate->reference }}
            &nbsp;·&nbsp; {{ __('Issued') }}: {{ $date($certificate->issued_at) }}
        </div>

        <p>
            {{ __('This is to certify that the works described below have been executed and were inspected, and that they are hereby accepted on the terms set out in this certificate.') }}
        </p>

        <table class="facts">
            <tr>
                <th>{{ __('Project') }}</th>
                <td>{{ $project->title }}</td>
            </tr>
            <tr>
                <th>{{ __('Project reference') }}</th>
                <td>{{ $project->reference }}</td>
            </tr>
            <tr>
                <th>{{ __('Contract value') }}</th>
                <td>{{ $project->contract_value_total?->format($brand['currency']) ?? '—' }}</td>
            </tr>
            <tr>
                <th>{{ __('Works completed on') }}</th>
                <td>{{ $date($project->actual_end_date) }}</td>
            </tr>
            <tr>
                <th>{{ __('Final inspection') }}</th>
                <td>
                    @if ($inspection && $inspection['conducted_at'])
                        {{ __('Conducted :date', ['date' => $date(\Illuminate\Support\Carbon::parse($inspection['conducted_at']))]) }}
                    @elseif ($inspection)
                        {{ __('On record') }}
                    @else
                        {{ __('Certified on the completion attestation held in the project file.') }}
                    @endif
                </td>
            </tr>
            @if ($certificate->defects_liability_ends_on)
                <tr>
                    <th>{{ __('Defects-liability period ends') }}</th>
                    <td>{{ $date($certificate->defects_liability_ends_on) }}</td>
                </tr>
            @endif
        </table>

        @if ($certificate->narrative)
            <div class="narrative">
                <div class="label">{{ __('Remarks') }}</div>
                {{ $certificate->narrative }}
            </div>
        @endif

        @if ($certificate->type->opensDefectsLiability())
            <p>
                {{ __('The contractor remains responsible for making good any defect that appears in the works during the defects-liability period stated above.') }}
            </p>
        @else
            <p>
                {{ __('The defects-liability period has expired and the contractor’s obligations under the contract are discharged.') }}
            </p>
        @endif

        <div class="sign">
            <div class="line">
                {{ $issuedBy->name }}<br>
                <span class="meta">{{ __('For and on behalf of :entity', ['entity' => $brand['entity']]) }}</span>
            </div>
        </div>
    </div>

    <div class="foot">
        {{ __('Generated by :instance on :date. Validity may be confirmed against certificate number :number in the issuing entity’s register.', [
            'instance' => $brand['instance'],
            'date' => $date(now()),
            'number' => $certificate->reference,
        ]) }}
    </div>
</body>
</html>
