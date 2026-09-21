<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\ProgressReportStatus;
use App\Enums\ProjectStatus;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportObligation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The MDA workspace's headline figures — the four tiles at the top of the
 * dashboard, which until now were hard-coded literals labelled "sample figure".
 *
 * Three round trips, not twelve: one grouped scan of the register, one grouped
 * scan of the returns, one of the obligations. Everything is narrowed by
 * `visibleTo()` first, so a consultant's dashboard counts THEIR assignments and
 * a director's counts the workspace — one code path, and the isolation proof
 * that already covers the list screens covers this too.
 *
 * Deliberately NOT cached. A dashboard tile that lags the action a user just
 * took ("I approved it, why does it still say 6?") destroys trust in every
 * other number on the page, and the tenant-scoped aggregates here are cheap —
 * the state-wide ones, which are not, are cached in BuildPortfolioSummary.
 */
class BuildWorkspaceSummary
{
    /**
     * @return array{
     *     project_count: int,
     *     active_projects: int,
     *     contract_value: Money,
     *     expenditure: Money,
     *     average_progress: float,
     *     overdue_projects: int,
     *     awaiting_review: int,
     *     awaiting_approval: int,
     *     drafts: int,
     *     obligations_outstanding: int,
     *     obligations_overdue: int
     * }
     */
    public function __invoke(User $actor): array
    {
        return [
            ...$this->projectFigures($actor),
            ...$this->reportFigures($actor),
            ...$this->obligationFigures($actor),
        ];
    }

    /**
     * @return array{project_count: int, active_projects: int, contract_value: Money, expenditure: Money, average_progress: float, overdue_projects: int}
     */
    private function projectFigures(User $actor): array
    {
        $finished = [
            ProjectStatus::Completed->value,
            ProjectStatus::Certified->value,
            ProjectStatus::Closed->value,
            ProjectStatus::Cancelled->value,
        ];

        // "Active" is the delivery window: awarded, mobilised, under way. Not
        // drafts (nothing is being built), not completed-and-after (nothing
        // more will be), and not suspended — a suspended project is precisely
        // the one a director must not see counted as active. Mid-term is an
        // EVENT on this platform, not a status, so it does not appear here.
        $active = [
            ProjectStatus::Awarded->value,
            ProjectStatus::Mobilized->value,
            ProjectStatus::InProgress->value,
        ];

        $row = Project::query()
            ->visibleTo($actor)
            ->toBase()
            ->selectRaw('COUNT(*) as project_count')
            ->selectRaw('COALESCE(SUM(contract_value_total), 0) as contract_value')
            ->selectRaw('COALESCE(SUM(expenditure_to_date), 0) as expenditure')
            ->selectRaw('COALESCE(AVG(physical_progress), 0) as average_progress')
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as active_projects',
                $active,
            )
            // Same definition of "overdue" as the register's filter: the
            // delivery date has passed and the work has not finished, with an
            // approved extension superseding the original date.
            ->selectRaw(
                'SUM(CASE WHEN status NOT IN (?, ?, ?, ?)
                      AND COALESCE(revised_end_date, expected_end_date) IS NOT NULL
                      AND COALESCE(revised_end_date, expected_end_date) < ?
                     THEN 1 ELSE 0 END) as overdue_projects',
                [...$finished, Carbon::today()->toDateString()],
            )
            ->first();

        return [
            'project_count' => (int) ($row->project_count ?? 0),
            'active_projects' => (int) ($row->active_projects ?? 0),
            'contract_value' => $this->money($row->contract_value ?? 0),
            'expenditure' => $this->money($row->expenditure ?? 0),
            'average_progress' => round((float) ($row->average_progress ?? 0), 1),
            'overdue_projects' => (int) ($row->overdue_projects ?? 0),
        ];
    }

    /**
     * SUM() comes back as a float on SQLite and a decimal string on MySQL, and
     * a float large enough (a state portfolio runs to billions of naira) is
     * rendered in scientific notation — which fromDecimalString() rightly
     * refuses. Normalise to a plain decimal string first.
     */
    private function money(mixed $value): Money
    {
        return Money::fromDecimalString(number_format((float) $value, 2, '.', ''));
    }

    /**
     * @return array{awaiting_review: int, awaiting_approval: int, drafts: int}
     */
    private function reportFigures(User $actor): array
    {
        $row = ProgressReport::query()
            ->visibleTo($actor)
            ->toBase()
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as awaiting_review', [ProgressReportStatus::Submitted->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as awaiting_approval', [ProgressReportStatus::Reviewed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as drafts', [ProgressReportStatus::Draft->value])
            ->first();

        return [
            'awaiting_review' => (int) ($row->awaiting_review ?? 0),
            'awaiting_approval' => (int) ($row->awaiting_approval ?? 0),
            'drafts' => (int) ($row->drafts ?? 0),
        ];
    }

    /**
     * @return array{obligations_outstanding: int, obligations_overdue: int}
     */
    private function obligationFigures(User $actor): array
    {
        $outstanding = ReportObligation::query()->visibleTo($actor)->outstanding();

        $row = $outstanding
            ->toBase()
            ->selectRaw('COUNT(*) as outstanding')
            ->selectRaw('SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END) as overdue', [Carbon::now()])
            ->first();

        return [
            'obligations_outstanding' => (int) ($row->outstanding ?? 0),
            'obligations_overdue' => (int) ($row->overdue ?? 0),
        ];
    }
}
