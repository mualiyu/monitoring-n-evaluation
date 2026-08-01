<?php

namespace App\Actions\Projects;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Project;
use App\Support\Money;

/**
 * Rebuilds `projects.contract_value_total` from `contracts`, the source of
 * truth. INTERNAL: it authorizes nothing because it decides nothing — the
 * Actions that change contracts (AwardContract, RecordContractVariation) call
 * it inside their transaction, which is the invariant the column comment
 * promises.
 *
 * Two rules the sum encodes, both of which are the drift risk the design flags
 * (§9.2):
 *  - **soft-deleted contracts do not count.** SoftDeletes handles that for
 *    free, and the moment it stopped being free the cache would quietly
 *    overstate a portfolio's committed value.
 *  - **terminated contracts do not count.** The column comment reads "Σ active
 *    contracts + variations"; a terminated engagement is money the state is no
 *    longer committed to, and leaving it in would make every dashboard total
 *    larger than the sum of live obligations. Awarded, active and completed
 *    all count — completed work was paid for.
 *
 * The reduction is integer kobo arithmetic in PHP rather than a SQL SUM()
 * because SQLite hands DECIMAL back as a float, and a float is exactly what
 * the money rules forbid.
 */
class RecalculateContractValueTotal
{
    public function __invoke(Project $project): Money
    {
        $total = Contract::query()
            ->where('project_id', $project->id)
            ->whereNot('status', ContractStatus::Terminated)
            ->get()
            ->reduce(
                fn (Money $carry, Contract $contract): Money => $carry->plus($contract->sum),
                Money::zero(),
            );

        // forceFill: the column is deliberately not fillable — it has exactly
        // this one writer.
        $project->forceFill(['contract_value_total' => $total])->save();

        return $total;
    }
}
