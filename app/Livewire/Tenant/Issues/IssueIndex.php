<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\InstanceTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The challenges register (plan §4): everything standing between this MDA's
 * projects and their delivery, and who owns clearing each one.
 *
 * ONE CODE PATH FOR EVERY ROLE: `visibleTo()` narrows Consultant/FieldMonitor
 * to their assignments while MDA staff see the whole register, so isolation is
 * proven once rather than per screen. The TenantScope confines everything to
 * the bound MDA before any of that runs.
 *
 * Reads only — every mutation routes through the detail screen, where the
 * ledger and the corrective action are in view.
 */
#[Layout('layouts::tenant')]
class IssueIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $category = '';

    /**
     * The project filter, keyed by ULID rather than by primary key. A bookmark
     * or a pasted link carries this value, and an auto-increment id in a URL
     * both enumerates another MDA's volumes and invites a guess at a row that
     * is not yours (rules/tenancy.md). Nothing leaks either way, because the
     * lookup runs inside the TenantScope; this is the difference between
     * "refused" and "not addressable".
     */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    /** Past its corrective-action deadline and still open. */
    #[Url(except: false)]
    public bool $overdue = false;

    /**
     * Open issues only. Defaults ON: a register that opens showing three years
     * of closed items answers "what have we ever recorded", which nobody asks,
     * instead of "what is blocking us now", which is the only reason anyone
     * opens this screen.
     */
    #[Url(as: 'live', except: true)]
    public bool $openOnly = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Issue::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        // A status filter and "open only" contradict each other the moment
        // somebody picks `closed`: the list would come back empty and read as
        // "nothing closed", which is the wrong answer to a question they just
        // asked explicitly.
        if ($this->status !== '' && ! IssueStatus::from($this->status)->isOpen()) {
            $this->openOnly = false;
        }

        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedProjectUlid(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function updatedOpenOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'severity', 'category', 'projectUlid', 'overdue']);
        $this->openOnly = true;
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->severity !== ''
            || $this->category !== '' || $this->projectUlid !== '' || $this->overdue
            || ! $this->openOnly;
    }

    /**
     * The register itself. Worst first, then soonest deadline: severity is the
     * question a director asks and the due date is the question the owner
     * asks, and this order answers both without two screens.
     *
     * @return LengthAwarePaginator<int, Issue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return $this->query()
            ->with([
                'project:id,ulid,title,reference',
                'owner:id,name',
                'raisedBy:id,name',
            ])
            ->orderByRaw($this->severityOrdering(), $this->severityBindings())
            // Nulls last: an issue with no deadline is not the most urgent
            // thing on the list, which is what an ascending sort would make it
            // on every database that orders NULL first.
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Builder<Issue> */
    private function query(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return Issue::query()
            ->visibleTo($user)
            ->when($this->openOnly, fn (Builder $q) => $q->open())
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->severity !== '', fn (Builder $q) => $q->where('severity', $this->severity))
            ->when($this->category !== '', fn (Builder $q) => $q->where('category', $this->category))
            ->when($this->projectUlid !== '', fn (Builder $q) => $q->whereIn('project_id', $this->filteredProject()))
            ->when($this->overdue, fn (Builder $q) => $q
                ->open()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', InstanceTime::now()->toDateString()))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('title', 'like', $this->term())
                ->orWhere('description', 'like', $this->term())
                ->orWhereIn('project_id', $this->searchMatches())));
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

    /** @return Builder<Project> */
    private function searchMatches(): Builder
    {
        return Project::query()
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $this->term())
                ->orWhere('reference', 'like', $this->term()))
            ->select('id');
    }

    private function term(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
    }

    /**
     * Worst severity first. A raw CASE over the enum's own ranking rather than
     * an alphabetical sort: `critical` happens to sort before `high`, but
     * `low` sorts before `medium` and would put the least urgent rows on top.
     * The values are enum cases, never user input.
     */
    private function severityOrdering(): string
    {
        // Bound parameters, not interpolation. The values are enum cases and
        // could never be injected — but "it happens to be safe today" is how a
        // raw string survives until somebody makes it take a filter value. The
        // sibling ExceptionReport::scopeWorstFirst does it this way; so does
        // this.
        $whens = str_repeat('WHEN ? THEN ? ', count(IssueSeverity::cases()));

        return 'CASE severity '.$whens.'ELSE 0 END';
    }

    /**
     * @return list<string|int>
     */
    private function severityBindings(): array
    {
        $bindings = [];

        foreach (IssueSeverity::cases() as $severity) {
            $bindings[] = $severity->value;
            $bindings[] = -$severity->weight();
        }

        return $bindings;
    }

    /**
     * The summary row. Four figures the register is judged on, in ONE round
     * trip.
     *
     * The tallies deliberately ignore the filter bar: they are the state of
     * the register, not of the current search. A stat row that moves with the
     * filters cannot answer "are we on top of this?", which is the only
     * question it is there to answer.
     *
     * @return array{open: int, critical: int, overdue: int, unowned: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $openStates = [];

        foreach (IssueStatus::cases() as $case) {
            if ($case->isOpen()) {
                $openStates[] = $case->value;
            }
        }

        $placeholders = implode(',', array_fill(0, count($openStates), '?'));
        $today = InstanceTime::now()->toDateString();

        $row = Issue::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw("COUNT(CASE WHEN status IN ({$placeholders}) THEN 1 END) as open_total", $openStates)
            ->selectRaw(
                "COUNT(CASE WHEN status IN ({$placeholders}) AND severity = ? THEN 1 END) as critical_total",
                [...$openStates, IssueSeverity::Critical->value],
            )
            ->selectRaw(
                "COUNT(CASE WHEN status IN ({$placeholders}) AND due_date IS NOT NULL AND due_date < ? THEN 1 END) as overdue_total",
                [...$openStates, $today],
            )
            ->selectRaw(
                "COUNT(CASE WHEN status IN ({$placeholders}) AND owner_id IS NULL THEN 1 END) as unowned_total",
                $openStates,
            )
            ->whereNull('deleted_at')
            ->first();

        return [
            'open' => (int) ($row->open_total ?? 0),
            'critical' => (int) ($row->critical_total ?? 0),
            'overdue' => (int) ($row->overdue_total ?? 0),
            'unowned' => (int) ($row->unowned_total ?? 0),
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
    public function statusOptions(): array
    {
        return IssueStatus::options();
    }

    /** @return array<string, string> */
    public function severityOptions(): array
    {
        return IssueSeverity::options();
    }

    /** @return array<string, string> */
    public function categoryOptions(): array
    {
        return IssueCategory::options();
    }

    /**
     * CSV of the register under exactly the filters in force. Streamed in
     * chunks so a ministry with 5,000 issues does not build an array in
     * memory.
     *
     * The authorization is repeated HERE and not merely inherited from
     * mount(): this is a network-callable method, a client can invoke it long
     * after the screen was opened, and the rows it writes go straight past the
     * view layer into a file someone forwards. It runs the list's own builder,
     * so a row can never be missing from the screen and present in the export.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Issue::class);

        $query = $this->query()
            ->with(['project:id,title,reference', 'owner:id,name', 'raisedBy:id,name'])
            ->orderByRaw($this->severityOrdering(), $this->severityBindings())
            ->orderBy('due_date')
            ->orderByDesc('id');

        $filename = 'issues-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it,
            // which mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Project'), __('Reference'), __('Title'), __('Category'),
                __('Severity'), __('Status'), __('Owner'), __('Raised by'),
                __('Raised on'), __('Due'), __('Overdue'), __('Corrective action'),
                __('Resolution'),
            ]);

            $query->chunk(500, function (iterable $issues) use ($handle): void {
                /** @var Issue $issue */
                foreach ($issues as $issue) {
                    fputcsv($handle, [
                        $issue->project->title,
                        $issue->project->reference,
                        $issue->title,
                        $issue->category->label(),
                        $issue->severity->label(),
                        $issue->status->label(),
                        $issue->owner?->name,
                        $issue->raisedBy?->name,
                        // The state's wall clock, not UTC.
                        InstanceTime::local($issue->created_at)->toDateString(),
                        $issue->due_date?->toDateString(),
                        $issue->isOverdue() ? __('Yes') : __('No'),
                        $issue->corrective_action,
                        $issue->resolution_note,
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
        return view('livewire.tenant.issues.issue-index');
    }
}
