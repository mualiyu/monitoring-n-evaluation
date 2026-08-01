<?php

namespace App\Models\Builders;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Tenancy\TenantSafeBuilder;

/**
 * Closes the mass-update hole in contract immutability (migration review §4).
 *
 * The model's `updating` hook rejects a dirty `sum`, `award_date`,
 * `contractor_id` or `scope_of_works` — but builder `update()` fires no model
 * events, so `Contract::query()->update(['sum' => …])` would walk straight
 * past it, exactly as it would walk past the tenant guards if
 * TenantSafeBuilder did not exist. An award sum that a bulk update or an
 * import path can rewrite is not immutable; it is merely inconvenient to
 * rewrite.
 *
 * @extends TenantSafeBuilder<Contract>
 */
class ContractBuilder extends TenantSafeBuilder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        foreach (Contract::IMMUTABLE_TERMS as $field) {
            if (array_key_exists($field, $values)) {
                throw ProjectRuleViolation::immutableContractField($field);
            }
        }

        return parent::update($values);
    }
}
