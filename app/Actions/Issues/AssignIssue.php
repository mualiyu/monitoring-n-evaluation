<?php

namespace App\Actions\Issues;

use App\Actions\Iam\CheckTenantMembership;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Jobs\Issues\NotifyIssueAssigned;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Giving an obstruction an owner — the single most load-bearing field on the
 * register, because an issue with no name against it is an issue nobody is
 * accountable for clearing.
 *
 * The owner is rarely the raiser: a consultant reports that cash has not been
 * released, and an M&E officer owns chasing it. That is why assignment is a
 * separate act under `issues.update` rather than a field on the raise form
 * that a contractor fills in for themselves.
 */
class AssignIssue
{
    // Injected rather than resolved inline: the container builds this Action
    // at every call site (Livewire method injection and app()), and a
    // constructor dependency states the collaboration instead of hiding it in
    // a service-location call halfway down the method.
    public function __construct(private CheckTenantMembership $membership) {}

    public function __invoke(Issue $issue, ?User $owner, User $actor): Issue
    {
        Gate::forUser($actor)->authorize('update', $issue);

        if (! $issue->status->isEditable()) {
            throw IssueRuleViolation::notEditable($issue->status);
        }

        if ($owner !== null && ! ($this->membership)($owner)) {
            // Membership, not merely permission: someone who cannot open this
            // workspace cannot open the issue either, and an assignment they
            // will never see is worse than no assignment at all.
            throw IssueRuleViolation::ownerNotInWorkspace();
        }

        $previousOwnerId = $issue->owner_id;

        $issue->owner_id = $owner?->id;
        $issue->save();

        // Silent when nothing changed, and silent when someone takes an issue
        // themselves — telling people what they just did is how a channel
        // teaches its readers to ignore it.
        if ($owner !== null && $owner->id !== $previousOwnerId && $owner->id !== $actor->id) {
            NotifyIssueAssigned::dispatch($issue->id, $owner->id, $actor->id);
        }

        return $issue;
    }
}
