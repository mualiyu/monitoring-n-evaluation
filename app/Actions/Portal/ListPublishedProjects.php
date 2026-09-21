<?php

namespace App\Actions\Portal;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Publishing\PublicProjectPayload;
use App\Tenancy\PortalRead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The published-projects browser behind /projects.
 *
 * Two gates, both unconditional and neither reachable from a query string:
 *  1. PublicProjectPayload::publishedOnly() — unpublished rows are not merely
 *     hidden from the list, they are excluded from the query, so no filter
 *     combination and no crafted parameter can surface one.
 *  2. The result is projected through PublicProjectPayload, so the view
 *     receives arrays of whitelisted keys and never a Project model.
 *
 * The cross-tenant read runs inside PortalRead — the portal's single
 * sanctioned tenancy bypass (rules/tenancy.md).
 */
class ListPublishedProjects
{
    /**
     * @param  array{search?: string|null, sector?: int|null, lga?: int|null, status?: ProjectStatus|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function __invoke(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return (new PortalRead)(function () use ($filters, $perPage): LengthAwarePaginator {
            $page = PublicProjectPayload::forPortal(Project::query())
                ->when(
                    ($filters['status'] ?? null) instanceof ProjectStatus,
                    fn (Builder $query) => $query->where('status', $filters['status']),
                )
                ->when(
                    ($filters['sector'] ?? null) !== null,
                    fn (Builder $query) => $query->where('sector_id', $filters['sector']),
                )
                ->when(
                    ($filters['lga'] ?? null) !== null,
                    fn (Builder $query) => $query->whereHas(
                        'locations',
                        fn (Builder $location) => $location->where('lga_id', $filters['lga']),
                    ),
                )
                ->when(
                    ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                    fn (Builder $query) => $query->where(
                        fn (Builder $match) => $match
                            ->where('title', 'like', '%'.$filters['search'].'%')
                            ->orWhere('reference', 'like', '%'.$filters['search'].'%'),
                    ),
                )
                // Newest publication first: a portal is read as a feed of what
                // the state has just opened up, not as a database dump.
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            return PublicProjectPayload::paginator($page)->withQueryString();
        });
    }
}
