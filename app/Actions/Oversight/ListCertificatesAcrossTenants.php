<?php

namespace App\Actions\Oversight;

use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cross-MDA completion register behind oversight `/certificates` — read
 * only, and the only cross-tenant read in this module besides its summary
 * twin.
 *
 * The permission is re-checked HERE, in the GLOBAL team, before any bypass.
 * The route's role middleware says "you are an oversight user"; it does not
 * say you hold `certificates.view`, and ExecutiveViewer/DataQualityReviewer
 * pass the first and would fail the second if a state ever narrowed the
 * matrix. A cross-tenant read is an explicit privilege, never a side effect of
 * which subdomain a request arrived on (rules/tenancy.md).
 *
 * NOTE on filtering by MDA: `whereBelongsTo()`, not a hand-written
 * `where('tenant_id', …)`. Manual tenant clauses are banned platform-wide, and
 * "except in oversight code" is exactly the exception that stops being read as
 * an exception.
 */
class ListCertificatesAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, type?: CertificateType|null, search?: string|null, revoked?: bool}  $filters
     * @return LengthAwarePaginator<int, Certificate>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('certificates.view')) {
            throw new AuthorizationException('Viewing certificates across MDAs requires oversight authority.');
        }

        // bypass(), not just withoutTenancy(): the scope removal applies to
        // the query it is called on, while the eager loads below are separate
        // queries against a tenant-owned model (projects) that would each hit
        // the fail-closed scope with no tenant bound.
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * @param  array{tenant?: Tenant|null, type?: CertificateType|null, search?: string|null, revoked?: bool}  $filters
     * @return LengthAwarePaginator<int, Certificate>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        return Certificate::query()
            ->with([
                'tenant:id,name,slug',
                'project:id,ulid,title,reference,tenant_id',
                'issuedBy:id,name',
            ])
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['type'] ?? null) instanceof CertificateType,
                fn (Builder $query) => $query->where('type', $filters['type']),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(fn (Builder $match) => $match
                    ->where('reference', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas('project', fn (Builder $project) => $project
                        ->where('title', 'like', '%'.$filters['search'].'%')
                        ->orWhere('reference', 'like', '%'.$filters['search'].'%'))),
            )
            // Withdrawn certificates are hidden by default and never deleted:
            // the state's own register should read as what is in force, with
            // the history one checkbox away.
            ->when(
                ($filters['revoked'] ?? false) === false,
                fn (Builder $query) => $query->active(),
            )
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
