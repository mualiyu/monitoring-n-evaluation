<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Workplans;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;
use App\Support\Money;
use App\Support\WorkplanProgress;
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
 * The MDA's work-plan register: every year's Annual Work Plan & Budget, where
 * each one stands in the approval chain, and how it is tracking.
 *
 * Reads only — every mutation routes through an Action from the builder
 * screen. `visibleTo()` narrows field roles to the plans they own a line in,
 * so isolation is proven once rather than per screen; the TenantScope confines
 * the query to the bound MDA before any of that runs.
 *
 * The roll-up comes from App\Support\WorkplanProgress over an eager-loaded
 * `activities` relation, NOT from a second formula in SQL: 25 plans' lines is
 * one extra query, and a duplicated weighting rule is a number that silently
 * disagrees with the builder screen.
 */
#[Layout('layouts::tenant')]
class WorkplanIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $year = '';

    /** Plans carrying at least one activity with no output indicator. */
    #[Url(except: false)]
    public bool $unlinked = false;

    #[Url(except: 'year')]
    public string $sort = 'year';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    /** Columns a user may sort by — never interpolate raw input into SQL. */
    private const SORTABLE = ['title', 'year', 'status', 'period_start', 'period_end'];

    public function mount(): void
    {
        $this->authorize('viewAny', Workplan::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedUnlinked(): void
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
        $this->reset(['search', 'status', 'year', 'unlinked']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->year !== '' || $this->unlinked;
    }

    /**
     * Everything the list, the stat row and the export share. Kept as one
     * builder so a plan can never appear in the table but be missed by the
     * export, or counted in a stat the filter excluded.
     *
     * @return Builder<Workplan>
     */
    private function baseQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return Workplan::query()
            ->visibleTo($user)
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where('title', 'like', $term);
            })
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->year !== '', fn (Builder $q) => $q->where('year', (int) $this->year))
            ->when($this->unlinked, fn (Builder $q) => $q->whereHas(
                'activities',
                fn (Builder $activity) => $activity->whereNull('indicator_id'),
            ));
    }

    /** @return LengthAwarePaginator<int, Workplan> */
    #[Computed]
    public function workplans(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->with(['owner:id,name', 'activities'])
            ->orderBy($this->sortColumn(), $this->direction === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(25);
    }

    private function sortColumn(): string
    {
        return in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'year';
    }

    /**
     * The roll-up for one plan, memoised per request so the table cell, the
     * warning badge and the export all read the same computation once.
     *
     * @return array<string, mixed>
     */
    public function summary(Workplan $workplan): array
    {
        return WorkplanProgress::summarise($workplan->activities);
    }

    /**
     * Summary row. Counted in PHP over the SAME page-independent query the
     * table uses, because the headline figures (progress, unlinked lines) are
     * roll-ups whose definition lives in WorkplanProgress — expressing them a
     * second time as SQL is how two screens start disagreeing.
     *
     * @return array{count: int, live: int, awaiting: int, unlinked: int, budget: string}
     */
    #[Computed]
    public function stats(): array
    {
        $plans = $this->baseQuery()->with('activities')->get();

        $unlinked = 0;
        $budget = Money::zero();

        foreach ($plans as $plan) {
            $summary = WorkplanProgress::summarise($plan->activities);
            $unlinked += $summary['unlinked'];
            $budget = $budget->plus($summary['budget']);
        }

        return [
            'count' => $plans->count(),
            'live' => $plans->filter(fn (Workplan $p): bool => $p->status->isApproved()
                && $p->status !== WorkplanStatus::Closed)->count(),
            'awaiting' => $plans->filter(fn (Workplan $p): bool => $p->status->isAwaitingDecision())->count(),
            'unlinked' => $unlinked,
            'budget' => $budget->toDecimalString(),
        ];
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        $options = [];

        foreach (WorkplanStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Only the years this workspace actually has plans for — an option that
     * returns nothing is a dead end.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function yearOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $options = [];

        foreach (Workplan::query()->visibleTo($user)->distinct()->orderByDesc('year')->pluck('year') as $year) {
            $options[(int) $year] = (string) $year;
        }

        return $options;
    }

    /**
     * CSV of the current filter set — the figures on screen, nothing else.
     * One row per PLAN, with the derived roll-up; the activity-level export
     * lives on the builder screen, where the activities are.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Workplan::class);

        $filename = 'workplans-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        $query = $this->baseQuery()
            ->with(['owner:id,name', 'activities'])
            ->orderBy($this->sortColumn(), $this->direction === 'desc' ? 'desc' : 'asc');

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it,
            // which mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Title'), __('Year'), __('Period start'), __('Period end'), __('Owner'),
                __('Status'), __('Activities'), __('Without output indicator'),
                __('Progress %'), __('Budget'), __('Expenditure'),
            ]);

            $query->chunk(200, function (iterable $plans) use ($handle): void {
                /** @var Workplan $plan */
                foreach ($plans as $plan) {
                    $summary = WorkplanProgress::summarise($plan->activities);

                    fputcsv($handle, [
                        $plan->title,
                        $plan->yearLabel(),
                        $plan->period_start->toDateString(),
                        $plan->period_end->toDateString(),
                        $plan->owner?->name,
                        $plan->status->label(),
                        $summary['activities'],
                        $summary['unlinked'],
                        $summary['progress'] ?? '',
                        $summary['budget']->toDecimalString(),
                        $summary['expenditure']->toDecimalString(),
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
        return view('livewire.tenant.workplans.workplan-index');
    }
}
