<?php

namespace Database\Factories;

use App\Enums\ExportFormat;
use App\Enums\ReportDataset;
use App\Models\ConsolidatedReport;
use App\Models\ReportExport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Exporting\ExportDefinition;
use App\Support\Exporting\ReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The register row for a generated artifact. GLOBAL — the register records
 * oversight exports (which cross every MDA and belong to none) beside
 * workspace exports (which do not), so `generated_for_tenant_id` is
 * PROVENANCE and this factory sets it explicitly or not at all.
 *
 * `status`, `disk`, `path` and `row_count` are deliberately not fillable —
 * App\Support\Exporting\ReportExporter is their only writer in application
 * code. Factories run unguarded, so ->ready() can state that a file exists;
 * a test that needs the file to actually BE there fakes the disk and puts one
 * at ->path.
 *
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        $dataset = ReportDataset::Projects;
        $definition = ExportDefinition::make(
            dataset: $dataset,
            format: ExportFormat::Csv,
            title: 'State portfolio',
            columns: $dataset->defaultColumns(),
            filters: ['status' => 'in_progress'],
        );

        return [
            'generated_for_tenant_id' => null,
            'dataset' => $dataset,
            'format' => ExportFormat::Csv,
            'surface' => 'oversight',
            'title' => $definition->title,
            // The whole definition, exactly as ReportExporter stores it: an
            // artifact forwarded six months later has to be traceable to the
            // question it answered.
            'filters' => $definition->toArray(),
            'columns' => $definition->columns,
            'group_by' => null,
            'status' => ReportExport::STATUS_PENDING,
            'row_count' => null,
            'truncated' => false,
            'error_message' => null,
            'disk' => null,
            'path' => null,
            'file_name' => $definition->fileName(),
            'mime_type' => null,
            'size_bytes' => null,
            'consolidated_report_id' => null,
            'generated_by_id' => User::factory(),
            'completed_at' => null,
            'expires_at' => CarbonImmutable::now()->addDays(ReportExporter::DEFAULT_RETENTION_DAYS),
        ];
    }

    public function dataset(ReportDataset $dataset): static
    {
        return $this->state(fn (array $attributes): array => [
            'dataset' => $dataset,
            'title' => $dataset->label(),
            'columns' => $dataset->defaultColumns(),
        ]);
    }

    public function format(ExportFormat $format): static
    {
        return $this->state(fn (array $attributes): array => [
            'format' => $format,
            'file_name' => Str::slug((string) ($attributes['title'] ?? 'report')).'.'.$format->extension(),
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(['generated_by_id' => $user->id]);
    }

    /** A workspace-scoped artifact — the case the provenance gate exists for. */
    public function generatedFor(Tenant $tenant): static
    {
        return $this->state([
            'generated_for_tenant_id' => $tenant->id,
            'surface' => 'tenant',
        ]);
    }

    public function forConsolidation(ConsolidatedReport $report): static
    {
        return $this->state(fn (array $attributes): array => [
            'consolidated_report_id' => $report->id,
            'dataset' => ReportDataset::Consolidation,
            'title' => $report->reference.' '.$report->title,
        ]);
    }

    /**
     * A finished artifact. The path is a ULID under the private disk, exactly
     * as the exporter writes it — never a user's string, and never a name two
     * exports could collide on.
     */
    public function ready(int $rows = 42): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReportExport::STATUS_READY,
            'row_count' => $rows,
            'disk' => (string) config('documents.disk', 'documents'),
            'path' => 'exports/'.CarbonImmutable::now()->format('Y/m').'/'.Str::ulid()->toString().'.'
                .($attributes['format'] instanceof ExportFormat
                    ? $attributes['format']->extension()
                    : (string) ($attributes['format'] ?? 'csv')),
            'mime_type' => $attributes['format'] instanceof ExportFormat
                ? $attributes['format']->mimeType()
                : 'text/csv',
            'size_bytes' => 2048,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'status' => ReportExport::STATUS_PENDING,
            'completed_at' => null,
            'disk' => null,
            'path' => null,
        ]);
    }

    public function failed(string $message = 'The export worker stopped before the file was written.'): static
    {
        return $this->state([
            'status' => ReportExport::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /** Past retention: the row stays, the file does not. */
    public function expired(): static
    {
        return $this->ready()->state([
            'expires_at' => CarbonImmutable::now()->subDay(),
        ]);
    }
}
