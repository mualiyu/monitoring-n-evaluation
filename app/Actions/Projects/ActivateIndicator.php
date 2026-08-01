<?php

namespace App\Actions\Projects;

use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Indicator;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Activation is the baseline gate (design §1.10). A baseline is mandatory —
 * but enforced HERE rather than by a NOT NULL column, because a NOT NULL
 * baseline forces a placeholder into every half-drafted indicator and a
 * fabricated zero baseline is worse data quality than an explicit null. All
 * three parts are required: the value, the date it was measured, and where it
 * came from; a baseline nobody can trace is a number, not a baseline.
 *
 * Only active indicators accept readings or appear in reports, so this is also
 * the moment an indicator starts to count.
 */
class ActivateIndicator
{
    public function __invoke(Indicator $indicator, User $actor): Indicator
    {
        Gate::forUser($actor)->authorize('activate', $indicator);

        if ($indicator->is_active) {
            throw ProjectRuleViolation::indicatorAlreadyActive();
        }

        if (! $indicator->hasCompleteBaseline()) {
            throw ProjectRuleViolation::indicatorBaselineIncomplete();
        }

        $indicator->update([
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return $indicator;
    }
}
