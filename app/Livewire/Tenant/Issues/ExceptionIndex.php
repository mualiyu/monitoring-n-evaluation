<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Issues;

use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Models\ExceptionReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The MDA's deviation board (manual Table 5.2 — the Exception Report).
 *
 * Almost every row here was written by the threshold engine rather than by a
 * person, which is the point: a deviation that only gets reported when
 * somebody notices it gets reported late, or never. The screen's job is to
 * make the machine's judgements answerable — each row carries the measurement
 * and the tolerance it tripped, so the first response can be an explanation
 * rather than an argument about whether it is real.
 *
 * Reads only — acknowledging and resolving happen on the detail screen, where
 * the measurement is in view.
 */
#[Layout('layouts::tenant')]
class ExceptionIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $trigger = '';

    #[Url(except: '')]
    public string $severity = '';

    /** The project filter, keyed by ULID rather than by primary key. */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    /**
     * Live deviations only. Defaults ON for the same reason the register
     * defaults to open issues: the question people open this screen to ask is
     * "what is off-track now".
     */
    #[Url(as: 'live', except: true)]
    public bool $liveOnly = true;

    public function mount(): void
    {
        $this->authorize('viewAny', ExceptionReport::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if ($this->status !== '' && ! ExceptionStatus::from($this->status)->isLive()) {
            $this->liveOnly = false;
        }

        $this->resetPage();
    }

    public function updatedTrigger(): void
    {
        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedProjectUlid(): void
    {
        $this->resetPage();
    }

    public function updatedLiveOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'trigger', 'severity', 'projectUlid']);
        $this->liveOnly = true;
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->trigger !== ''
            || $this->severity !== '' || $this->projectUlid !== '' || ! $this->liveOnly;
    }

    /**
     * @return LengthAwarePaginator<int, ExceptionReport>
     */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        return $this->query()
            ->with([
                'project:id,ulid,title,reference',
                'issue:id,ulid,title,status',
            ])
            ->worstFirst()
            ->paginate(25);
    }

    /** @return Builder<ExceptionReport> */
    private function query(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return ExceptionReport::query()
            ->visibleTo($user)
            ->when($this->liveOnly, fn (Builder $q) => $q->live())
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->trigger !== '', fn (Builder $q) => $q->where('trigger', $this->trigger))
            ->when($this->severity !== '', fn (Builder $q) => $q->where('severity', $this->severity))
            ->when($this->projectUlid !== '', fn (Builder $q) => $q->whereIn(
                'project_id',
                Project::query()->where('ulid', $this->projectUlid)->select('id'),
            ))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('narrative', 'like', $this->term())
                ->orWhereIn('project_id', $this->searchMatches())));
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
     * The state of the board, deliberately NOT filtered — see the note on
     * IssueIndex::stats().
     *
     * @return array{live: int, critical: int, automatic: int, unanswered: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $live = [];

        foreach (ExceptionStatus::cases() as $case) {
            if ($case->isLive()) {
                $live[] = $case->value;
            }
        }

        $placeholders = implode(',', array_fill(0, count($live), '?'));

        $row = ExceptionReport::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw("COUNT(CASE WHEN status IN ({$placeholders}) THEN 1 END) as live_total", $live)
            ->selectRaw(
                "COUNT(CASE WHEN status IN ({$placeholders}) AND severity = ? THEN 1 END) as critical_total",
                [...$live, IssueSeverity::Critical->value],
            )
            ->selectRaw(
                "COUNT(CASE WHEN status IN ({$placeholders}) AND raised_by_id IS NULL THEN 1 END) as automatic_total",
                $live,
            )
            // Raised and never even acknowledged — the figure that says
            // whether this board is being read at all.
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as unanswered_total', [ExceptionStatus::Open->value])
            ->whereNull('deleted_at')
            ->first();

        return [
            'live' => (int) ($row->live_total ?? 0),
            'critical' => (int) ($row->critical_total ?? 0),
            'automatic' => (int) ($row->automatic_total ?? 0),
            'unanswered' => (int) ($row->unanswered_total ?? 0),
        ];
    }

    /** @return array<string, string> */
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
        return ExceptionStatus::options();
    }

    /** @return array<string, string> */
    public function triggerOptions(): array
    {
        return ExceptionTrigger::options();
    }

    /** @return array<string, string> */
    public function severityOptions(): array
    {
        return IssueSeverity::options();
    }

    public function render(): View
    {
        return view('livewire.tenant.issues.exception-index');
    }
}
