<?php

namespace App\Actions\Indicators;

use App\Models\IndicatorDefinition;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Takes a library entry out of circulation without deleting it.
 *
 * Retirement, never deletion, and for the same reason sectors are retired:
 * instantiated indicators hold a restrictOnDelete FK back here, and a
 * definition that vanished would leave every figure published under it meaning
 * nothing. A retired entry stays readable and stays quotable; it simply cannot
 * be instantiated into a new framework.
 *
 * The manual's Q4 indicator retreat (digest §2) is exactly this act at scale:
 * the year's list is reviewed, measures that stopped being informative are
 * dropped, new ones are added, and the historical series keeps its meaning.
 */
class RetireIndicatorDefinition
{
    public function __invoke(IndicatorDefinition $definition, User $actor, bool $retired = true): IndicatorDefinition
    {
        Gate::forUser($actor)->authorize('update', $definition);

        $definition->update(['is_active' => ! $retired]);

        return $definition;
    }
}
