<?php

namespace App\Support\Exporting;

use App\Actions\Oversight\AggregateForIndicatorPerformance;
use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Actions\Oversight\ListProjectsAcrossTenants;
use App\Actions\Oversight\ListReportsAcrossTenants;
use App\Enums\ProgressReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReportDataset;
use App\Models\ConsolidatedReport;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\InstanceTime;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Stringable;

/**
 * THE honesty layer of the report builder: the one place that turns a
 * ReportDataset plus a scalar filter set into rows.
 *
 * Every dataset here resolves through an existing app/Actions/Oversight/
 * Action — the SAME one the matching dashboard calls:
 *
 *   projects   → ListProjectsAcrossTenants        (the /portfolio list)
 *   reports    → ListReportsAcrossTenants         (the cross-MDA reports desk)
 *   compliance → BuildComplianceLeagueTable       (the /compliance board)
 *   indicators → AggregateForIndicatorPerformance (the consolidation's own source)
 *
 * A builder with its own SQL would eventually print a number the dashboard
 * disagrees with, and a government report that contradicts the screen it came
 * from is worse than no report. It also means the authorization is not
 * duplicated: each Action checks its own oversight permission in the GLOBAL
 * team before bypassing tenancy, so the builder cannot become a side door to
 * data its user cannot already see.
 *
 * ⚠ No tenancy bypass lives HERE, deliberately. Every bypass is inside the
 * Action that authorizes it (rules/tenancy.md, enforced by the discipline
 * test) — this class only shapes what those Actions hand back.
 */
class DatasetRows
{
    /** Rows fetched per round trip while streaming an export. */
    public const CHUNK_SIZE = 500;

    /**
     * Stream every row the definition selects. `$callback` returning false
     * stops the walk, so a 25-row preview costs one page rather than a full
     * scan.
     *
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    public function chunk(User $actor, ExportDefinition $definition, callable $callback): void
    {
        match ($definition->dataset) {
            ReportDataset::Projects => $this->chunkProjects($actor, $definition, $callback),
            ReportDataset::Reports => $this->chunkReports($actor, $definition, $callback),
            ReportDataset::Compliance => $this->chunkCompliance($actor, $definition, $callback),
            ReportDataset::Indicators => $this->chunkIndicators($actor, $definition, $callback),
            ReportDataset::Consolidation => $this->chunkConsolidation($definition, $callback),
        };
    }

    /**
     * The first $limit rows — what the builder previews before anyone commits
     * to generating a file.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(User $actor, ExportDefinition $definition, int $limit = 25): array
    {
        $rows = [];

        $this->chunk($actor, $definition, function (array $chunk) use (&$rows, $limit): bool {
            foreach ($chunk as $row) {
                $rows[] = $row;

                if (count($rows) >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $rows;
    }

    /**
     * How many rows the definition selects, cheaply — the number that decides
     * whether generation happens inline or on a queue. For the two paginated
     * datasets this is the paginator's own COUNT; for the two array datasets
     * the rows are already in memory and counting them is free.
     */
    public function count(User $actor, ExportDefinition $definition): int
    {
        return match ($definition->dataset) {
            ReportDataset::Projects => app(ListProjectsAcrossTenants::class)(
                $actor, $this->projectFilters($definition), 1
            )->total(),
            ReportDataset::Reports => app(ListReportsAcrossTenants::class)(
                $actor, $this->reportFilters($definition), 1
            )->total(),
            ReportDataset::Compliance => count($this->complianceRows($actor, $definition)),
            ReportDataset::Indicators => count($this->indicatorRows($actor, $definition, null)),
            ReportDataset::Consolidation => count($this->consolidationRows($definition)),
        };
    }

    /**
     * The row values for the definition's chosen columns, in order — what a
     * CSV line, a spreadsheet row and a PDF table cell all print.
     *
     * @param  array<string, mixed>  $row
     * @return list<string|int|float|bool|null>
     */
    public function values(ExportDefinition $definition, array $row): array
    {
        $values = [];

        foreach ($definition->columns as $column) {
            $value = $row[$column] ?? null;

            // One is_*() check per type rather than is_scalar(): a row value
            // arrives as `mixed` from an Action's array, and narrowing it a
            // type at a time is what lets a NULL stay a null all the way to
            // the writer. That distinction is load-bearing — with
            // WithStrictNullComparison it is the difference between "0 returns
            // filed" and "we do not know".
            if ($value === null) {
                $values[] = null;
            } elseif (is_string($value)) {
                $values[] = $value;
            } elseif (is_int($value)) {
                $values[] = $value;
            } elseif (is_float($value)) {
                $values[] = $value;
            } elseif (is_bool($value)) {
                $values[] = $value;
            } else {
                // A value object that prints itself (Money, a date) becomes
                // its text; anything else becomes an empty cell rather than
                // the word "Array" in a government spreadsheet.
                $values[] = $value instanceof Stringable ? (string) $value : null;
            }
        }

        return $values;
    }

    /* ---------------------------------------------------------------- */
    /* Projects — ListProjectsAcrossTenants */
    /* ---------------------------------------------------------------- */

