<?php

namespace App\Actions\Oversight;

use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Models\ExceptionReport;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cross-MDA deviation board behind oversight /exceptions. Like
 * ListProjectsAcrossTenants, this is an explicit oversight privilege rather
 * than a convenience (rules/tenancy.md, enforced by the discipline sweep), and
 * the bypass is confined to this directory.
 *
 * THE PERMISSION IS RE-CHECKED IN THE GLOBAL TEAM before any bypass. A tenant
 * user holds `exceptions.view` inside their own MDA — the matrix gives it to
 * every MDA role — and `$user->can()` resolves against whatever permission
 * team happens to be bound. On this surface none is, so asking the ordinary
 * way would answer from a stale team and hand an MDA officer every ministry's
 * deviations. holdsGlobalPermission() forces the question into the global
 * team, where only oversight roles hold anything at all.
 *
 * Worst first, because the only reason to open this board is to find the worst
 * thing on it.
 *
 * NOTE on filtering by MDA: whereBelongsTo(), not a hand-written
 * where('tenant_id', …). Manual tenant clauses are banned platform-wide, and
 * "except in oversight code" is exactly the exception that stops being read as
 * an exception.
 */
class ListExceptionsAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, status?: ExceptionStatus|null, trigger?: ExceptionTrigger|null, severity?: IssueSeverity|null, search?: string|null, live_only?: bool}  $filters
     * @return LengthAwarePaginator<int, ExceptionReport>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('exceptions.view')) {
            throw new AuthorizationException('Viewing exception reports across MDAs requires oversight authority.');
        }

        // bypass(), not just withoutTenancy(): the scope removal applies to
        // the query it is called on, while the eager loads below are separate
        // queries against tenant-owned models (projects, issues) that would
        // each hit the fail-closed scope with no tenant bound.
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * The headline counts for the board's stat row, in ONE round trip across
     * every MDA. Same authority gate, same bypass discipline.
     *
     * @return array{live: int, critical: int, automatic: int, entities: int}
     */
    public function summary(User $actor): array
    {
        if (! $actor->holdsGlobalPermission('exceptions.view')) {
            throw new AuthorizationException('Viewing exception reports across MDAs requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(function (): array {
            $live = [];

            foreach (ExceptionStatus::cases() as $case) {
                if ($case->isLive()) {
                    $live[] = $case->value;
                }
            }

            $row = ExceptionReport::query()
                ->withoutTenancy()
                ->toBase()
                ->selectRaw('COUNT(*) as live_total')
                ->selectRaw('COUNT(CASE WHEN severity = ? THEN 1 END) as critical_total', [IssueSeverity::Critical->value])
                ->selectRaw('COUNT(CASE WHEN raised_by_id IS NULL THEN 1 END) as automatic_total')
                ->selectRaw('COUNT(DISTINCT tenant_id) as entity_total')
                ->whereIn('status', $live)
                ->whereNull('deleted_at')
                ->first();

            return [
                'live' => (int) ($row->live_total ?? 0),
                'critical' => (int) ($row->critical_total ?? 0),
                'automatic' => (int) ($row->automatic_total ?? 0),
                'entities' => (int) ($row->entity_total ?? 0),
            ];
        });
    }

    /**
     * @param  array{tenant?: Tenant|null, status?: ExceptionStatus|null, trigger?: ExceptionTrigger|null, severity?: IssueSeverity|null, search?: string|null, live_only?: bool}  $filters
     * @return LengthAwarePaginator<int, ExceptionReport>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        return ExceptionReport::query()
            ->withoutTenancy()
            // What the row actually renders. A cross-MDA board is the query
            // where an N+1 becomes hundreds of round trips.
            ->with([
                'tenant:id,name,slug',
                'project:id,ulid,title,reference,physical_progress',
                'issue:id,ulid,title,status',
            ])
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['status'] ?? null) instanceof ExceptionStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['trigger'] ?? null) instanceof ExceptionTrigger,
                fn (Builder $query) => $query->where('trigger', $filters['trigger']),
            )
            ->when(
                ($filters['severity'] ?? null) instanceof IssueSeverity,
                fn (Builder $query) => $query->where('severity', $filters['severity']),
            )
            ->when(
                $filters['live_only'] ?? false,
                fn (Builder $query) => $query->live(),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->whereIn(
                    'project_id',
                    // A subquery under the same bypass, so the term matches
                    // projects across every MDA exactly as the outer query
                    // reads across every MDA.
                    Project::query()
                        ->withoutTenancy()
                        ->where(fn (Builder $match) => $match
                            ->where('title', 'like', '%'.$filters['search'].'%')
                            ->orWhere('reference', 'like', '%'.$filters['search'].'%'))
                        ->select('id'),
                ),
            )
            ->worstFirst()
            ->paginate($perPage);
    }
}
