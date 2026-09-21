<?php

namespace App\Actions\Oversight;

use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Models\Evaluation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cross-MDA evaluation list behind oversight `/evaluations`. This and
 * ListRecommendationsAcrossTenants are the ONLY tenancy bypasses in the
 * evaluation module — a cross-tenant read is an explicit oversight privilege,
 * not a convenience (rules/tenancy.md, enforced by the discipline test).
 *
 * The permission is re-checked HERE, in the GLOBAL permission team, before any
 * bypass. Route middleware admits four oversight roles; `evaluations.view` is
 * what says which of them may read another MDA's findings, and asking for it
 * inside the Action means a second caller (a console export, a dashboard card)
 * inherits the check instead of having to remember it.
 *
 * Eager-loads what the row renders: the MDA, the project, the lead and a
 * recommendation count. A cross-MDA list is exactly the query where an N+1
 * becomes hundreds of round trips.
 */
class ListEvaluationsAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, status?: EvaluationStatus|null, type?: EvaluationType|null, search?: string|null, overdue?: bool}  $filters
     * @return LengthAwarePaginator<int, Evaluation>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('evaluations.view')) {
            throw new AuthorizationException('Viewing evaluations across MDAs requires oversight authority.');
        }

        // bypass(), not just withoutTenancy(): the scope removal applies to
        // the query it is called on, while the eager loads below are separate
        // queries against tenant-owned models (team members, recommendations)
        // that would each hit the fail-closed scope with no tenant bound.
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * @param  array{tenant?: Tenant|null, status?: EvaluationStatus|null, type?: EvaluationType|null, search?: string|null, overdue?: bool}  $filters
     * @return LengthAwarePaginator<int, Evaluation>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        return Evaluation::query()
            ->with([
                'tenant:id,name,slug',
                'project:id,ulid,title,reference',
                'lead.user:id,name',
                'criterionScores:id,evaluation_id,criterion,score,weight',
            ])
            ->withCount('recommendations')
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                // whereBelongsTo(), not a hand-written where('tenant_id', …):
                // manual tenant clauses are banned platform-wide, and "except
                // in oversight code" is exactly the exception that stops being
                // read as an exception.
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['status'] ?? null) instanceof EvaluationStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['type'] ?? null) instanceof EvaluationType,
                fn (Builder $query) => $query->where('type', $filters['type']),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('title', 'like', '%'.$filters['search'].'%')
                        ->orWhere('subject_name', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->when(
                $filters['overdue'] ?? false,
                // Past its report deadline and not yet settled — the same rule
                // Evaluation::isReportOverdue() applies to a single row.
                fn (Builder $query) => $query
                    ->whereNotNull('report_due_on')
                    ->whereDate('report_due_on', '<', now()->toDateString())
                    ->whereIn('status', [
                        EvaluationStatus::Planned,
                        EvaluationStatus::InProgress,
                        EvaluationStatus::DraftReport,
                        EvaluationStatus::UnderReview,
                    ]),
            )
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
