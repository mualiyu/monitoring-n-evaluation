<?php

namespace App\Actions\Oversight;

use App\Actions\Publishing\ListPublishingCandidates;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The state-wide publishing queue behind oversight /publishing.
 *
 * Like every other cross-MDA read on this platform, the tenancy bypass lives
 * here — in app/Actions/Oversight/, next to the authorization check that
 * justifies it — rather than in the Livewire component that renders it.
 * `projects.publish` is the right gate: the secretariat's authority to decide
 * what the public sees is the same authority that publishes, and it must be
 * held in the GLOBAL team, so an MdaAdmin's workspace role grants nothing
 * here.
 *
 * bypass(), not just withoutTenancy(): the candidate query eager-loads
 * locations and contracts, each of which is a separate query against a
 * tenant-owned model that would otherwise hit the fail-closed scope with no
 * tenant bound.
 *
 * The candidate definition itself is NOT duplicated — it is
 * ListPublishingCandidates::query(), so the MDA queue and the state queue can
 * never disagree about which projects are eligible.
 */
class ListPublishingCandidatesAcrossTenants
{
    /**
     * @param  array{search?: string|null, state?: string|null, tenant?: Tenant|null}  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('projects.publish')) {
            throw new AuthorizationException('Deciding what the public portal shows requires state publishing authority.');
        }

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => ListPublishingCandidates::query($filters)
                ->when(
                    ($filters['tenant'] ?? null) instanceof Tenant,
                    // whereBelongsTo(), never a hand-written tenant clause —
                    // "except in oversight code" is exactly the exception that
                    // stops being read as an exception.
                    fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
                )
                ->paginate($perPage),
        );
    }

    /**
     * Resolve one candidate by ULID for a publish/unpublish write. Separate
     * from the list because a write must never act on a row the caller only
     * believes it saw: this re-reads it under the same candidate definition.
     */
    public function find(User $actor, string $ulid): ?Project
    {
        if (! $actor->holdsGlobalPermission('projects.publish')) {
            throw new AuthorizationException('Deciding what the public portal shows requires state publishing authority.');
        }

        return app(CurrentTenant::class)->bypass(
            fn (): ?Project => ListPublishingCandidates::query()->where('ulid', $ulid)->first(),
        );
    }
}
