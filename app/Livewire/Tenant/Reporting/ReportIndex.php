<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Actions\Reporting\WaiveReportObligation;
use App\Enums\ProgressReportStatus;
use App\Enums\ReportObligationStatus;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
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

    /**
     * The project filter, keyed by ULID rather than by primary key. A bookmark
     * or a pasted link carries this value, and an auto-increment id in a URL
     * both enumerates another MDA's volumes and invites a guess at a row that
     * is not yours — which is why every tenant-owned model is addressed by its
     * public id (rules/tenancy.md). Nothing leaks either way, because the
     * lookup runs inside the TenantScope; this is the difference between
     * "refused" and "not addressable".
     */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    /** The obligation a waiver is being written for, and its reason. */
    public ?int $waivingId = null;

    public string $waiverReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

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

    public function updatedProjectUlid(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'periodId', 'status', 'projectUlid']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->periodId !== ''
            || $this->status !== '' || $this->projectUlid !== '';
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
            ->when($this->projectUlid !== '', fn (Builder $q) => $q->whereIn('project_id', $this->filteredProject()))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereIn('project_id', $this->searchMatches()));
    }

    /**
     * The primary key behind the ULID in the filter — as a subquery, so the
     * TenantScope on Project confines it to the bound MDA exactly as the outer
     * query is confined. A ULID from another workspace matches nothing rather
     * than resolving to a row and then being filtered out somewhere later.
     *
     * @return Builder<Project>
     */
    private function filteredProject(): Builder
    {
        return Project::query()->where('ulid', $this->projectUlid)->select('id');
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
        return $this->reportQuery()
            ->with([
                'project:id,ulid,title,reference',
                'reportingPeriod:id,code,label,due_at',
                'submittedBy:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25, pageName: 'reportsPage');
    }

    /** @return Builder<ProgressReport> */
    private function reportQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return ProgressReport::query()
            ->visibleTo($user)
            ->when($this->periodId !== '', fn (Builder $q) => $q->where('reporting_period_id', $this->periodId))
            ->when($this->projectUlid !== '', fn (Builder $q) => $q->whereIn('project_id', $this->filteredProject()))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->whereIn('project_id', $this->searchMatches()));
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
        if ($this->showingObligations()) {
            return collect(ReportObligationStatus::cases())
                ->mapWithKeys(fn (ReportObligationStatus $case) => [$case->value => $case->label()])
                ->all();
        }

        return collect(ProgressReportStatus::cases())
            ->mapWithKeys(fn (ProgressReportStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /* ---------------------------------------------------------------- */
    /* Waiving an obligation */
    /* ---------------------------------------------------------------- */

    /**
     * The obligation a waiver names, resolved through `visibleTo()` — the same
     * narrowing the list runs. An id outside this workspace (or outside a
     * consultant's assignments) is a 404, not a refusal that confirms the row
     * exists somewhere.
     */
    private function waivableObligation(int $id): ReportObligation
    {
        /** @var User $user */
        $user = auth()->user();

        return ReportObligation::query()->visibleTo($user)->findOrFail($id);
    }

    public function startWaive(int $obligationId): void
    {
        $this->authorize('waive', $this->waivableObligation($obligationId));

        $this->resetErrorBag();
        $this->failure = null;
        $this->waiverReason = '';
        $this->waivingId = $obligationId;

        $this->dispatch('open-modal', 'waive-obligation');
    }

    public function cancelWaive(): void
    {
        $this->waivingId = null;
        $this->waiverReason = '';
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'waive-obligation');
    }

    /**
     * Excusing a window, on the record. The reason is not a formality: it is
     * the only thing standing between "the site was under water all month" and
     * an MDA quietly deleting its own black marks, and it is what an auditor
     * reads afterwards. Authority (`reports.waive`), the outstanding-status
     * check and the reason are all re-asserted by WaiveReportObligation — this
     * screen only stops a doomed round trip and surfaces the refusal.
     */
    public function confirmWaive(WaiveReportObligation $waive): void
    {
        $obligation = $this->waivableObligation((int) $this->waivingId);

        $this->authorize('waive', $obligation);

        $this->validate([
            'waiverReason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'waiverReason.required' => __('Say why this window cannot be reported on. A waiver with no reason is an unexplained gap in the compliance record.'),
            'waiverReason.min' => __('Give the auditor something to read — a few words at least.'),
        ], [
            'waiverReason' => __('reason'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $waive($obligation, $actor, $this->waiverReason);
        } catch (ReportRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'waive-obligation');

            return;
        }

        $this->waivingId = null;
        $this->waiverReason = '';

        unset($this->obligations, $this->stats);
        $this->dispatch('close-modal', 'waive-obligation');

        session()->flash('status', __('Obligation waived. It no longer counts against this entity on the state compliance board.'));
    }

    /* ---------------------------------------------------------------- */
    /* Export */
    /* ---------------------------------------------------------------- */

    /**
     * CSV of the list currently on screen — the obligations desk or the filed
     * returns, under exactly the filters in force. Streamed in chunks so a
     * ministry with 5,000 obligations a year does not build an array in memory.
     *
     * The authorization is repeated HERE and not merely inherited from mount():
     * this is a network-callable method, a client can invoke it long after the
     * screen was opened, and the rows it writes go straight past the view layer
     * into a file someone forwards. Both queries are the list's own builders,
     * so a row can never be missing from the screen and present in the export.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', ProgressReport::class);

        return $this->showingObligations() ? $this->exportObligations() : $this->exportReports();
    }

    private function exportObligations(): StreamedResponse
    {
        $query = $this->obligationQuery()
            ->with(['project:id,title,reference', 'reportingPeriod:id,label,cadence,due_at'])
            ->orderBy('due_at')
            ->orderBy('id');

        return $this->streamCsv('report-obligations', [
            __('Project'), __('Reference'), __('Window'), __('Cadence'),
            __('Deadline'), __('Status'), __('Filed on'), __('Filed late'),
            __('Waiver reason'),
        ], function ($handle) use ($query): void {
            $query->chunk(500, function (iterable $obligations) use ($handle): void {
                /** @var ReportObligation $obligation */
                foreach ($obligations as $obligation) {
                    // An obligation with no project is an MDA-level one (Phase 2
                    // consolidation); the row has to stand without a project name.
                    $project = $obligation->project;

                    fputcsv($handle, [
                        $project === null ? __('Entity-level return') : $project->title,
                        $project?->reference,
                        $obligation->reportingPeriod->label,
                        $obligation->reportingPeriod->cadence->label(),
                        // The state's wall clock, not UTC — the deadline the
                        // MDA was actually given.
                        InstanceTime::local($obligation->due_at)->toDateString(),
                        $obligation->status->label(),
                        $obligation->fulfilled_at === null ? null : InstanceTime::local($obligation->fulfilled_at)->toDateString(),
                        $obligation->submitted_late ? __('Yes') : __('No'),
                        $obligation->waiver_reason,
                    ]);
                }
            });
        });
    }

    private function exportReports(): StreamedResponse
    {
        $query = $this->reportQuery()
            ->with(['project:id,title,reference', 'reportingPeriod:id,label', 'submittedBy:id,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        return $this->streamCsv('progress-reports', [
            __('Project'), __('Reference'), __('Window'), __('Status'),
            __('Progress claimed %'), __('Period spend'), __('Deadline'),
            __('Filed on'), __('Filed by'), __('Filed late'),
        ], function ($handle) use ($query): void {
            $query->chunk(500, function (iterable $reports) use ($handle): void {
                /** @var ProgressReport $report */
                foreach ($reports as $report) {
                    fputcsv($handle, [
                        $report->project->title,
                        $report->project->reference,
                        $report->reportingPeriod->label,
                        $report->status->label(),
                        $report->physical_progress_claimed,
                        $report->period_expenditure->toDecimalString(),
                        InstanceTime::local($report->due_at)->toDateString(),
                        $report->submitted_at === null ? null : InstanceTime::local($report->submitted_at)->toDateString(),
                        $report->submittedBy?->name,
                        $report->submitted_late ? __('Yes') : __('No'),
                    ]);
                }
            });
        });
    }

    /**
     * @param  list<string>  $headings
     * @param  callable(resource): void  $rows
     */
    private function streamCsv(string $name, array $headings, callable $rows): StreamedResponse
    {
        $filename = $name.'-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($headings, $rows): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it, which
            // mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headings);
            $rows($handle);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        return view('livewire.tenant.reporting.report-index');
    }
}
