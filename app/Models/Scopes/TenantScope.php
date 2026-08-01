<?php

namespace App\Models\Scopes;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope for tenant-owned models.
 *
 * Fail-closed by design: querying a tenant-owned model with no tenant bound
 * (and no explicit bypass) throws TenantNotResolvedException — loudly, at the
 * source. A missing tenant context must never mean "see everything", and a
 * silent empty result would only hide the bug. Cross-tenant reads are an
 * explicit oversight-surface privilege via withoutTenancy() or bypass().
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentTenant::class);

        if ($current->isBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $current->idOrFail());
    }
}
