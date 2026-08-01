<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\CrossTenantWriteException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent builder for BelongsToTenant models. Model events enforce tenant
 * integrity on create()/save(); this builder closes the mass-operation gap —
 * builder update()/insert()/upsert() fire no model events, so without these
 * guards they could plant or move rows across tenants.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class TenantSafeBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        if (array_key_exists('tenant_id', $values) && ! app(CurrentTenant::class)->isBypassed()) {
            throw CrossTenantWriteException::forReassignment($this->getModel()::class);
        }

        return parent::update($values);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $this->guardEventlessWrite(__FUNCTION__);

        return parent::insert($values);
    }

    // insertGetId() is deliberately NOT guarded: Eloquent's performInsert()
    // routes every create()/save() through it AFTER the trait's creating
    // checks have run. Direct app-code calls are banned statically by the
    // tenancy discipline test instead.

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $this->guardEventlessWrite(__FUNCTION__);

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->guardEventlessWrite(__FUNCTION__);

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Event-less bulk writes skip the trait's auto-fill and cross-tenant
     * checks entirely, so they require an explicit bypass() acknowledgment —
     * inside which the writer owns tenant_id correctness.
     */
    private function guardEventlessWrite(string $method): void
    {
        if (! app(CurrentTenant::class)->isBypassed()) {
            throw CrossTenantWriteException::forCreate(
                $this->getModel()::class." (builder {$method}() skips tenant events; wrap in CurrentTenant::bypass() and set tenant_id explicitly, or use create())"
            );
        }
    }
}
