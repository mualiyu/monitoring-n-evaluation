<?php

namespace App\Enums;

use Maatwebsite\Excel\Excel as ExcelWriter;

/**
 * The three shapes a generated artifact leaves this platform in.
 *
 * CSV and XLSX both go through maatwebsite/excel so that one writer produces
 * both — a platform with a hand-rolled CSV path and a separate spreadsheet
 * path eventually disagrees about a column. PDF goes through dompdf and a
 * Blade template, because a PDF is a laid-out document, not a grid.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Csv => __('CSV'),
            self::Xlsx => __('Excel'),
            self::Pdf => __('PDF'),
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Csv => 'document-text',
            self::Xlsx => 'squares',
            self::Pdf => 'folder',
        };
    }

    /** Whether maatwebsite/excel writes this format (as opposed to dompdf). */
    public function isSpreadsheet(): bool
    {
        return $this !== self::Pdf;
    }

    /** The maatwebsite writer type. Meaningless for PDF, hence the null. */
    public function writerType(): ?string
    {
        return match ($this) {
            self::Csv => ExcelWriter::CSV,
            self::Xlsx => ExcelWriter::XLSX,
            self::Pdf => null,
        };
    }
}
