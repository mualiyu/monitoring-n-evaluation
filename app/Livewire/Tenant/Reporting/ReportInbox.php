<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The inbox (progress-reporting.md §6): returns waiting on THIS user, and
 * nothing else. The officer's and the director's landing card.
 *
 * WHY A SEPARATE SCREEN FROM /reports. The desk answers "what does this
 * workspace owe and what has it filed" — a register. This answers "what is
 * waiting for me" — a queue. They are read at different moments by different
 * people, and a filter on a register is not a queue: it cannot express
 * "submitted by someone else, and not already reviewed by me", which is the
 * whole content of the question.
 *
 * SEPARATION OF DUTIES IS IN THE QUERY, not only in the buttons. A return this
 * user filed never appears as theirs to clear, and when
 * `require_separate_approver` is on, one they reviewed never appears as theirs
 * to approve. TransitionProgressReportStatus enforces both regardless — this
 * screen simply never offers work the chain would refuse, because an inbox
 * whose items bounce is worse than an empty one.
 *
 * Read-only: every decision is taken on the review screen, which is the one
 * place the chain is written.
 */
#[Layout('layouts::tenant')]
class ReportInbox extends Component
{
    use WithPagination;

    /** Which queue is on screen: '' (everything mine), review, approve, returned. */
    #[Url(except: '')]
    public string $queue = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    /**
     * Keyed by ULID, never by primary key: this value is bookmarked and
     * pasted, and an auto-increment id in a URL both enumerates a workspace's
     * volumes and invites a guess at a neighbouring row.
     */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ProgressReport::class);
    }

    public function updatedQueue(): void
    {
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

    public function updatedProjectUlid(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['queue', 'search', 'periodId', 'projectUlid']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->queue !== '' || $this->search !== ''
            || $this->periodId !== '' || $this->projectUlid !== '';
    }

    /* ---------------------------------------------------------------- */
    /* What this user's queues are */
    /* ---------------------------------------------------------------- */

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function canReviewQueue(): bool
    {
        return $this->user()->can('reports.review');
    }

    public function canApproveQueue(): bool
    {
        return $this->user()->can('reports.approve');
    }

    /** Whether approval must go to someone other than the reviewer. */
    public function separateApproverRequired(): bool
    {
        return app(SettingsRepository::class)->bool('reporting', 'require_separate_approver', true);
    }

    /**
     * The queues this user actually has. An option that can only ever be
     * empty is a dead end, so a consultant is never offered "awaiting my
     * approval".
     *
     * @return array<string, string>
     */
    #[Computed]
    public function queueOptions(): array
    {
        $options = [];

        if ($this->canReviewQueue()) {
            $options['review'] = __('Awaiting my review');
        }

        if ($this->canApproveQueue()) {
            $options['approve'] = __('Awaiting my approval');
        }

        // Always present: everyone who may file a return may have one come
        // back, and "returned to me" is the author's half of the same inbox.
        $options['returned'] = __('Returned to me for correction');

        return $options;
    }

    /* ---------------------------------------------------------------- */
    /* The queue */
    /* ---------------------------------------------------------------- */

    /**
     * @return LengthAwarePaginator<int, ProgressReport>
     */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        return $this->inboxQuery($this->queue)
            ->with([
                'project:id,ulid,title,reference,physical_progress',
                'reportingPeriod:id,code,label,due_at',
                'submittedBy:id,name',
                'reviewedBy:id,name',
            ])
            // Oldest first — a queue is worked from the bottom of the pile,
            // and the return that has waited longest is the one at risk.
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * The queue, as a query. `$only` restricts it to one branch ('' unions
     * every branch this user has); `$filtered` is false for the summary row,
     * which counts the queue itself rather than the current search.
     *
     * @return Builder<ProgressReport>
     */
    private function inboxQuery(string $only = '', bool $filtered = true): Builder
    {
        $user = $this->user();

        return ProgressReport::query()
            ->visibleTo($user)
            ->where(function (Builder $queue) use ($user, $only): void {
                if ($this->canReviewQueue() && ($only === '' || $only === 'review')) {
                    $queue->orWhere(fn (Builder $branch) => $branch
                        ->where('status', ProgressReportStatus::Submitted)
                        ->where(fn (Builder $other) => $other
                            ->whereNull('submitted_by_id')
                            ->orWhere('submitted_by_id', '!=', $user->id)));
                }

                if ($this->canApproveQueue() && ($only === '' || $only === 'approve')) {
                    $queue->orWhere(function (Builder $branch) use ($user): void {
                        $branch->where('status', ProgressReportStatus::Reviewed)
                            ->where(fn (Builder $other) => $other
                                ->whereNull('submitted_by_id')
                                ->orWhere('submitted_by_id', '!=', $user->id));

                        if ($this->separateApproverRequired()) {
                            $branch->where(fn (Builder $other) => $other
                                ->whereNull('reviewed_by_id')
                                ->orWhere('reviewed_by_id', '!=', $user->id));
                        }
                    });
                }

                if ($only === '' || $only === 'returned') {
                    $queue->orWhere(fn (Builder $branch) => $branch
                        ->where('status', ProgressReportStatus::Returned)
                        ->where('created_by_id', $user->id));
                }

                // A user with no queue at all (an unassigned viewer asking for
                // a branch they do not hold) must match NOTHING, not
                // everything: an empty where-group would otherwise collapse to
                // "all returns in this workspace".
                $queue->orWhereRaw('1 = 0');
            })
            ->when($filtered && $this->periodId !== '', fn (Builder $q) => $q->where('reporting_period_id', $this->periodId))
            ->when($filtered && $this->projectUlid !== '', fn (Builder $q) => $q->whereIn('project_id', $this->filteredProject()))
            ->when($filtered && $this->search !== '', fn (Builder $q) => $q->whereIn('project_id', $this->searchMatches()));
    }

    /**
     * The primary key behind the ULID in the filter, as a subquery — the
     * TenantScope on Project confines it to the bound workspace exactly as the
     * outer query is confined, so a foreign ULID matches nothing rather than
     * resolving and being filtered out later.
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
     * The counts on the summary row — deliberately UNFILTERED, because they
     * answer "how much is waiting on me", not "how much matches this search".
     *
     * @return array{total: int, review: int, approve: int, returned: int, overdue: int}
     */
    #[Computed]
    public function stats(): array
    {
        $unfiltered = fn (string $branch): Builder => $this->inboxQuery($branch, filtered: false);

        return [
            'total' => $unfiltered('')->count(),
            'review' => $this->canReviewQueue() ? $unfiltered('review')->count() : 0,
            'approve' => $this->canApproveQueue() ? $unfiltered('approve')->count() : 0,
            'returned' => $unfiltered('returned')->count(),
            // Waiting on a decision AND already past the window's deadline:
            // the chain, not the author, is now what is making it late.
            'overdue' => $unfiltered('')->where('due_at', '<', now())->count(),
        ];
    }

    /**
     * Windows this workspace has returns in — the full statutory calendar
     * would offer options that match nothing.
     *
     * @return Collection<int, ReportingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return ReportingPeriod::query()
            ->whereIn('id', ProgressReport::query()->visibleTo($this->user())->select('reporting_period_id'))
            ->orderByDesc('period_start')
            ->get(['id', 'code', 'label', 'due_at']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function projectOptions(): array
    {
        return Project::query()
            ->visibleTo($this->user())
            ->orderBy('title')
            ->pluck('title', 'ulid')
            ->all();
    }

    /**
     * What this user is being asked to do with a given return — the label on
     * the row's button, and the reason it is in the list at all.
     */
    public function actionFor(ProgressReport $report): string
    {
        return match (true) {
            $report->status === ProgressReportStatus::Submitted => __('Review'),
            $report->status === ProgressReportStatus::Reviewed => __('Approve'),
            default => __('Correct and refile'),
        };
    }

    public function render(): View
    {
        return view('livewire.tenant.reporting.report-inbox');
    }
}
