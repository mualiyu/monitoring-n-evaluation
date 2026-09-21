<?php

namespace App\Actions\Indicators;

use App\Enums\IndicatorTier;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Indicator;
use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Defines an indicator this MDA needs and the state library has no entry for.
 *
 * The library is the preferred route (InstantiateIndicatorFromDefinition) —
 * comparable numbers across MDAs is the entire reason the state keeps a
 * predetermined list. But an MDA measuring something genuinely local must not
 * be blocked waiting for the Q4 indicator retreat to add it, so a local
 * definition is allowed with `indicator_definition_id` left null. That null is
 * also the query that answers "what should the retreat consider promoting".
 *
 * Created INACTIVE regardless of what the caller passes: activation is the
 * baseline gate (ActivateIndicator), and a creation path that could skip it
 * would make the baseline optional in practice.
 */
class CreateIndicator
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(
        array $attributes,
        User $actor,
        ?ResultFramework $framework = null,
        ?Indicator $parentIndicator = null,
    ): Indicator {
        Gate::forUser($actor)->authorize('create', Indicator::class);

        $tier = $attributes['tier'] ?? null;
        $tier = $tier instanceof IndicatorTier ? $tier : ($tier === null ? null : IndicatorTier::from((string) $tier));

        if ($framework !== null) {
            Gate::forUser($actor)->authorize('update', $framework);

            $tier ??= $framework->level->defaultTier();

            if (! $tier->fitsLevel($framework->level)) {
                throw IndicatorRuleViolation::tierDoesNotFitLevel($tier, $framework->level);
            }
        }

        return Indicator::query()->create([
            'result_framework_id' => $framework?->id,
            'parent_indicator_id' => $parentIndicator?->id,
            // The framework knows which project it serves; where there is no
            // framework the caller says so explicitly.
            'project_id' => $framework !== null ? $framework->project_id : ($attributes['project_id'] ?? null),
            'tier' => $tier,
            'name' => $attributes['name'],
            'definition' => $attributes['definition'] ?? null,
            'focus' => $attributes['focus'] ?? null,
            'unit' => $attributes['unit'],
            'measurement_frequency' => $attributes['measurement_frequency'],
            'target_type' => $attributes['target_type'],
            'data_source' => $attributes['data_source'] ?? null,
            'means_of_verification' => $attributes['means_of_verification'] ?? null,
            'responsible_collector_id' => $attributes['responsible_collector_id'] ?? null,
            'responsible_collector_text' => $attributes['responsible_collector_text'] ?? null,
            'baseline_value' => $attributes['baseline_value'] ?? null,
            'baseline_date' => $attributes['baseline_date'] ?? null,
            'baseline_source' => $attributes['baseline_source'] ?? null,
            'smart_justification' => $attributes['smart_justification'] ?? null,
            'created_by_id' => $actor->id,
            'is_active' => false,
            'activated_at' => null,
        ]);
    }
}
