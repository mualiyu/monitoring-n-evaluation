<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Exports;

use App\Actions\Oversight\AggregateForIndicatorPerformance;
use App\Enums\ExportFormat;
use App\Enums\ProgressReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReportDataset;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Exporting\DatasetRows;
use App\Support\Exporting\ExportDefinition;
use App\Support\Exporting\ReportExporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The ad-hoc report builder (plan §8): pick a dataset, the columns, the
 * filters and a grouping, look at twenty-five rows, and take the answer away
 * as CSV, Excel or PDF.
 *
 * THE HONESTY RULE. Every figure this screen prints comes from
 * App\Support\Exporting\DatasetRows, which resolves each dataset through the
 * SAME app/Actions/Oversight/ Action the matching dashboard calls. There is no
 * second SQL path: a builder with its own query would eventually print a
 * number the dashboard disagrees with, and a government report that
 * contradicts the screen it was exported from is worse than no report at all.
 *
 * That also means authorization is not duplicated. Each Action re-checks its
 * own oversight permission in the GLOBAL permission team before bypassing
 * tenancy, and DatasetRows::assertReadable refuses a dataset this actor may
 * not read — so the builder can never become a side door to data its user
 * cannot already see on a board.
 *
 * Nothing here touches a model directly, so there is no tenancy bypass in this
 * file at all.
 */
#[Layout('layouts::oversight')]
class ReportBuilder extends Component
{
    /** The permission that opens the screen; each dataset then costs its own. */
    private const SCREEN_PERMISSION = 'oversight.reports.view';

    #[Url(except: 'projects')]
    public string $dataset = 'projects';

    /** Filters. Scalars only — they are stored verbatim on the register row. */
    #[Url(as: 'mda', except: '')]
    public string $tenant = '';

    #[Url(except: '')]
    public string $period = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $band = '';

    #[Url(except: '')]
    public string $lateness = '';

    #[Url(except: false)]
    public bool $overdue = false;

    #[Url(as: 'group', except: '')]
    public string $groupBy = '';

    /**
     * Column keys, in print order.
     *
     * @var list<string>
     */
    #[Url(except: [])]
    public array $columns = [];

    public string $title = '';

    /** A refusal, shown verbatim. */
    public ?string $failure = null;

    /** An outcome worth stating without alarm — a queued export, usually. */
    public ?string $notice = null;

    /**
     * Per-request memos for the two reads the screen makes. Private, so
     * Livewire neither serialises nor rehydrates them — a preview must be
     * re-read on every request, never carried across one.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $previewCache = null;

    private ?int $rowCountCache = null;

    public function mount(): void
    {
        abort_unless($this->user()->holdsGlobalPermission(self::SCREEN_PERMISSION), 403);

        if (ReportDataset::tryFrom($this->dataset)?->isBuilderSelectable() !== true) {
            $this->dataset = ReportDataset::Projects->value;
        }

        // A fresh session starts on the columns a secretariat asks for nine
        // times out of ten; a shared URL keeps whatever it carried.
        if ($this->columns === []) {
            $this->columns = $this->chosenDataset()->defaultColumns();
        }

        if ($this->title === '') {
            $this->title = $this->chosenDataset()->label();
        }
    }

    /* ------------------------------------------------------------------ */
    /* The question being asked */
    /* ------------------------------------------------------------------ */

    public function chosenDataset(): ReportDataset
    {
        $dataset = ReportDataset::tryFrom($this->dataset);

        return $dataset !== null && $dataset->isBuilderSelectable()
            ? $dataset
            : ReportDataset::Projects;
    }

    /**
     * Switching dataset resets the column selection, the grouping and the
     * title, because none of them means anything in the new dataset — column
     * KEYS are part of each dataset's contract, not a shared vocabulary.
     */
    public function updatedDataset(): void
    {
        $dataset = $this->chosenDataset();

        $this->dataset = $dataset->value;
        $this->columns = $dataset->defaultColumns();
        $this->groupBy = '';
        $this->band = '';
        $this->lateness = '';
        $this->overdue = false;
        $this->status = '';
        $this->title = $dataset->label();
        $this->failure = null;
        $this->notice = null;

        $this->previewCache = null;
        $this->rowCountCache = null;
    }

