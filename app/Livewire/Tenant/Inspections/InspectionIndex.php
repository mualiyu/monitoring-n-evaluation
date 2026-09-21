<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Inspections;

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\User;
use App\Support\InstanceTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The MDA's field-work desk: what is in the diary, what happened, and what has
 * not been written up.
 *
 * ONE CODE PATH FOR EVERY ROLE. `visibleTo()` narrows a consultant to their
 * assignments and a field monitor to those plus their own visits, while MDA
 * staff see the whole desk — so isolation is proven once rather than per
 * screen, and the export runs the list's own builder, which is what stops a
 * row being absent from the screen and present in the file.
 *
 * Reads only. Every mutation routes through the conduct form or the detail
 * screen, both of which authorize again on each method.
 */
#[Layout('layouts::tenant')]
class InspectionIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $outcome = '';

    /**
     * The project filter, keyed by ULID rather than by primary key. A bookmark
     * or a pasted link carries this value, and an auto-increment id in a URL
     * both enumerates another MDA's volumes and invites a guess at a row that
     * is not yours. Nothing leaks either way, because the lookup runs inside
     * the TenantScope; this is the difference between "refused" and "not
     * addressable".
     */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    /** Only visits I am the named lead for — a monitor's own diary. */
    #[Url(as: 'mine', except: false)]
    public bool $mineOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', SiteInspection::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedOutcome(): void
    {
        $this->resetPage();
    }

    public function updatedProjectUlid(): void
    {
        $this->resetPage();
    }

    public function updatedMineOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'type', 'outcome', 'projectUlid', 'mineOnly']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->type !== ''
            || $this->outcome !== '' || $this->projectUlid !== '' || $this->mineOnly;
    }

    /**
     * @return LengthAwarePaginator<int, SiteInspection>
     */
    #[Computed]
    public function inspections(): LengthAwarePaginator
    {
        return $this->query()
            ->with([
                'project:id,ulid,title,reference',
                'leadInspector:id,name',
            ])
            ->withCount(['responses as findings_count' => fn (Builder $q) => $q->where('is_finding', true)])
            // Newest first: a field desk is read from the top, and the visit
            // that just happened is the one somebody is asking about.
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Builder<SiteInspection> */
    private function query(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return SiteInspection::query()
            ->visibleTo($user)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->outcome !== '', fn (Builder $q) => $q->where('outcome', $this->outcome))
            ->when($this->mineOnly, fn (Builder $q) => $q->where('lead_inspector_id', $user->id))
            ->when($this->projectUlid !== '', fn (Builder $q) => $q->whereIn('project_id', $this->filteredProject()))
            ->when($this->search !== '', fn (Builder $q) => $q->whereIn('project_id', $this->searchMatches()));
    }

    /**
     * The primary key behind the ULID in the filter — as a subquery, so the
     * TenantScope on Project confines it to the bound MDA exactly as the outer
     * query is confined. A ULID from another workspace matches nothing rather
     * than resolving to a row and being filtered out later.
     *
     * @return Builder<Project>
     */
    private function filteredProject(): Builder
    {
        return Project::query()->where('ulid', $this->projectUlid)->select('id');
    }

    /** @return Builder<Project> */
    private function searchMatches(): Builder
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

        return Project::query()
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $term)
                ->orWhere('reference', 'like', $term))
            ->select('id');
    }

    /**
     * The summary row. Four figures in ONE round trip — this screen is the
     * officer's landing card and it is refreshed all morning.
     *
     * The tallies deliberately ignore the filter bar: they are the state of
     * the desk, not of the current search. A stat row that moves with the
     * filters cannot answer "am I behind?", which is the only question it is
     * there to answer.
     *
     * @return array{scheduled: int, under_way: int, awaiting_review: int, reports_overdue: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $now = Carbon::now();

        $row = SiteInspection::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as scheduled', [InspectionStatus::Scheduled->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as under_way', [InspectionStatus::InProgress->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as awaiting_review', [InspectionStatus::Submitted->value])
            ->selectRaw(
                'COUNT(CASE WHEN status = ? AND report_due_at IS NOT NULL AND report_due_at < ? THEN 1 END) as reports_overdue',
                [InspectionStatus::InProgress->value, $now],
            )
            ->first();

        return [
            'scheduled' => (int) ($row->scheduled ?? 0),
            'under_way' => (int) ($row->under_way ?? 0),
            'awaiting_review' => (int) ($row->awaiting_review ?? 0),
            'reports_overdue' => (int) ($row->reports_overdue ?? 0),
        ];
    }

    /**
     * Keyed by ULID, because that is what the filter and the URL carry.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return Project::query()
            ->visibleTo($user)
            ->orderBy('title')
            ->pluck('title', 'ulid')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(InspectionStatus::cases())
            ->mapWithKeys(fn (InspectionStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(InspectionType::cases())
            ->mapWithKeys(fn (InspectionType $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function outcomeOptions(): array
    {
        return collect(InspectionOutcome::cases())
            ->mapWithKeys(fn (InspectionOutcome $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return Collection<int, SiteInspection> */
    #[Computed]
    public function page(): Collection
    {
        /** @var Collection<int, SiteInspection> $items */
        $items = collect($this->inspections()->items());

        return $items;
    }

    /**
     * CSV of the list currently on screen, under exactly the filters in force.
     * Streamed in chunks so a ministry with 5,000 visits a year does not build
     * an array in memory.
     *
     * The authorization is repeated HERE and not merely inherited from
     * mount(): this is a network-callable method, a client can invoke it long
     * after the screen was opened, and the rows it writes go straight past the
     * view layer into a file someone forwards.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', SiteInspection::class);

        $query = $this->query()
            ->with(['project:id,title,reference', 'leadInspector:id,name'])
            ->withCount(['responses as findings_count' => fn (Builder $q) => $q->where('is_finding', true)])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');

        $filename = 'site-inspections-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it,
            // which mangles every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Project'), __('Reference'), __('Type'), __('Status'),
                __('Scheduled'), __('Conducted'), __('Lead inspector'),
                __('Observed progress %'), __('Outcome'), __('Findings'),
                __('Report filed late'), __('GPS outside site'),
            ]);

            $query->chunk(500, function (iterable $inspections) use ($handle): void {
                /** @var SiteInspection $inspection */
                foreach ($inspections as $inspection) {
                    fputcsv($handle, [
                        $inspection->project->title,
                        $inspection->project->reference,
                        $inspection->type->label(),
                        $inspection->status->label(),
                        // The state's wall clock, not UTC — the date the visit
                        // was actually made on.
                        InstanceTime::local($inspection->scheduled_date)->toDateString(),
                        $inspection->conducted_at === null ? null : InstanceTime::local($inspection->conducted_at)->toDateString(),
                        $inspection->leadInspector->name,
                        $inspection->physical_progress_observed,
                        $inspection->outcome?->label(),
                        $inspection->findings_count ?? 0,
                        $inspection->report_late ? __('Yes') : __('No'),
                        $inspection->geofence_breached ? __('Yes') : __('No'),
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
        return view('livewire.tenant.inspections.inspection-index');
    }
}
