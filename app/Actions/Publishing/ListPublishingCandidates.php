<?php

namespace App\Actions\Publishing;

use App\Models\Project;
use App\Models\User;
use App\Support\Publishing\PublicProjectPayload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The MDA's own publishing queue: which of THIS workspace's projects are
 * public, and which are eligible to be.
 *
 * No tenancy bypass and no manual tenant clause — TenantScope confines the
 * query to the resolved workspace, which is the whole reason the tenant
 * surface can run the same screen as oversight without a second set of rules.
 * The oversight twin (ListPublishingCandidatesAcrossTenants) is the one that
 * crosses MDAs, and it lives in app/Actions/Oversight/ where a bypass belongs.
 *
 * Eligibility comes from PublishProjectToPortal::PUBLISHABLE_STATUSES, so the
 * list can never offer a project the guard would then refuse.
 */
class ListPublishingCandidates
{
    /**
     * @param  array{search?: string|null, state?: string|null}  $filters
     *                                                                     `state`: 'published' | 'unpublished' | '' (both)
     * @return LengthAwarePaginator<int, Project>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Project::class);

        return self::query($filters)->paginate($perPage);
    }

    /**
     * The candidate query, shared with the oversight twin so "what may be
     * published" is defined exactly once.
     *
     * @param  array{search?: string|null, state?: string|null}  $filters
     * @return Builder<Project>
     */
    public static function query(array $filters = []): Builder
    {
        return Project::query()
            ->with(PublicProjectPayload::RELATIONS)
            ->whereIn('status', PublishProjectToPortal::publishableValues())
            ->when(
                ($filters['state'] ?? '') === 'published',
                fn (Builder $query) => $query->whereNotNull('published_at'),
            )
            ->when(
                ($filters['state'] ?? '') === 'unpublished',
                fn (Builder $query) => $query->whereNull('published_at'),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('title', 'like', '%'.$filters['search'].'%')
                        ->orWhere('reference', 'like', '%'.$filters['search'].'%'),
                ),
            )
            // Unpublished first: the queue is a to-do list, not an archive.
            ->orderByRaw('CASE WHEN published_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id');
    }
}