    /**
     * The checkboxes bind straight to this array, so anything could arrive in
     * it from a crafted payload or a hand-edited URL. Unknown keys are dropped
     * rather than rejected — a mangled query string should print the ordinary
     * columns, not a 500 — and the order is normalised to the dataset's own,
     * which is the order ExportDefinition prints in anyway.
     */
    public function updatedColumns(): void
    {
        $this->columns = array_values(array_intersect(
            array_keys($this->chosenDataset()->columns()),
            $this->columns,
        ));

        $this->previewCache = null;
    }

    public function resetColumns(): void
    {
        $this->columns = $this->chosenDataset()->defaultColumns();

        $this->previewCache = null;
    }

    public function clearFilters(): void
    {
        $this->reset(['tenant', 'period', 'status', 'search', 'band', 'lateness', 'overdue', 'groupBy']);

        $this->previewCache = null;
        $this->rowCountCache = null;
    }

    public function hasFilters(): bool
    {
        return $this->tenant !== '' || $this->period !== '' || $this->status !== ''
            || $this->search !== '' || $this->band !== '' || $this->lateness !== '' || $this->overdue;
    }

    /**
     * The filter set, as the scalars the definition stores. Slugs and codes
     * rather than ids: a register row six months old has to remain readable,
     * and "works" says something an integer does not.
     *
     * @return array<string, scalar|null>
     */
    public function filters(): array
    {
        $dataset = $this->chosenDataset();

        $filters = [
            'tenant' => $this->tenant === '' ? null : $this->tenant,
            'period' => $this->period === '' ? null : $this->period,
            'search' => $this->search === '' ? null : $this->search,
        ];

        return match ($dataset) {
            ReportDataset::Projects => [
                ...$filters,
                'period' => null,
                'status' => $this->status === '' ? null : $this->status,
                'overdue' => $this->overdue ?: null,
            ],
            ReportDataset::Reports => [
                ...$filters,
                'status' => $this->status === '' ? null : $this->status,
                'lateness' => $this->lateness === '' ? null : $this->lateness,
            ],
            ReportDataset::Compliance => ['period' => $filters['period']],
            default => [
                ...$filters,
                'band' => $this->band === '' ? null : $this->band,
            ],
        };
    }

