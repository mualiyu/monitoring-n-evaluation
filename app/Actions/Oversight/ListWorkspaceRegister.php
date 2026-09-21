<?php

declare(strict_types=1);

namespace App\Actions\Oversight;

use App\Enums\TenantType;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The workspace register: every MDA the state has onboarded, with the two
 * numbers that say whether a workspace is alive — how many people can enter it
 * and how many projects it is carrying.
 *
 * Tenant itself is global (it is the register OF workspaces, so it cannot be
 * scoped by one), which is why listing needs no bypass. The PROJECT COUNT
 * does: projects are tenant-owned, and `withCount` compiles a correlated
 * subquery that would meet the fail-closed scope with no tenant bound. The
 * bypass therefore wraps the execution, and it lives here — in an oversight
 * Action that checked `tenants.view` in the GLOBAL permission team first —
 * rather than in the screen.
 */
class ListWorkspaceRegister
{
    /**
     * @param  array{search?: string|null, type?: TenantType|null, sector?: int|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => $this->query($filters)
                ->with('sector:id,name')
                ->withCount(['users', 'projects'])
                ->paginate($perPage),
        );
    }

    /**
     * Headline counts for the register's stat row: how many workspaces exist,
     * how many are open, how many are suspended.
     *
     * @return array{total: int, active: int, suspended: int}
     */
    public function summary(User $actor): array
    {
        $this->authorize($actor);

        $total = Tenant::query()->count();
        $active = Tenant::query()->where('is_active', true)->count();

        return ['total' => $total, 'active' => $active, 'suspended' => $total - $active];
    }

    private function authorize(User $actor): void
    {
        if (! $actor->holdsGlobalPermission('tenants.view')) {
            throw new AuthorizationException('Reading the workspace register requires oversight tenants.view authority.');
        }
    }

    /**
     * @param  array{search?: string|null, type?: TenantType|null, sector?: int|null, status?: string|null}  $filters
     * @return Builder<Tenant>
     */
    private function query(array $filters): Builder
    {
        $search = $filters['search'] ?? null;

        return Tenant::query()
            ->when(
                is_string($search) && trim($search) !== '',
                function (Builder $query) use ($search): void {
                    $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $search)).'%';

                    $query->where(fn (Builder $match) => $match
                        ->where('name', 'like', $term)
                        ->orWhere('short_name', 'like', $term)
                        ->orWhere('slug', 'like', $term));
                },
            )
            ->when(
                ($filters['type'] ?? null) instanceof TenantType,
                fn (Builder $query) => $query->where('type', $filters['type']),
            )
            ->when(
                ($filters['sector'] ?? null) !== null,
                fn (Builder $query) => $query->where('sector_id', $filters['sector']),
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn (Builder $query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'suspended',
                fn (Builder $query) => $query->where('is_active', false),
            )
            ->orderBy('name');
    }
}
