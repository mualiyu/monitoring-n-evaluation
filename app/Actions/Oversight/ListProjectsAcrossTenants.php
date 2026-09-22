<?php

namespace App\Actions\Oversight;

use App\Enums\ProjectStatus;
use App\Models\FundingSource;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cross-MDA project list behind /portfolio. Together with
 * BuildPortfolioSummary these are the ONLY withoutTenancy() calls in the
 * module — a cross-tenant read is an explicit oversight privilege, not a
 * convenience (rules/tenancy.md, enforced by the discipline test).
 *
 * Eager-loads what the row actually renders (§9.3): tenant, sector, primary
 * location's LGA and an assignment count. A cross-MDA list is the query where
 * an N+1 becomes hundreds of round trips.
 *
 * NOTE on filtering by MDA: `whereBelongsTo()`, not a hand-written
 * `where('tenant_id', …)`. Manual tenant clauses are banned platform-wide, and
 * "except in oversight code" is exactly the exception that stops being read as
 * an exception.
 *
 * Location filters follow the GIS dashboard's rules exactly, because its tiles
 * drill into this list: a multi-site project is listed under every LGA it
 * touches, and `geotagged` (true = a site with BOTH coordinates, false = none,
 * null = either) tests only the filtered LGA's sites when an LGA is set.
 */
class ListProjectsAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, status?: ProjectStatus|null, sector?: Sector|null, funding_source?: FundingSource|null, lga?: Lga|null, geotagged?: bool|null, search?: string|null, overdue?: bool}  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('oversight.portfolio.view')) {
            throw new AuthorizationException('Viewing projects across MDAs requires oversight authority.');
        }

        // bypass(), not just withoutTenancy(): the scope removal applies to
        // the query it is called on, while the eager loads below are separate
        // queries against tenant-owned models (locations, assignments) that
        // would each hit the fail-closed scope with no tenant bound. bypass()
        // is the acknowledgment that this whole read is cross-tenant — which
        // is exactly what the oversight surface is for, and why it is confined
        // to this directory.
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * @param  array{tenant?: Tenant|null, status?: ProjectStatus|null, sector?: Sector|null, funding_source?: FundingSource|null, lga?: Lga|null, geotagged?: bool|null, search?: string|null, overdue?: bool}  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        $lga = ($filters['lga'] ?? null) instanceof Lga ? $filters['lga'] : null;
        $geotagged = $filters['geotagged'] ?? null;

        // A site the map can draw — one coordinate without the other is not a
        // fix — scoped to the filtered LGA when there is one.
        $mappable = fn (Builder $location): Builder => $location
            ->whereNotNull('project_locations.latitude')
            ->whereNotNull('project_locations.longitude')
            ->when($lga instanceof Lga, fn (Builder $site) => $site->whereBelongsTo($lga));

        return Project::query()
            ->with([
                'tenant:id,name,slug',
                'sector:id,name',
                'primaryLocation.lga:id,name',
            ])
            ->withCount('assignments')
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['status'] ?? null) instanceof ProjectStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['sector'] ?? null) instanceof Sector,
                fn (Builder $query) => $query->whereBelongsTo($filters['sector']),
            )
            ->when(
                ($filters['funding_source'] ?? null) instanceof FundingSource,
                fn (Builder $query) => $query->whereHas(
                    'fundingAllocations',
                    fn (Builder $allocation) => $allocation->whereBelongsTo($filters['funding_source']),
                ),
            )
            ->when(
                $lga instanceof Lga,
                fn (Builder $query) => $query->whereHas(
                    'locations',
                    fn (Builder $location) => $location->whereBelongsTo($lga),
                ),
            )
            ->when($geotagged === true, fn (Builder $query) => $query->whereHas('locations', $mappable))
            ->when($geotagged === false, fn (Builder $query) => $query->whereDoesntHave('locations', $mappable))
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('title', 'like', '%'.$filters['search'].'%')
                        ->orWhere('reference', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->when(
                $filters['overdue'] ?? false,
                // Past the revised (else planned) date and not finished — the
                // same rule Project::isOverdue() applies to a single row.
                fn (Builder $query) => $query
                    ->whereNull('actual_end_date')
                    ->whereNotIn('status', [
                        ProjectStatus::Completed,
                        ProjectStatus::Certified,
                        ProjectStatus::Closed,
                        ProjectStatus::Cancelled,
                    ])
                    ->whereRaw('COALESCE(revised_end_date, expected_end_date) < ?', [now()->toDateString()]),
            )
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
