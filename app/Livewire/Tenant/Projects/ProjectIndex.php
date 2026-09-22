<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Enums\ProjectStatus;
use App\Models\FundingSource;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MDA workspace project register — the module's hottest screen.
 *
 * One code path for every role: `visibleTo()` narrows Consultant/FieldMonitor to
 * their assignments while MDA staff keep the full portfolio, so isolation is
 * proven once instead of per-screen (design §5). The TenantScope confines the
 * query to the bound MDA before any of that runs.
 *
 * Reads only — every mutation on this screen routes through an Action from the
 * row menu or the detail screen.
 */
#[Layout('layouts::tenant')]
class ProjectIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(as: 'funding', except: '')]
    public string $fundingSource = '';

    #[Url(except: '')]
    public string $lga = '';

    /**
     * `yes` = at least one site with BOTH coordinates, `no` = no such site;
     * anything else constrains nothing. With an LGA set, only that LGA's sites
     * are tested — the GIS dashboard's rule, so its tiles drill in here and
     * land on the same number.
     */
    #[Url(except: '')]
    public string $geotagged = '';

    #[Url(except: false)]
    public bool $overdue = false;

    #[Url(except: 'expected_end_date')]
    public string $sort = 'expected_end_date';

    #[Url(except: 'asc')]
    public string $direction = 'asc';

    /** Columns a user may sort by — never interpolate raw input into SQL. */
    private const SORTABLE = [
        'reference', 'title', 'status', 'contract_value_total',
        'physical_progress', 'expected_end_date',
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedFundingSource(): void
    {
        $this->resetPage();
    }

    public function updatedLga(): void
    {
        $this->resetPage();
    }

    public function updatedGeotagged(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'sector', 'fundingSource', 'lga', 'geotagged', 'overdue']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->status !== ''
            || $this->sector !== ''
            || $this->fundingSource !== ''
            || $this->lga !== ''
            || $this->geotaggedFilter() !== null
            || $this->overdue;
    }

    /**
     * The geotag filter as the query reads it: true, false, or no constraint.
     * URL state is user input, so an unrecognised value is ignored, not guessed.
     */
    private function geotaggedFilter(): ?bool
    {
        return match ($this->geotagged) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * Everything the list, the stat row and the export share. Kept as one
     * builder so a row can never appear in the table but be missed by the
     * export, or counted in a stat the filter excluded.
     *
     * @return Builder<Project>
     */
    private function baseQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return Project::query()
            ->visibleTo($user)
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('title', 'like', $term)
                    ->orWhere('reference', 'like', $term));
            })
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->sector !== '', fn (Builder $q) => $q->where('sector_id', $this->sector))
            ->when($this->fundingSource !== '', fn (Builder $q) => $q->whereHas(
                'fundingAllocations',
                fn (Builder $allocation) => $allocation->where('funding_source_id', $this->fundingSource),
            ))
            // A multi-site project appears under every LGA it touches (§1.5),
            // so this is whereHas on locations, not a column on projects.
            ->when($this->lga !== '', fn (Builder $q) => $q->whereHas(
                'locations',
                fn (Builder $location) => $location->where('lga_id', $this->lga),
            ))
            ->when($this->geotaggedFilter() === true, fn (Builder $q) => $q->whereHas(
                'locations',
                $this->constrainToMappableSites(...),
            ))
            ->when($this->geotaggedFilter() === false, fn (Builder $q) => $q->whereDoesntHave(
                'locations',
                $this->constrainToMappableSites(...),
            ))
            ->when($this->overdue, fn (Builder $q) => $this->constrainToOverdue($q));
    }

    /**
     * A site the map can draw: BOTH coordinates present — one without the
     * other is not a fix. With an LGA filter, only that LGA's sites count, so
     * `lga=X&geotagged=no` means "has a site in X, none of them fixed" even if
     * the project is fixed elsewhere. Same rule as the GIS dashboard.
     *
     * @param  Builder<ProjectLocation>  $location
     * @return Builder<ProjectLocation>
     */
    private function constrainToMappableSites(Builder $location): Builder
    {
        return $location
            ->whereNotNull('project_locations.latitude')
            ->whereNotNull('project_locations.longitude')
            ->when($this->lga !== '', fn (Builder $site) => $site->where('project_locations.lga_id', $this->lga));
    }

    /**
     * "Overdue" = the delivery date has passed and the project has not finished.
     * A revised end date supersedes the planned one — extensions are approved
     * reality, and flagging an extended project as late is a false alarm the
     * user learns to ignore.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    private function constrainToOverdue(Builder $query): Builder
    {
        // actual_end_date IS NULL too — the rule Project::isOverdue(), the
        // state portfolio and the GIS map all apply. Without it the register
        // alone counted a project with a recorded finish date as late, and the
        // map's "Overdue" tile drilled into a list with a different number.
        return $query
            ->whereNull('actual_end_date')
            ->whereNotIn('status', [
                ProjectStatus::Completed->value,
                ProjectStatus::Certified->value,
                ProjectStatus::Closed->value,
                ProjectStatus::Cancelled->value,
            ])
            ->whereNotNull(DB::raw('COALESCE(revised_end_date, expected_end_date)'))
            ->whereRaw('COALESCE(revised_end_date, expected_end_date) < ?', [Carbon::today()->toDateString()]);
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->with([
                'sector:id,name',
                'primaryLocation:id,project_id,site_name,lga_id',
                'primaryLocation.lga:id,name',
            ])
            ->orderBy($this->sortColumn(), $this->direction === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(25);
    }

    private function sortColumn(): string
    {
        return in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'expected_end_date';
    }

    /**
     * Summary row. Three aggregates in one round trip rather than three queries
     * — this screen is opened dozens of times a day per user.
     *
     * @return array{count: int, contract_value: string, in_progress: int, overdue: int}
     */
    #[Computed]
    public function stats(): array
    {
        $unfinished = [
            ProjectStatus::Completed->value,
            ProjectStatus::Certified->value,
            ProjectStatus::Closed->value,
            ProjectStatus::Cancelled->value,
        ];

        // All four figures in ONE round trip. The overdue tally repeats the
        // predicate of constrainToOverdue() as a CASE rather than running a
        // second query — same definition, expressed once per engine.
        $totals = $this->baseQuery()
            ->toBase()
            ->selectRaw('COUNT(*) as aggregate_count')
            ->selectRaw('COALESCE(SUM(contract_value_total), 0) as aggregate_value')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as aggregate_in_progress', [ProjectStatus::InProgress->value])
            ->selectRaw(
                'SUM(CASE WHEN status NOT IN (?, ?, ?, ?)
                      AND actual_end_date IS NULL
                      AND COALESCE(revised_end_date, expected_end_date) IS NOT NULL
                      AND COALESCE(revised_end_date, expected_end_date) < ?
                     THEN 1 ELSE 0 END) as aggregate_overdue',
                [...$unfinished, Carbon::today()->toDateString()],
            )
            ->first();

        return [
            'count' => (int) ($totals->aggregate_count ?? 0),
            'contract_value' => (string) ($totals->aggregate_value ?? '0'),
            'in_progress' => (int) ($totals->aggregate_in_progress ?? 0),
            'overdue' => (int) ($totals->aggregate_overdue ?? 0),
        ];
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, FundingSource> */
    #[Computed]
    public function fundingSources(): Collection
    {
        return FundingSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Only the LGAs this workspace actually works in — a 20-item list beats the
     * full state roll, and an option that returns nothing is a dead end.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function lgaOptions(): array
    {
        return Lga::query()
            ->whereIn('id', ProjectLocation::query()->select('lga_id')->whereNotNull('lga_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(ProjectStatus::cases())
            ->mapWithKeys(fn (ProjectStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /**
     * CSV of the current filter set — the figures on screen, nothing else.
     * Streamed in chunks so a 5,000-project portfolio does not build an array
     * in memory. (.xlsx via maatwebsite/excel is a follow-up; CSV opens in
     * Excel today and is what the finance officers actually re-import.)
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Project::class);

        $filename = 'projects-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        $query = $this->baseQuery()
            ->with(['sector:id,name'])
            ->orderBy($this->sortColumn(), $this->direction === 'desc' ? 'desc' : 'asc');

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it, which
            // mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Reference'), __('Title'), __('Sector'), __('Status'),
                __('Budget allocation'), __('Contract value'), __('Expenditure to date'),
                __('Physical progress %'), __('Start date'), __('Expected end date'),
                __('Revised end date'),
            ]);

            $query->chunk(500, function (iterable $projects) use ($handle): void {
                /** @var Project $project */
                foreach ($projects as $project) {
                    fputcsv($handle, [
                        $project->reference,
                        $project->title,
                        $project->sector?->name,
                        $project->status->label(),
                        $project->budget_allocation?->toDecimalString(),
                        $project->contract_value_total?->toDecimalString(),
                        $project->expenditure_to_date->toDecimalString(),
                        $project->physical_progress,
                        $project->start_date?->toDateString(),
                        $project->expected_end_date?->toDateString(),
                        $project->revised_end_date?->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.project-index');
    }
}
