<?php

namespace App\Actions\Projects;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A variation is a NEW contract row pointing at the one it varies — the
 * manual's amendment-register pattern (design §1.7). The original award is
 * never edited: superseded figures stay readable, which is the difference
 * between an audit trail and a rewritten history.
 *
 * The variation's own `sum` is the DELTA, positive or negative (a downward
 * variation is a real thing), so the project cache is Σ of every live row.
 */
class RecordContractVariation
{
    /**
     * @param  array<string, mixed>  $attributes  the variation's own fields (number, sum, scope, dates…)
     */
    public function __invoke(Contract $original, User $actor, array $attributes, ?string $reason = null): Contract
    {
        Gate::forUser($actor)->authorize('update', $original);

        $reason = trim((string) ($reason ?? $attributes['variation_reason'] ?? ''));

        if ($reason === '') {
            throw ProjectRuleViolation::variationRequiresReason();
        }

        // Variations attach to the award, not to each other: a chain would
        // make "what is this contract worth now" a graph walk, and the
        // revisedValue() accessor a lie.
        if ($original->isVariation()) {
            throw ProjectRuleViolation::variationOfVariation();
        }

        if (array_key_exists('project_id', $attributes)
            && (int) $attributes['project_id'] !== $original->project_id) {
            throw ProjectRuleViolation::variationCrossesProjects();
        }

        return DB::transaction(function () use ($original, $actor, $attributes, $reason): Contract {
            $variation = Contract::create([
                ...$attributes,
                'project_id' => $original->project_id,
                // A variation is executed by the firm holding the original —
                // a different firm is a new award, not an amendment.
                'contractor_id' => $original->contractor_id,
                'varies_contract_id' => $original->id,
                'variation_reason' => $reason,
                'created_by_id' => $actor->id,
            ]);

            $project = Project::query()->findOrFail($original->project_id);

            (new RecalculateContractValueTotal)($project);

            return $variation;
        });
    }
}