    /**
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    private function chunkProjects(User $actor, ExportDefinition $definition, callable $callback): void
    {
        $action = app(ListProjectsAcrossTenants::class);
        $filters = $this->projectFilters($definition);
        $page = 1;

        do {
            // Page THROUGH the Action rather than reaching past it into a
            // builder of our own. The portfolio list exposes no chunk()
            // helper, and duplicating its query here to get one would be the
            // exact second SQL path this class exists to prevent.
            $paginator = $this->onPage($page, fn (): LengthAwarePaginator => $action($actor, $filters, self::CHUNK_SIZE));

            $rows = [];

            foreach ($paginator->items() as $project) {
                $rows[] = $this->projectRow($project);
            }

            if ($rows !== [] && ! $callback($rows)) {
                return;
            }

            $more = $page < $paginator->lastPage();
            $page++;
        } while ($more);
    }

    /**
     * Run a paginating Action on a specific page.
     *
     * The page number is not a parameter of `paginate()` as the Actions call
     * it — Laravel resolves it from the request through this resolver, which
     * is also how Livewire drives its own paginators. So the resolver is
     * swapped for the length of one call and put back pinned to whatever page
     * was in effect before, which is what any later `paginate()` in this
     * request would have resolved anyway. The `finally` is load-bearing: a
     * throw mid-export must not leave the request stuck on page 7.
     *
     * @param  Closure(): LengthAwarePaginator<int, Project>  $callback
     * @return LengthAwarePaginator<int, Project>
     */
    private function onPage(int $page, Closure $callback): LengthAwarePaginator
    {
        $restore = Paginator::resolveCurrentPage();

        Paginator::currentPageResolver(fn (): int => $page);

        try {
            return $callback();
        } finally {
            Paginator::currentPageResolver(fn (): int => $restore);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(Project $project): array
    {
        return [
            'entity' => $project->tenant->name,
            'reference' => $project->reference,
            'title' => $project->title,
            'sector' => $project->sector->name,
            'status' => $project->status->label(),
            'contract_value' => $project->contract_value_total?->toDecimalString(),
            'expenditure' => $project->expenditure_to_date->toDecimalString(),
            'physical_progress' => $project->physical_progress,
            'start_date' => $project->start_date?->toDateString(),
            'expected_end_date' => $project->expected_end_date?->toDateString(),
            'lga' => $project->primaryLocation?->lga?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectFilters(ExportDefinition $definition): array
    {
        $filters = $definition->filters;

        return [
            'tenant' => $this->tenant($filters['tenant'] ?? null),
            'status' => isset($filters['status']) ? ProjectStatus::tryFrom((string) $filters['status']) : null,
            'search' => isset($filters['search']) ? (string) $filters['search'] : null,
            'overdue' => (bool) ($filters['overdue'] ?? false),
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Returns — ListReportsAcrossTenants */
    /* ---------------------------------------------------------------- */

    /**
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    private function chunkReports(User $actor, ExportDefinition $definition, callable $callback): void
    {
        $stopped = false;

        app(ListReportsAcrossTenants::class)->chunk(
            $actor,
            $this->reportFilters($definition),
            function (Collection $reports) use ($callback, &$stopped): void {
                if ($stopped) {
                    return;
                }

                $rows = [];

                foreach ($reports as $report) {
                    $rows[] = $this->reportRow($report);
                }

                // The Action's chunk() has no early exit, so a preview that
                // has seen enough latches instead of running away with the
                // whole table.
                $stopped = $rows !== [] && ! $callback($rows);
            },
            self::CHUNK_SIZE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reportRow(ProgressReport $report): array
    {
        return [
            'entity' => $report->tenant->name,
            'window' => $report->reportingPeriod->label,
            'reference' => $report->project->reference,
            'project' => $report->project->title,
            'status' => $report->status->label(),
            'physical_progress' => $report->physical_progress_claimed,
            'period_expenditure' => $report->period_expenditure->toDecimalString(),
            // The state's wall clock, not UTC — the date the MDA filed on.
            'submitted_at' => $report->submitted_at === null
                ? null
                : InstanceTime::local($report->submitted_at)->toDateString(),
            'submitted_by' => $report->submittedBy?->name,
            'submitted_late' => $report->submitted_late ? __('Yes') : __('No'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportFilters(ExportDefinition $definition): array
    {
        $filters = $definition->filters;

        return [
            'tenant' => $this->tenant($filters['tenant'] ?? null),
            'period' => $this->period($filters['period'] ?? null),
            'status' => isset($filters['status'])
                ? ProgressReportStatus::tryFrom((string) $filters['status'])
                : null,
            'search' => isset($filters['search']) ? (string) $filters['search'] : null,
            'lateness' => isset($filters['lateness']) ? (string) $filters['lateness'] : null,
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Compliance — BuildComplianceLeagueTable */
    /* ---------------------------------------------------------------- */

    /**
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    private function chunkCompliance(User $actor, ExportDefinition $definition, callable $callback): void
    {
        $rows = $this->complianceRows($actor, $definition);

        if ($rows !== []) {
            $callback($rows);
        }
    }

    /**
     * One row per MDA — forty on the largest state deployment, so the whole
     * board comes back in one grouped query and there is nothing to chunk.
     *
     * @return list<array<string, mixed>>
     */
    private function complianceRows(User $actor, ExportDefinition $definition): array
    {
        $period = $this->period($definition->filters['period'] ?? null) ?? $this->latestPeriod();

        if ($period === null) {
            return [];
        }

        $board = app(BuildComplianceLeagueTable::class)($actor, $period);

        return array_map(fn (array $row): array => [
            'entity' => $row['name'],
            'window' => $board['period']['label'],
            'expected' => $row['expected'],
            'submitted' => $row['submitted'],
            'on_time' => $row['on_time'],
            'missed' => $row['missed'],
            'waived' => $row['waived'],
            'compliance_rate' => $row['compliance_rate'],
            'on_time_rate' => $row['on_time_rate'],
        ], $board['tenants']);
    }

    /* ---------------------------------------------------------------- */
    /* Indicators — AggregateForIndicatorPerformance */
    /* ---------------------------------------------------------------- */

    /**
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    private function chunkIndicators(User $actor, ExportDefinition $definition, callable $callback): void
    {
        $filters = $definition->filters;

        app(AggregateForIndicatorPerformance::class)->chunk(
            $actor,
            $this->period($filters['period'] ?? null),
            [
                'tenant' => $this->tenant($filters['tenant'] ?? null),
                'search' => isset($filters['search']) ? (string) $filters['search'] : null,
                'band' => isset($filters['band']) ? (string) $filters['band'] : null,
            ],
            function (array $rows) use ($callback): bool {
                foreach ($rows as $index => $row) {
                    $rows[$index]['band'] = AggregateForIndicatorPerformance::bandLabel((string) $row['band']);
                }

                return $rows === [] || $callback($rows);
            },
            self::CHUNK_SIZE,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function indicatorRows(User $actor, ExportDefinition $definition, ?int $limit): array
    {
        $rows = [];

        $this->chunkIndicators($actor, $definition, function (array $chunk) use (&$rows, $limit): bool {
            foreach ($chunk as $row) {
                $rows[] = $row;

                if ($limit !== null && count($rows) >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $rows;
    }

    /* ---------------------------------------------------------------- */
    /* Consolidation — ConsolidatedReport's own snapshot accessors */
    /* ---------------------------------------------------------------- */

    /**
     * The per-entity annex of ONE state roll-up.
     *
     * ⚠ Read through ConsolidatedReport::entityFigures(), never off the
     * entries table. That accessor returns the FROZEN snapshot once the report
     * has been signed and the live rows before then — so the spreadsheet annex
     * and the PDF state the same figures, which is the entire point of taking
     * a snapshot at approval. A second read here that went to the live tables
     * would silently restate a signed report every time an MDA edited an old
     * return.
     *
     * No tenancy bypass and no oversight Action: the consolidation and its
     * entries are GLOBAL rows, and the authority to read them was already
     * spent in assertReadable().
     *
     * @return list<array<string, mixed>>
     */
    private function consolidationRows(ExportDefinition $definition): array
    {
        if ($definition->consolidatedReportId === null) {
            return [];
        }

        $report = ConsolidatedReport::query()
            ->whereKey($definition->consolidatedReportId)
            ->first();

        // A consolidation deleted between the request and the generation is
        // an empty annex, not a 500: the register row still records that
        // somebody asked, which is what the register is for.
        return $report?->entityFigures() ?? [];
    }

    /**
     * One MDA per row — forty on the largest state deployment, so the whole
     * annex comes back in one read and there is nothing to chunk.
     *
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    private function chunkConsolidation(ExportDefinition $definition, callable $callback): void
    {
        $rows = $this->consolidationRows($definition);

        if ($rows !== []) {
            $callback($rows);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Filter resolution */
    /* ---------------------------------------------------------------- */

    /**
     * A workspace by SLUG, never by id: the slug is what appears in a URL and
     * a stored filter set, and it stays readable in a register row six months
     * later where an integer would not.
     */
    private function tenant(mixed $slug): ?Tenant
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return Tenant::query()->where('slug', (string) $slug)->first();
    }

    private function period(mixed $code): ?ReportingPeriod
    {
        if ($code === null || $code === '') {
            return null;
        }

        return ReportingPeriod::query()->where('code', (string) $code)->first();
    }

    /** The most recent window that has opened — the default denominator. */
    private function latestPeriod(): ?ReportingPeriod
    {
        return ReportingPeriod::query()
            ->where('opens_at', '<=', now())
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * Guard for a caller that hands over a dataset the actor may not read.
     * Every Action re-checks its own permission, so this is belt AND braces —
     * and it is the braces that produce a clean message on the screen rather
     * than an exception from three layers down.
     */
    public function assertReadable(User $actor, ExportDefinition $definition): void
    {
        if (! $actor->holdsGlobalPermission($definition->dataset->permission())) {
            throw new AuthorizationException(
                'Exporting '.$definition->dataset->label().' requires oversight authority.'
            );
        }
    }
}
