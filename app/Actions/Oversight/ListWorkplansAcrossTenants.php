<?php

namespace App\Actions\Oversight;

use App\Enums\WorkplanStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cross-MDA work-plan list behind oversight /workplans. Read-only: the
 * secretariat sees which entities have a plan for the year, whether it has
 * been approved, and how it is tracking — it does not edit anybody's plan.
 *
 * The bypass and the authorization that justifies it live together, in this
 * one place, exactly as ListProjectsAcrossTenants does. `workplans.view` is
 * re-checked in the GLOBAL permission team first: an MDA role grants nothing
 * here, because a tenant role can never be assigned to the global team.
 *
 * bypass(), not just withoutTenancy(): the eager loads below are separate
 * queries against tenant-owned models (activities, the owner's plans) that
 * would each hit the fail-closed scope with no tenant bound. bypass() is the
 * acknowledgment that this whole read is cross-tenant.
 *
 * NOTE on filtering by MDA: whereBelongsTo(), not a hand-written
 * where('tenant_id', …) — manual tenant clauses are banned platform-wide, and
 * "except in oversight code" is exactly the exception that stops being read
 * as an exception.
 */
class ListWorkplansAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, status?: WorkplanStatus|null, year?: int|null, search?: string|null, unlinked?: bool}  $filters
     * @return LengthAwarePaginator<int, Workplan>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('workplans.view')) {
            throw new AuthorizationException('Viewing work plans across MDAs requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => $this->query($filters, $perPage)
        );
    }

    /**
     * The years any MDA has a plan for — the filter bar's options, so it never
     * offers a year that returns nothing.
     *
     * @return list<int>
     */
    public function years(User $actor): array
    {
        if (! $actor->holdsGlobalPermission('workplans.view')) {
            throw new AuthorizationException('Viewing work plans across MDAs requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(fn (): array => array_map(
            'intval',
            Workplan::query()->distinct()->orderByDesc('year')->pluck('year')->all(),
        ));
    }

    /**
     * @param  array{tenant?: Tenant|null, status?: WorkplanStatus|null, year?: int|null, search?: string|null, unlinked?: bool}  $filters
     * @return LengthAwarePaginator<int, Workplan>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        return Workplan::query()
            ->with([
                'tenant:id,name,slug',
                'owner:id,name',
                // The roll-up is computed by App\Support\WorkplanProgress over
                // these rows — one eager load for the page rather than a
                // formula repeated in SQL that could drift from the PHP one.
                'activities',
            ])
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['status'] ?? null) instanceof WorkplanStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['year'] ?? null) !== null,
                fn (Builder $query) => $query->where('year', $filters['year']),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where('title', 'like', '%'.$filters['search'].'%'),
            )
            // The manual's rule, made visible state-wide: plans carrying
            // activities with no output indicator.
            ->when(
                $filters['unlinked'] ?? false,
                fn (Builder $query) => $query->whereHas(
                    'activities',
                    fn (Builder $activity) => $activity->whereNull('indicator_id'),
                ),
            )
            ->orderByDesc('year')
            ->orderBy('title')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
