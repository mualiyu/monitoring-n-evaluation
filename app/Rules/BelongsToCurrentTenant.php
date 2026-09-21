<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "This id names a record of the CURRENT workspace."
 *
 * The reason this exists rather than `Rule::exists('projects', 'id')`:
 * `Rule::exists` runs on the QUERY BUILDER, so it never sees the TenantScope
 * and will happily confirm another MDA's row. The obvious patch —
 * `->where('tenant_id', $id)` — is banned platform-wide (rules/tenancy.md:
 * "if you need it, the model is missing the trait") and the discipline sweep
 * fails on it, precisely because a hand-written tenant filter is a thing
 * somebody eventually forgets.
 *
 * Querying through the MODEL instead means the global scope does the work:
 * another MDA's id simply does not exist here, and an unbound tenant context
 * throws rather than matching everything.
 *
 *   'projectId' => ['nullable', 'integer', new BelongsToCurrentTenant(Project::class)],
 *   'dependsOnId' => ['nullable', new BelongsToCurrentTenant(
 *       WorkplanActivity::class,
 *       fn (Builder $q) => $q->where('workplan_id', $this->workplan->id),
 *   )],
 */
class BelongsToCurrentTenant implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder<Model>): mixed)|null  $constrain  extra narrowing, e.g. to a parent record
     */
    public function __construct(
        private string $model,
        private ?Closure $constrain = null,
        private ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // nullable/required decide presence; this rule decides ownership
        }

        /** @var Builder<Model> $query */
        $query = $this->model::query()->whereKey($value);

        if ($this->constrain !== null) {
            ($this->constrain)($query);
        }

        if (! $query->exists()) {
            // Deliberately the same message as "not found": telling a user
            // that the record exists but belongs to another MDA is itself a
            // disclosure.
            $fail($this->message ?? __('The selected :attribute is invalid.', ['attribute' => $attribute]));
        }
    }
}
