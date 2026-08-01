<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\TenantSafeBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every tenant-owned model uses this trait. It:
 *  - applies the fail-closed TenantScope global scope (throws when queried
 *    with no tenant bound and no bypass),
 *  - auto-fills tenant_id from the bound CurrentTenant on create,
 *  - forbids creating records for a foreign tenant outside a bypass,
 *  - makes tenant_id immutable — records never change tenants,
 *  - exposes withoutTenancy() as the ONLY sanctioned scope bypass
 *    (allowed exclusively in oversight-surface code — see rules/tenancy.md).
 *
 * @mixin Model
 *
 * @property int $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $current = app(CurrentTenant::class);

            if ($model->getAttribute('tenant_id') === null) {
                if ($current->isBypassed()) {
                    return; // bypassed writers must set tenant_id explicitly
                }

                $model->setAttribute('tenant_id', $current->idOrFail());

                return;
            }

            if (! $current->isBypassed() && (int) $model->getAttribute('tenant_id') !== $current->idOrFail()) {
                throw CrossTenantWriteException::forCreate(static::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWriteException::forReassignment(static::class);
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return TenantSafeBuilder<static>
     */
    public function newEloquentBuilder($query): TenantSafeBuilder
    {
        /** @var TenantSafeBuilder<static> */
        return new TenantSafeBuilder($query);
    }

    /**
     * Bypass the tenant scope for this query. Oversight-surface code only
     * (app/Actions/Oversight, app/Livewire/Oversight) — anywhere else is a bug.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
