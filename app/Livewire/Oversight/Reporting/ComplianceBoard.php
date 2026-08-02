<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Reporting;

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The rewards-and-sanctions board (progress-reporting.md §3.1): every MDA for
 * one reporting window, ranked by how much of it they filed on time.
 *
 * The whole table comes from ONE grouped query inside
 * BuildComplianceLeagueTable — which is also where the cross-tenant read is
 * authorized and where the `withoutTenancy()` bypass lives. This component
 * never bypasses tenancy itself: the bypass belongs with the authorization
 * check that justifies it, in one place.
 *
 * Sorting happens in PHP, deliberately. The Action returns one row per MDA —
 * forty rows on the largest state deployment — and re-running a cached
 * aggregate to reorder forty rows would trade a free operation for a database
 * round trip.
 */
#[Layout('layouts::oversight')]
class ComplianceBoard extends Component
{
    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    #[Url(except: 'on_time_rate')]
    public string $sort = 'on_time_rate';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    /** Columns the board may be ranked by. */
    private const SORTABLE = ['name', 'expected', 'submitted', 'on_time', 'missed', 'compliance_rate', 'on_time_rate'];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.compliance.view'), 403);
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $column;
        // Names read naturally A→Z; every other column is a score, and the
        // interesting end of a score is the top of it.
        $this->direction = $column === 'name' ? 'asc' : 'desc';
    }

    /**
     * The windows worth looking at: the current one and the ones before it.
     * Ordered newest-first so `first()` is "now" and `get(1)` is "last time" —
     * which is the toggle the board actually needs.
     *
     * @return Collection<int, ReportingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        // Whole models, not a column list: these windows are compared by
        // `period_start` to find the previous one and handed to the compliance
        // Action, so a partial select would hand both a model with holes in
        // it. Twelve rows — there is nothing to optimise here anyway.
        return ReportingPeriod::query()
            ->where('opens_at', '<=', now())
            ->orderByDesc('period_start')
            ->limit(12)
            ->get();
    }

    /** The window on screen — the most recent one unless told otherwise. */
    #[Computed]
    public function period(): ?ReportingPeriod
    {
        if ($this->periodId !== '') {
            $chosen = $this->periods()->firstWhere('id', (int) $this->periodId);

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $this->periods()->first();
    }

    /** The window before the one on screen, for the quick toggle. */
    #[Computed]
    public function previousPeriod(): ?ReportingPeriod
    {
        $current = $this->period();

        if ($current === null) {
            return null;
        }

        return $this->periods()
            ->first(fn (ReportingPeriod $period): bool => $period->period_start->lessThan($current->period_start));
    }

    public function showPeriod(int $periodId): void
    {
        $this->periodId = (string) $periodId;
    }

    /**
     * @return array{
     *     period: array{id: int, code: string, label: string, due_at: string},
     *     tenants: list<array<string, mixed>>,
     *     totals: array{expected: int, submitted: int, on_time: int, missed: int, waived: int}
     * }|null
     */
    #[Computed]
    public function board(): ?array
    {
        $period = $this->period();

        if ($period === null) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();

        $board = app(BuildComplianceLeagueTable::class)($user, $period);
        $board['tenants'] = $this->sortRows($board['tenants']);

        return $board;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        $column = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'on_time_rate';
        $descending = $this->direction !== 'asc';

        usort($rows, function (array $a, array $b) use ($column, $descending): int {
            // An MDA with nothing expected has no rate at all — null, not zero.
            // It sorts to the bottom either way rather than claiming a perfect
            // or a failing score it never earned.
            $left = $a[$column] ?? null;
            $right = $b[$column] ?? null;

            if ($left === null || $right === null) {
                return $left === $right ? 0 : ($left === null ? 1 : -1);
            }

            $comparison = is_string($left) && is_string($right)
                ? strcasecmp($left, $right)
                : $left <=> $right;

            return $descending ? -$comparison : $comparison;
        });

        return $rows;
    }

    /** State-wide on-time rate, excluding waived obligations from the base. */
    public function stateOnTimeRate(): ?float
    {
        $board = $this->board();

        if ($board === null) {
            return null;
        }

        $scored = $board['totals']['expected'] - $board['totals']['waived'];

        return $scored > 0 ? round($board['totals']['on_time'] * 100 / $scored, 1) : null;
    }

    public function render(): View
    {
        return view('livewire.oversight.reporting.compliance-board');
    }
}
