<?php

namespace App\Actions\Projects;

use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Award: the contract row, the refreshed project-level cache and the
 * draft → awarded transition, in ONE transaction (design §3). Half of that
 * committing is what produces an "awarded" project with no contract, or a
 * portfolio total that disagrees with its own contracts.
 *
 * Two authorizations, not one: `contracts.create` (may you create a contract
 * here) and `projects.award` (may you commit this project to it). The second
 * is checked up front rather than left to the transition, so an actor who
 * cannot award never gets as far as writing the contract row.
 */
class AwardContract
{
    /** Statuses a project can still receive a contract in. */
    private const AWARDABLE = [
        ProjectStatus::Draft,
        ProjectStatus::Awarded,
        ProjectStatus::Mobilized,
        ProjectStatus::InProgress,
        ProjectStatus::Suspended,
    ];

    /**
     * @param  array<string, mixed>  $attributes  contract fields (number, type, sum, scope, dates…)
     */
    public function __invoke(Project $project, Contractor $contractor, User $actor, array $attributes): Contract
    {
        Gate::forUser($actor)->authorize('create', Contract::class);
        Gate::forUser($actor)->authorize('award', $project);

        if (! in_array($project->status, self::AWARDABLE, true)) {
            throw ProjectRuleViolation::contractOnUnawardableProject($project->status);
        }

        // The whole reason the vendor registry is state-wide rather than
        // per-MDA: a firm blacklisted by one ministry must not keep winning
        // work in the next one.
        if ($contractor->is_blacklisted) {
            throw ProjectRuleViolation::contractorBlacklisted($contractor->name);
        }

        return DB::transaction(function () use ($project, $contractor, $actor, $attributes): Contract {
            $contract = Contract::create([
                ...$attributes,
                // AFTER the spread, so a payload naming `status` cannot decide
                // it. The field stays fillable because a contract legitimately
                // moves awarded → active → completed later on; what it must
                // never do is *begin* anywhere else — a contract booked
                // straight into "completed" claims an execution history that
                // never happened and a close-out nobody performed. Stated here
                // rather than left to the column default so the returned model
                // carries the value too.
                'status' => ContractStatus::Awarded,
                'project_id' => $project->id,
                'contractor_id' => $contractor->id,
                'created_by_id' => $actor->id,
                // A variation is RecordContractVariation's business; this
                // Action only ever writes original awards.
                'varies_contract_id' => null,
                'variation_reason' => null,
            ]);

            (new RecalculateContractValueTotal)($project);

            if ($project->status === ProjectStatus::Draft) {
                (new TransitionProjectStatus)(
                    $project,
                    ProjectStatus::Awarded,
                    $actor,
                    __('Contract :number awarded to :contractor.', [
                        'number' => $contract->contract_number,
                        'contractor' => $contractor->name,
                    ]),
                );
            }

            return $contract;
        });
    }
}
