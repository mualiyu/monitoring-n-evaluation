<?php

namespace App\Actions\Indicators;

use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes one LEAF of a logframe. Never a branch.
 *
 * The FK restricts on both `parent_id` and `indicators.result_framework_id`,
 * so the database would refuse a branch delete anyway — but it would refuse it
 * as a 500. Checking here turns "the site broke" into "this statement still
 * carries three indicators", which is the sentence the officer needs.
 *
 * A soft delete, like every other government record on the platform: a result
 * statement that was measured for two years is not erased because the
 * programme was restructured.
 */
class DeleteResultFramework
{
    public function __invoke(ResultFramework $framework, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $framework);

        DB::transaction(function () use ($framework): void {
            $locked = ResultFramework::query()
                ->lockForUpdate()
                ->whereKey($framework->getKey())
                ->firstOrFail();

            if (! $locked->isRemovable()) {
                throw IndicatorRuleViolation::frameworkNotEmpty();
            }

            $locked->delete();
        });
    }
}
