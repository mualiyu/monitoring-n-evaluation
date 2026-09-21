<?php

namespace App\Actions\Indicators;

use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Rewords a result statement, or moves it within its own branch.
 *
 * What it deliberately CANNOT do is change `level` or `parent_id`: re-levelling
 * a statement after indicators hang off it would leave an output measure
 * attached to an outcome, and re-parenting it would move every figure beneath
 * it into a different roll-up without anybody choosing that. Both are done by
 * removing the leaf and adding it where it belongs, which is visible.
 */
class UpdateResultFramework
{
    public function __invoke(
        ResultFramework $framework,
        User $actor,
        string $statement,
        ?string $code = null,
        ?string $description = null,
        ?string $assumptions = null,
        ?int $sortOrder = null,
    ): ResultFramework {
        Gate::forUser($actor)->authorize('update', $framework);

        $framework->update([
            'statement' => $statement,
            'code' => $code,
            'description' => $description,
            'assumptions' => $assumptions,
            'sort_order' => $sortOrder ?? $framework->sort_order,
        ]);

        return $framework;
    }
}
