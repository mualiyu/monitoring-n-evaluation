<?php

namespace App\Actions\Evaluation;

use App\Actions\Iam\CheckTenantMembership;
use App\Enums\RecommendationStatus;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Jobs\Evaluation\NotifyRecommendationAssigned;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Puts a recommendation on the follow-up register (manual digest §7.9).
 *
 * The source is polymorphic — an evaluation, a progress report, an inspection,
 * an exception report — because the manual's knowledge-management loop does
 * not care which artifact noticed the problem, only that what was said is
 * tracked to a decision. A recommendation "per user type, prioritized, costed,
 * timetabled" (digest §4) is what the columns below record.
 *
 * TWO GUARDS THAT ARE NOT FORMALITIES:
 *  - the source must be TENANT-OWNED and must belong to THIS workspace. A
 *    recommendation hung off another MDA's inspection would be a cross-tenant
 *    reference the global scope cannot police, because `source_type` is a
 *    string and `source_id` has no foreign key to police it with.
 *  - an addressee is required, as a user or as a named body. A recommendation
 *    addressed to nobody is the definition of one that will not be
 *    implemented, and the overdue sweep would have nobody to chase.
 */
class RaiseRecommendation
{
    /**
     * @param  array<string, mixed>  $attributes  title, body, addressee_id |
     *                                            addressee_body, priority,
     *                                            estimated_cost, timeline, due_on
     */
    public function __invoke(Model $source, User $actor, array $attributes): Recommendation
    {
        Gate::forUser($actor)->authorize('create', Recommendation::class);

        $this->assertSourceIsRecommendable($source);
        $this->assertAddressee($attributes);

        $recommendation = DB::transaction(function () use ($source, $actor, $attributes): Recommendation {
            $recommendation = Recommendation::create([
                ...$attributes,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                // Provenance captured at raise time, not a live mirror: it is
                // what makes "every outstanding recommendation on this road"
                // one indexed query rather than a join across four possible
                // source tables.
                'project_id' => $attributes['project_id'] ?? $this->projectOf($source),
                'raised_by_id' => $actor->id,
            ]);

            // Not fillable: `status` belongs to TransitionRecommendationStatus
            // and the stamps are audit facts. Stating the opening value here
            // rather than leaning on the DB default means the in-memory model
            // is never a null status waiting for a re-read to become real.
            $recommendation->forceFill([
                'status' => RecommendationStatus::Open,
                'raised_at' => now(),
                'status_changed_at' => now(),
            ])->save();

            return $recommendation;
        });

        // Dispatched after the write closes, never inside it.
        if ($recommendation->addressee_id !== null) {
            NotifyRecommendationAssigned::dispatch($recommendation->id);
        }

        return $recommendation;
    }

    /**
     * The source has to be a tenant-owned record of THIS workspace. Checked by
     * the column rather than by a class allow-list, so an inspection or an
     * exception report shipped by another module needs no change here — while
     * a global reference row (a sector, a contractor) is correctly refused,
     * because a recommendation belongs to one MDA's follow-up register.
     */
    private function assertSourceIsRecommendable(Model $source): void
    {
        $tenantId = $source->getAttribute('tenant_id');

        if ($tenantId === null) {
            throw EvaluationRuleViolation::sourceNotRecommendable(class_basename($source));
        }

        // BelongsToTenant refuses a create for a foreign tenant, so a mismatch
        // would fail anyway — but it would fail as a CrossTenantWriteException
        // from deep inside the model layer, and a screen has nothing useful to
        // show for that. Refusing it here gives the caller a sentence.
        //
        // No tenant bound means a console or oversight context, where the
        // source could only have been loaded through an explicit oversight
        // read in the first place.
        $current = app(CurrentTenant::class);

        if ($current->bound() && $current->id() !== (int) $tenantId) {
            throw EvaluationRuleViolation::sourceBelongsToAnotherEntity();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertAddressee(array $attributes): void
    {
        $addresseeId = $attributes['addressee_id'] ?? null;
        $hasUser = $addresseeId !== null;
        $hasBody = trim((string) ($attributes['addressee_body'] ?? '')) !== '';

        if (! $hasUser && ! $hasBody) {
            throw EvaluationRuleViolation::addresseeRequired();
        }

        if (! $hasUser) {
            return;
        }

        // MEMBERSHIP, not merely existence. An evaluation finding is
        // confidential to the MDA it is about, and the assignment
        // notification quotes the finding verbatim in its mail subject and
        // body — so an unvalidated user id here delivered another MDA's
        // findings to a stranger's inbox and notification centre. Every
        // sibling assignee field on the platform is already guarded this way
        // (IssueDetail::saveOwner, WorkplanBuilder, WorkplanCreate); this one
        // was the outlier.
        $addressee = User::query()->whereKey($addresseeId)->first();

        if (! $addressee instanceof User || ! app(CheckTenantMembership::class)($addressee)) {
            throw EvaluationRuleViolation::addresseeNotInWorkspace();
        }
    }

    /**
     * The delivery record behind the source, when there is one. An evaluation
     * of a programme and an MDA-level report both have none, and a null
     * project is a perfectly good register entry.
     */
    private function projectOf(Model $source): ?int
    {
        $projectId = $source->getAttribute('project_id');

        if ($projectId !== null) {
            return (int) $projectId;
        }

        // A project is its own subject.
        return $source instanceof Project ? (int) $source->getKey() : null;
    }
}