    public function definition(ExportFormat $format = ExportFormat::Csv): ExportDefinition
    {
        return ExportDefinition::make(
            dataset: $this->chosenDataset(),
            format: $format,
            title: $this->title,
            columns: $this->columns,
            filters: $this->filters(),
            groupBy: $this->groupBy === '' ? null : $this->groupBy,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Preview */
    /* ------------------------------------------------------------------ */

    /**
     * Twenty-five rows, through the same Actions the export uses. The preview
     * stops chunking once it has enough, so looking costs one page rather than
     * a full scan.
     *
     * Memoised on a PRIVATE property rather than with #[Computed]: the view
     * asks for it, so does the row counter and so does the grouping, and a
     * private property is naturally per-request (Livewire never hydrates one)
     * without the static-analysis blind spot a magic property introduces.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(): array
    {
        if ($this->previewCache !== null) {
            return $this->previewCache;
        }

        $definition = $this->definition();
        $rows = app(DatasetRows::class);

        try {
            $rows->assertReadable($this->user(), $definition);

            return $this->previewCache = $rows->preview($this->user(), $definition, 25);
        } catch (AuthorizationException) {
            // Stated on the screen by unreadable(), not thrown: the actor may
            // legitimately hold one dataset's permission and not another's.
            return $this->previewCache = [];
        }
    }

    /** How many rows the question selects in total, not just on this page. */
    public function rowCount(): int
    {
        if ($this->rowCountCache !== null) {
            return $this->rowCountCache;
        }

        $definition = $this->definition();
        $rows = app(DatasetRows::class);

        try {
            $rows->assertReadable($this->user(), $definition);

            return $this->rowCountCache = $rows->count($this->user(), $definition);
        } catch (AuthorizationException) {
            return $this->rowCountCache = 0;
        }
    }

    /** Why the preview is empty, when the reason is authority rather than data. */
    public function unreadable(): ?string
    {
        return $this->user()->holdsGlobalPermission($this->chosenDataset()->permission())
            ? null
            : __('Your oversight role does not carry authority over :dataset. Choose another dataset.', [
                'dataset' => $this->chosenDataset()->label(),
            ]);
    }

    /** Whether the export will be produced on this request or on a worker. */
    public function willQueue(): bool
    {
        return $this->rowCount() > ReportExporter::INLINE_ROW_LIMIT;
    }

    /**
     * Rows grouped for the preview, in the order the buckets first appear.
     * Grouping is presentational — it buckets rows that were already fetched
     * and never changes a figure.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupedPreview(): array
    {
        $rows = $this->preview();

        if ($this->groupBy === '') {
            return ['' => $rows];
        }

        $grouped = [];

        foreach ($rows as $row) {
            $bucket = (string) ($row[$this->groupBy] ?? __('Not stated'));
            $grouped[$bucket][] = $row;
        }

        return $grouped;
    }

    /* ------------------------------------------------------------------ */
    /* Generating */
    /* ------------------------------------------------------------------ */

    /**
     * Produce the artifact.
     *
     * The file never reaches the browser from here: ReportExporter stores it
     * on the private disk and registers who asked for it under which filters,
     * and it leaves only through the signed, policy-checked download route.
     * Anything large goes to a worker and appears in the register instead.
     */
    public function generate(ReportExporter $exporter, string $format): void
    {
        abort_unless($this->user()->holdsGlobalPermission(self::SCREEN_PERMISSION), 403);

        $this->failure = null;
        $this->notice = null;

        $chosen = ExportFormat::tryFrom($format);

        if ($chosen === null) {
            $this->failure = __('That is not a format this platform writes.');

            return;
        }

        $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'columns' => ['required', 'array', 'min:1'],
        ], [
            'columns.required' => __('Choose at least one column — a spreadsheet with no columns is not an export.'),
            'columns.min' => __('Choose at least one column — a spreadsheet with no columns is not an export.'),
        ], ['title' => __('title'), 'columns' => __('columns')]);

        try {
            $export = $exporter->run($this->user(), $this->definition($chosen));
        } catch (AuthorizationException $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        if (! $export->isDownloadable()) {
            $this->notice = __('This export is large, so it is being built on a worker. It will appear in the export register when it is ready.');

            return;
        }

        $this->redirect($exporter->downloadUrl($export));
    }

    /* ------------------------------------------------------------------ */
    /* Options */
    /* ------------------------------------------------------------------ */

    /** @return array<string, string> */
    #[Computed]
    public function datasetOptions(): array
    {
        $options = [];

        foreach (ReportDataset::builderOptions() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Workspaces keyed by SLUG, not id: the slug is what a stored filter set
     * carries, and it is still readable when the register row is opened a year
     * later.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tenantOptions(): array
    {
        return Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function periodOptions(): array
    {
        /** @var Collection<int, ReportingPeriod> $periods */
        $periods = ReportingPeriod::query()
            ->where('opens_at', '<=', now())
            ->orderByDesc('period_start')
            ->limit(24)
            ->get();

        return $periods
            ->mapWithKeys(fn (ReportingPeriod $period): array => [
                $period->code => $period->label.' · '.$period->cadence->label(),
            ])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        $options = [];

        $cases = $this->chosenDataset() === ReportDataset::Reports
            ? ProgressReportStatus::cases()
            : ProjectStatus::cases();

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function bandOptions(): array
    {
        return [
            AggregateForIndicatorPerformance::BAND_ON_TRACK => AggregateForIndicatorPerformance::bandLabel(AggregateForIndicatorPerformance::BAND_ON_TRACK),
            AggregateForIndicatorPerformance::BAND_AT_RISK => AggregateForIndicatorPerformance::bandLabel(AggregateForIndicatorPerformance::BAND_AT_RISK),
            AggregateForIndicatorPerformance::BAND_OFF_TRACK => AggregateForIndicatorPerformance::bandLabel(AggregateForIndicatorPerformance::BAND_OFF_TRACK),
            AggregateForIndicatorPerformance::BAND_NO_TARGET => AggregateForIndicatorPerformance::bandLabel(AggregateForIndicatorPerformance::BAND_NO_TARGET),
        ];
    }

    /** @return array<string, string> */
    public function latenessOptions(): array
    {
        return ['on_time' => __('Filed on time'), 'late' => __('Filed late')];
    }

    /** @return array<string, string> */
    public function groupOptions(): array
    {
        $labels = $this->chosenDataset()->columns();
        $options = [];

        foreach ($this->chosenDataset()->groupableColumns() as $column) {
            $options[$column] = $labels[$column] ?? $column;
        }

        return $options;
    }

    /* ------------------------------------------------------------------ */

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.exports.report-builder');
    }
}
