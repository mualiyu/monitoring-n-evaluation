<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Enums\ProgressReportStatus;
use App\Enums\ReportObligationStatus;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\User;
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

/**
 * The MDA's reporting desk — what is owed, and what has been filed.
 *
 * TWO LISTS, ONE SCREEN, because they answer two different questions and an
 * officer asks both in the same minute: *what do we still owe* (obligations,
 * with a countdown) and *what did we file* (returns, with their chain status).
 * Splitting them across two routes would mean two filter bars to keep in sync
 * and a permanent argument about which one "/reports" should be.
 *
 * One code path for every role: `visibleTo()` narrows Consultant/FieldMonitor
 * to their assignments while MDA staff see the whole desk, so isolation is
 * proven once rather than per screen. The TenantScope confines everything to
 * the bound MDA before any of that runs.
 *
 * Reads only — every mutation routes through the wizard or the review screen.
 */
#[Layout('layouts::tenant')]
class ReportIndex extends Component
{
    use WithPagination;

    /** Which list is on top. Persisted, so a bookmarked inbox stays an inbox. */
    #[Url(except: 'obligations')]
    public string $view = 'obligations';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'project', except: '')]
    public string $projectId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ProgressReport::class);
    }

    public function updatedView(): void
    {
        // The two lists have different status vocabularies (`pending` means
        // nothing to a report, `reviewed` means nothing to an obligation), so
        // a status carried across the switch would silently filter everything
        // away and read as "no records".
        $this->status = '';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPeriodId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedProjectId(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'periodId', 'status', 'projectId']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->periodId !== ''
            || $this->status !== '' || $this->projectId !== '';
    }

    public function showingObligations(): bool
    {
        return $this->view !== 'reports';
    }

    /**
     * What this workspace still owes. Ordered by deadline: the whole point of
     * the list is "what is closest to being late".
     *
     * @return LengthAwarePaginator<int, ReportObligation>
     */
    #[Computed]
    public function obligations(): LengthAwarePaginator
    {
        return $this->obligationQuery()
            ->with([
                'project:id,ulid,title,reference,physical_progress',
                'reportingPeriod:id,code,label,cadence,due_at',
                // The row links straight to the filed return, so its public id
                // is part of the list — not a lazy load per row.
                'progressReport:id,ulid',
            ])
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate(25, pageName: 'obligationsPage');
    }

    /** @return Builder<ReportObligation> */
    private function obligationQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return ReportObligation::query()
            ->visibleTo($user)
            ->when($this->periodId !== '', fn (Builder $q) => $q->where('reporting_period_id', $this->periodId))
            ->when($this->projectId !== '', fn (Builder $q) => $q->where('project_id', $this->projectId))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereIn('project_id', $this->searchMatches()));
    }

    /**
     * What this workspace has filed. Newest first — a reporting desk is read
     * from the top.
     *
     * @return LengthAwarePaginator<int, ProgressReport>
     */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return ProgressReport::query()
            ->visibleTo($user)
            ->when($this->periodId !== '', fn (Builder $q) => $q->where('reporting_period_id', $this->periodId))
            ->when($this->projectId !== '', fn (Builder $q) => $q->where('project_id', $this->projectId))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereIn('project_id', $this->searchMatches()))
            ->with([
                'project:id,ulid,title,reference',
                'reportingPeriod:id,code,label,due_at',
                'submittedBy:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25, pageName: 'reportsPage');
    }

    /**
     * The ids of projects matching the search box — shared by both lists so a
     * term can never find an obligation but miss the return that answers it.
     *
     * A subquery rather than whereHas(): the TenantScope on Project confines it
     * to the bound MDA exactly as the outer query is confined, and both lists
     * hand it the same builder instead of duplicating the LIKE.
     *
     * @return Builder<Project>
     */
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
     * @return array{outstanding: int, due_soon: int, overdue: int, awaiting_action: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $now = Carbon::now();

        $obligations = ReportObligation::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as outstanding', [ReportObligationStatus::Pending->value])
            ->selectRaw(
                'COUNT(CASE WHEN status = ? AND due_at >= ? AND due_at <= ? THEN 1 END) as due_soon',
                [ReportObligationStatus::Pending->value, $now, $now->copy()->addDays(7)],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status = ? AND due_at < ? THEN 1 END) as overdue',
                [ReportObligationStatus::Pending->value, $now],
            )
            ->first();

        return [
            'outstanding' => (int) ($obligations->outstanding ?? 0),
            'due_soon' => (int) ($obligations->due_soon ?? 0),
            'overdue' => (int) ($obligations->overdue ?? 0),
            'awaiting_action' => $this->awaitingActionCount(),
        ];
    }

    /**
     * Returns waiting on THIS user specifically: submitted returns for someone
     * who reviews, reviewed returns for someone who approves. A reviewer and a
     * director open the same screen and see their own queue.
     */
    private function awaitingActionCount(): int
    {
        /** @var User $user */
        $user = auth()->user();

        $states = [];

        if ($user->can('reports.review')) {
            $states[] = ProgressReportStatus::Submitted->value;
        }

        if ($user->can('reports.approve')) {
            $states[] = ProgressReportStatus::Reviewed->value;
        }

        if ($states === []) {
            // An author's queue is their own work coming back.
            $states[] = ProgressReportStatus::Returned->value;
        }

        return ProgressReport::query()
            ->visibleTo($user)
            ->whereIn('status', $states)
            ->count();
    }

    /**
     * Windows this workspace actually has obligations or returns in — a
     * filter option that returns nothing is a dead end, and the full statutory
     * calendar is 19 rows a year.
     *
     * @return Collection<int, ReportingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return ReportingPeriod::query()
            ->whereIn('id', ReportObligation::query()->visibleTo($user)->select('reporting_period_id'))
            ->orWhereIn('id', ProgressReport::query()->visibleTo($user)->select('reporting_period_id'))
            ->orderByDesc('period_start')
            ->get(['id', 'code', 'label', 'due_at']);
    }

    /** @return array<int, string> */
    #[Computed]
    public function projectOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return Project::query()
            ->visibleTo($user)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        if ($this->showingObligations()) {
            return collect(ReportObligationStatus::cases())
                ->mapWithKeys(fn (ReportObligationStatus $case) => [$case->value => $case->label()])
                ->all();
        }

        return collect(ProgressReportStatus::cases())
            ->mapWithKeys(fn (ProgressReportStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.tenant.reporting.report-index');
    }
}
