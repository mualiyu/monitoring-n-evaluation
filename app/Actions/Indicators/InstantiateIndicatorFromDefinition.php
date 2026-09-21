<?php

namespace App\Actions\Indicators;

use App\Enums\IndicatorTier;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Takes a STATE LIBRARY entry and makes it this MDA's indicator, attached to
 * the result statement it measures.
 *
 * This is the move the manual's "predetermined indicator list" (digest §4)
 * depends on. The definition, unit, frequency and means of verification are
 * COPIED rather than referenced, on purpose: the state may reword a library
 * entry next year, and a figure published under the old wording must keep
 * meaning what it meant when it was published. `indicator_definition_id`
 * preserves the lineage so consolidation can still group across MDAs.
 *
 * The baseline is deliberately NOT copied — there is nothing to copy. A
 * baseline is this MDA's own measurement of its own starting point, and a
 * library entry that shipped one would be handing every ministry the same
 * fabricated zero.
 */
class InstantiateIndicatorFromDefinition
{
    public function __invoke(
        IndicatorDefinition $definition,
        ResultFramework $framework,
        User $actor,
        ?IndicatorTier $tier = null,
        ?Indicator $parentIndicator = null,
    ): Indicator {
        Gate::forUser($actor)->authorize('create', Indicator::class);
        Gate::forUser($actor)->authorize('update', $framework);

        if (! $definition->is_active) {
            throw IndicatorRuleViolation::definitionRetired($definition->code);
        }

        $tier ??= $definition->default_tier ?? $framework->level->defaultTier();

        if (! $tier->fitsLevel($framework->level)) {
            throw IndicatorRuleViolation::tierDoesNotFitLevel($tier, $framework->level);
        }

        return Indicator::query()->create([
            ...$definition->templateAttributes(),
            'tier' => $tier,
            'result_framework_id' => $framework->id,
            'parent_indicator_id' => $parentIndicator?->id,
            // The framework knows which project it serves; an indicator that
            // disagreed with its own statement would appear under one project
            // in the register and another in the logframe.
            'project_id' => $framework->project_id,
            'created_by_id' => $actor->id,
            // Inactive until this MDA agrees a baseline for it —
            // ActivateIndicator is the gate, and instantiation does not skip it.
            'is_active' => false,
            'activated_at' => null,
        ]);
    }
}
