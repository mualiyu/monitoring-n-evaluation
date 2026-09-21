<?php

namespace App\Support\Exporting;

use App\Enums\ExportFormat;
use App\Enums\ReportDataset;

/**
 * "What was asked for": a dataset, a format, a column selection, a filter set
 * and an optional grouping — the whole question an export answers, in one
 * immutable value.
 *
 * DELIBERATELY SCALAR ALL THE WAY DOWN. Filters are slugs, ids and strings,
 * never Eloquent models, for two reasons that are really one: the definition
 * is serialized onto a queue for a big export, and it is stored verbatim in
 * `report_exports.filters` so that a spreadsheet forwarded to a Commissioner
 * six months later can be traced back to the question it answered. "3,218
 * projects" means nothing without the filter that produced it.
 *
 * DatasetRows is the only thing that turns these scalars back into models, and
 * it does so through the same Actions the dashboards use.
 */
final readonly class ExportDefinition
{
    /**
     * @param  list<string>  $columns  keys from ReportDataset::columns()
     * @param  array<string, scalar|null>  $filters
     */
    public function __construct(
        public ReportDataset $dataset,
        public ExportFormat $format,
        public string $title,
        public array $columns,
        public array $filters = [],
        public ?string $groupBy = null,
        public ?int $consolidatedReportId = null,
        public ?int $generatedForTenantId = null,
        public string $surface = 'oversight',
    ) {}

    /**
     * Build a definition, dropping anything the dataset does not recognise.
     *
     * Unknown column keys and an ungroupable grouping are silently discarded
     * rather than rejected: they arrive from a query string a user can edit,
     * and an export that prints the default columns is a better answer to a
     * mangled URL than a 500. An EMPTY selection falls back to the dataset's
     * defaults, because a spreadsheet with no columns is not an export.
     *
     * @param  list<string>  $columns
     * @param  array<string, scalar|null>  $filters
     */
    public static function make(
        ReportDataset $dataset,
        ExportFormat $format,
        string $title,
        array $columns = [],
        array $filters = [],
        ?string $groupBy = null,
        ?int $consolidatedReportId = null,
        ?int $generatedForTenantId = null,
        string $surface = 'oversight',
    ): self {
        $available = array_keys($dataset->columns());

        // Built by walking $available rather than by intersecting: the
        // selection must come back as a LIST in the dataset's own column
        // order, or it JSON-encodes as an object and returns from the
        // database with string keys. array_intersect() preserves the first
        // array's keys, which is exactly the gappy array that breaks.
        $chosen = [];

        foreach ($available as $column) {
            if (in_array($column, $columns, true)) {
                $chosen[] = $column;
            }
        }

        return new self(
            dataset: $dataset,
            format: $format,
            title: trim($title) === '' ? $dataset->label() : trim($title),
            columns: $chosen === [] ? $dataset->defaultColumns() : $chosen,
            filters: array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            groupBy: $groupBy !== null && in_array($groupBy, $dataset->groupableColumns(), true) ? $groupBy : null,
            consolidatedReportId: $consolidatedReportId,
            generatedForTenantId: $generatedForTenantId,
            surface: $surface,
        );
    }

    /**
     * Column headings in the order they will print.
     *
     * @return list<string>
     */
    public function headings(): array
    {
        $labels = $this->dataset->columns();

        // $this->columns is a list, so array_map hands back a list and there
        // is nothing to re-index.
        return array_map(
            fn (string $key): string => $labels[$key] ?? $key,
            $this->columns,
        );
    }

    /** Whether a column prints right-aligned. */
    public function isNumeric(string $column): bool
    {
        return in_array($column, $this->dataset->numericColumns(), true);
    }

    /**
     * A filename safe on every filesystem and in every mail client: the title
     * reduced to a slug, plus the instant, plus the format. Never anything a
     * user typed verbatim (rules/security.md — no user-controlled filenames).
     */
    public function fileName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $this->title) ?? 'report';
        $slug = trim(strtolower($slug), '-');

        return ($slug === '' ? 'report' : substr($slug, 0, 60))
            .'-'.now()->format('Y-m-d-Hi')
            .'.'.$this->format->extension();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset->value,
            'format' => $this->format->value,
            'title' => $this->title,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'group_by' => $this->groupBy,
            'consolidated_report_id' => $this->consolidatedReportId,
            'generated_for_tenant_id' => $this->generatedForTenantId,
            'surface' => $this->surface,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<string, scalar|null> $filters */
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        /** @var list<string> $columns */
        $columns = is_array($payload['columns'] ?? null) ? array_values($payload['columns']) : [];

        return self::make(
            dataset: ReportDataset::from((string) $payload['dataset']),
            format: ExportFormat::from((string) $payload['format']),
            title: (string) ($payload['title'] ?? ''),
            columns: $columns,
            filters: $filters,
            groupBy: isset($payload['group_by']) ? (string) $payload['group_by'] : null,
            consolidatedReportId: isset($payload['consolidated_report_id'])
                ? (int) $payload['consolidated_report_id']
                : null,
            generatedForTenantId: isset($payload['generated_for_tenant_id'])
                ? (int) $payload['generated_for_tenant_id']
                : null,
            surface: (string) ($payload['surface'] ?? 'oversight'),
        );
    }

    /**
     * The filter set rendered for a human — what prints under the title of the
     * PDF and sits in the register's detail row.
     *
     * @return array<string, string>
     */
    public function describedFilters(): array
    {
        $described = [];

        foreach ($this->filters as $key => $value) {
            $described[ucfirst(str_replace('_', ' ', (string) $key))] = (string) $value;
        }

        return $described;
    }
}
