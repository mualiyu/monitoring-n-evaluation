<?php

namespace App\Exports;

use App\Models\User;
use App\Support\Exporting\DatasetRows;
use App\Support\Exporting\ExportDefinition;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Any ExportDefinition, as a spreadsheet — the ONE maatwebsite export object
 * behind both CSV and XLSX, because a platform with a hand-rolled CSV writer
 * and a separate spreadsheet writer eventually disagrees about a column.
 *
 * FromCollection rather than FromQuery, deliberately. Two of the four datasets
 * (compliance, indicators) are computed aggregates with no query to hand over,
 * and all four are reached through Actions rather than builders — that is the
 * honesty rule of DatasetRows. The collection is still built by CHUNKED reads
 * (500 rows a round trip), and ReportExporter sends anything large to a queued
 * worker rather than a web request, so the memory the sheet occupies is
 * bounded by policy instead of by luck.
 *
 * WithStrictNullComparison: without it, a null lands in the sheet as an empty
 * string and a genuine zero lands as blank too — so "0 returns filed" and "we
 * do not know" print identically. On a compliance table that is the difference
 * between a finding and a gap.
 *
 * Maatwebsite's FromCollection carries no generic parameters, so the row shape
 * is stated on collection() rather than on the class.
 */
class DatasetExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    private int $rowCount = 0;

    private bool $truncated = false;

    public function __construct(
        private readonly User $actor,
        private readonly ExportDefinition $definition,
        private readonly ?int $maxRows = null,
    ) {}

    /**
     * A row is a LIST OF CELLS, and the cell type is deliberately `mixed`
     * rather than `scalar|null`: a genuine null has to survive all the way to
     * the writer for WithStrictNullComparison to tell "0 returns filed" from
     * "we do not know", and Illuminate\Support\Collection's TValue is
     * invariant, so a nullable union there is not a type this collection can
     * actually be constructed as. DatasetRows::values() states the real cell
     * types.
     *
     * @return Collection<int, list<mixed>>
     */
    public function collection(): Collection
    {
        $rows = app(DatasetRows::class);

        /** @var list<list<mixed>> $out */
        $out = [];

        $rows->chunk($this->actor, $this->definition, function (array $chunk) use ($rows, &$out): bool {
            foreach ($chunk as $row) {
                if ($this->maxRows !== null && $this->rowCount >= $this->maxRows) {
                    $this->truncated = true;

                    return false;
                }

                $out[] = $rows->values($this->definition, $row);
                $this->rowCount++;
            }

            return true;
        });

        // Wrapped once at the end rather than pushed into a Collection row by
        // row: the sheet is handed one immutable list, and nothing downstream
        // can be handed a half-built collection.
        return new Collection($out);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->definition->headings();
    }

    /** The worksheet tab name. Excel refuses >31 chars and five punctuation marks. */
    public function title(): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $this->definition->title) ?? '';

        return mb_substr(trim($clean) === '' ? $this->definition->dataset->label() : trim($clean), 0, 31);
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }
}
