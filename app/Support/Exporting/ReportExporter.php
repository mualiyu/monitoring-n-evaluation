<?php

namespace App\Support\Exporting;

use App\Enums\ExportFormat;
use App\Enums\ReportDataset;
use App\Exports\DatasetExport;
use App\Jobs\Consolidation\GenerateReportExport;
use App\Models\ConsolidatedReport;
use App\Models\ReportExport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * THE export layer. Hand it a definition — a dataset, a column map, filters
 * and a format — and it produces the artifact, stores it privately, and
 * records who took what.
 *
 * Four properties this class exists to guarantee, none of which a per-screen
 * `response()->streamDownload()` can give you:
 *
 *  1. EVERY EXPORT IS REGISTERED. A report_exports row is written before a
 *     byte is generated and completed afterwards — including on failure. An
 *     export that nobody can trace is data leaving a government platform
 *     unobserved, and the register is the observation.
 *
 *  2. NOTHING IS GENERATED ON THE WEB REQUEST IF IT IS BIG. Past
 *     INLINE_ROW_LIMIT rows the work goes to a queue (plan §6 — "queue heavy
 *     exports"), the caller gets a pending register row, and the finished file
 *     appears in the register for download.
 *
 *  3. THE FILE NEVER TOUCHES A PUBLIC DISK. It lands on the private
 *     `documents` disk under a ULID filename — no user-controlled name on
 *     disk — and leaves only through the signed, policy-checked download route
 *     (rules/security.md §Uploads), exactly as the document vault does.
 *
 *  4. ONE WRITER PER FORMAT. CSV and XLSX both go through maatwebsite/excel so
 *     a column cannot exist in one and not the other; PDF goes through dompdf
 *     and a Blade template because a PDF is a laid-out document, not a grid.
 */
class ReportExporter
{
    /**
     * Rows above which generation moves to a queue. A number chosen for
     * request latency, not for the domain — hence a constant here rather than
     * an entry in the settings chain, which is reserved for policy decisions a
     * state takes differently.
     */
    public const INLINE_ROW_LIMIT = 2000;

    /**
     * Rows a PDF will print before it is truncated with a note. A 40,000-row
     * PDF is not a document anybody reads; it is a way to make dompdf run out
     * of memory. The spreadsheet formats carry the full set.
     */
    public const PDF_ROW_LIMIT = 2000;

    /** Days a generated artifact stays downloadable before retention prunes it. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Generate now if small, queue if large. The entry point every screen
     * uses; the caller inspects `isPending()` to decide what to tell the user.
     */
    public function run(User $actor, ExportDefinition $definition): ReportExport
    {
        $rows = app(DatasetRows::class);
        $rows->assertReadable($actor, $definition);

        $export = $this->register($actor, $definition);

        if ($rows->count($actor, $definition) > self::INLINE_ROW_LIMIT) {
            GenerateReportExport::dispatch($export->id, $actor->id);

            return $export;
        }

        return $this->generate($export, $actor);
    }

    /**
     * Open the register row. Written BEFORE any bytes exist, so a generation
     * that dies half way still leaves a record that somebody asked.
     */
    public function register(User $actor, ExportDefinition $definition): ReportExport
    {
        /** @var ReportExport $export */
        $export = ReportExport::query()->create([
            'generated_for_tenant_id' => $definition->generatedForTenantId,
            'dataset' => $definition->dataset,
            'format' => $definition->format,
            'surface' => $definition->surface,
            'title' => $definition->title,
            'filters' => $definition->toArray(),
            'columns' => $definition->columns,
            'group_by' => $definition->groupBy,
            'file_name' => $definition->fileName(),
            'consolidated_report_id' => $definition->consolidatedReportId,
            'generated_by_id' => $actor->id,
            'expires_at' => CarbonImmutable::now()->addDays($this->retentionDays()),
        ]);

        // Not fillable, so stated here rather than left to the column default:
        // the caller inspects isPending()/isReady() on the model it is handed,
        // and a NULL status until something re-reads the row would make an
        // artifact that is plainly pending answer "no" to isPending().
        $export->forceFill([
            'status' => ReportExport::STATUS_PENDING,
            'truncated' => false,
        ])->save();

        return $export;
    }

    /**
     * Produce the artifact for an already-registered request and store it.
     *
     * A failure is RECORDED, not swallowed and not merely rethrown: the
     * register row moves to `failed` with the message, so the screen can say
     * what happened instead of showing a row that is pending for ever. The
     * exception is then rethrown so a queued attempt still fails loudly.
     */
    public function generate(ReportExport $export, User $actor): ReportExport
    {
        $definition = ExportDefinition::fromArray($export->filters ?? []);

        app(DatasetRows::class)->assertReadable($actor, $definition);

        $disk = $this->disk();
        // The stored name is a ULID, never the display name: the filename on
        // disk must not be derived from anything a user typed, and two exports
        // titled the same must not collide.
        $path = sprintf(
            'exports/%s/%s.%s',
            CarbonImmutable::now()->format('Y/m'),
            $export->ulid,
            $definition->format->extension(),
        );

        try {
            [$rowCount, $truncated] = $definition->format->isSpreadsheet()
                ? $this->writeSpreadsheet($actor, $definition, $disk, $path)
                : $this->writePdf($actor, $definition, $disk, $path);
        } catch (Throwable $exception) {
            // forceFill: the outcome columns are deliberately not fillable, so
            // only this class can move them.
            $export->forceFill([
                'status' => ReportExport::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }

        $export->forceFill([
            'status' => ReportExport::STATUS_READY,
            'row_count' => $rowCount,
            'truncated' => $truncated,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $definition->format->mimeType(),
            'size_bytes' => Storage::disk($disk)->size($path),
            'completed_at' => now(),
            'error_message' => null,
        ])->save();

        return $export;
    }

    /**
     * The export of a consolidation itself: the State APR (or the general
     * consolidated-report layout) as a PDF, or its per-entity annex as a
     * spreadsheet.
     *
     * Registered in the same register, under the same audit columns, as every
     * other artifact — a signed state report is not a special case of leaving
     * the platform.
     */
    public function consolidation(
        ConsolidatedReport $report,
        User $actor,
        ExportFormat $format,
    ): ReportExport {
        $definition = ExportDefinition::make(
            dataset: ReportDataset::Consolidation,
            format: $format,
            title: $report->reference.' '.$report->title,
            columns: ReportDataset::Consolidation->defaultColumns(),
            filters: [
                'consolidation' => $report->reference,
                'period' => $report->loadMissing('reportingPeriod')->reportingPeriod->code,
                'status' => $report->status->value,
            ],
            consolidatedReportId: $report->id,
        );

        return $this->generate($this->register($actor, $definition), $actor);
    }

    /**
     * A signed, short-lived URL for the register's download button — the same
     * mechanism the document vault uses, because the same rule applies: a link
     * pasted into a group chat must expire.
     */
    public function downloadUrl(ReportExport $export): string
    {
        return URL::signedRoute(
            'oversight.exports.download',
            ['reportExport' => $export->ulid],
            CarbonImmutable::now()->addMinutes((int) config('documents.signed_url_minutes', 15)),
        );
    }

    /**
     * @return array{0: int, 1: bool} row count, truncated
     */
    private function writeSpreadsheet(User $actor, ExportDefinition $definition, string $disk, string $path): array
    {
        $sheet = new DatasetExport($actor, $definition);

        Excel::store($sheet, $path, $disk, $definition->format->writerType());

        return [$sheet->rowCount(), $sheet->wasTruncated()];
    }

    /**
     * @return array{0: int, 1: bool} row count, truncated
     */
    private function writePdf(User $actor, ExportDefinition $definition, string $disk, string $path): array
    {
        $rows = app(DatasetRows::class);
        $collected = [];
        $truncated = false;

        $rows->chunk($actor, $definition, function (array $chunk) use ($rows, $definition, &$collected, &$truncated): bool {
            foreach ($chunk as $row) {
                if (count($collected) >= self::PDF_ROW_LIMIT) {
                    $truncated = true;

                    return false;
                }

                $collected[] = $rows->values($definition, $row);
            }

            return true;
        });

        $report = $definition->consolidatedReportId === null
            ? null
            : ConsolidatedReport::query()->whereKey($definition->consolidatedReportId)->first();

        $pdf = Pdf::loadView($this->template($report), [
            'definition' => $definition,
            'theme' => PdfTheme::for($this->tenantFor($definition)),
            'headings' => $definition->headings(),
            'rows' => $collected,
            'truncated' => $truncated,
            'rowLimit' => self::PDF_ROW_LIMIT,
            'report' => $report,
            'generatedAt' => CarbonImmutable::now(),
            'generatedBy' => $actor->name,
        ])->setPaper('a4', $report !== null ? 'portrait' : 'landscape');

        Storage::disk($disk)->put($path, $pdf->output());

        return [count($collected), $truncated];
    }

    /**
     * Which layout. The Annual Performance Report has a shape a state is
     * judged on (manual digest §4) and gets its own template; every other
     * consolidation gets the general one; everything else is a table.
     */
    private function template(?ConsolidatedReport $report): string
    {
        if ($report === null) {
            return 'pdf.generic-report';
        }

        return $report->type->usesAnnualTemplate() ? 'pdf.state-apr' : 'pdf.consolidated-report';
    }

    private function tenantFor(ExportDefinition $definition): ?Tenant
    {
        return $definition->generatedForTenantId === null
            ? null
            : Tenant::query()->whereKey($definition->generatedForTenantId)->first();
    }

    private function disk(): string
    {
        return (string) config('documents.disk', 'documents');
    }

    /**
     * Retention is a policy decision a state takes for itself, so it comes
     * through the settings chain (tenant override → instance setting → config
     * default) rather than as a literal.
     */
    private function retentionDays(): int
    {
        return app(SettingsRepository::class)->int('exports', 'retention_days', self::DEFAULT_RETENTION_DAYS);
    }
}
