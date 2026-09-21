<?php

namespace App\Policies;

use App\Models\Recommendation;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (the shared trait), narrowed by the project a
 * recommendation bites on so that a user who cannot see a project cannot read
 * the findings raised against it.
 *
 * `transition` is separated from `update` on purpose. Editing the TEXT of a
 * recommendation — its wording, its cost, its deadline — and moving it through
 * the follow-up loop are different acts with different consequences: the first
 * changes what was said, the second records what was done about it. A closed
 * recommendation may never be re-worded (the evidence trail would no longer
 * match the finding), while an open one is still being drafted.
 */
class RecommendationPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'recommendations.view');
    }

    public function view(User $user, Recommendation $recommendation): bool
    {
        if (! $this->permits($user, 'recommendations.view', $recommendation)) {
            return false;
        }

        // On the oversight surface no tenant is bound and the visibility query
        // has nothing to scope itself to; the cross-MDA read is authorized in
        // app/Actions/Oversight instead.
        return ! app(CurrentTenant::class)->bound() || $recommendation->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'recommendations.manage');
    }

    /**
     * Re-wording the recommendation itself. Only while it is still open: once
     * someone has accepted it, the text is what they accepted, and editing it
     * afterwards would quietly change the thing the evidence is evidence FOR.
     */
    public function update(User $user, Recommendation $recommendation): bool
    {
        return $this->permits($user, 'recommendations.manage', $recommendation)
            && ! $recommendation->status->isTerminal();
    }

    /**
     * Moving it through the follow-up loop: accept, start, implement, decline,
     * supersede. The guards on each move (a reason, evidence, a named
     * replacement) belong to TransitionRecommendationStatus — they are facts
     * about the move, not about the mover.
     */
    public function transition(User $user, Recommendation $recommendation): bool
    {
        return $this->permits($user, 'recommendations.manage', $recommendation);
    }
}
