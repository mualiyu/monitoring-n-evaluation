<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (the shared trait), plus the one rule specific
 * to the challenges register: `view` narrows through Project::scopeVisibleTo,
 * so a consultant sees issues on the projects they are assigned to and no
 * others. That rule is not written here — it lives in
 * Issue::scopeVisibleTo(), and view() asks the same query the list screens
 * ask, so a policy and a list can never disagree about a single row.
 *
 * THE SEPARATION THAT MATTERS HERE is in the permission matrix, not in code: a
 * consultant holds `issues.create` and neither `issues.update` nor
 * `.resolve`, so "the contractor who reports a problem cannot declare it
 * solved" needs no runtime check to be true. Raising is wide on purpose — the
 * person who sees the problem records it — and everything after it is narrow.
 *
 * What is NOT here: the escalation rung. Escalation is the threshold engine's
 * and no human's (TransitionIssueStatus::assertActor), so there is no ability
 * to grant.
 */
class IssuePolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'issues.view');
    }

    public function view(User $user, Issue $issue): bool
    {
        if (! $this->permits($user, 'issues.view', $issue)) {
            return false;
        }

        // On the oversight surface no tenant is bound, the visibility query
        // has nothing to scope itself to, and the project-level roles it
        // narrows on cannot be held globally in the first place.
        return ! app(CurrentTenant::class)->bound() || $this->isVisible($user, $issue);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'issues.create');
    }

    /**
     * Register upkeep: the owner, the corrective action, the due date, the
     * severity, and the acknowledged / in-progress rungs of the chain.
     */
    public function update(User $user, Issue $issue): bool
    {
        return $this->permits($user, 'issues.update', $issue);
    }

    /** Declaring the obstruction cleared — an M&E judgement. */
    public function resolve(User $user, Issue $issue): bool
    {
        return $this->permits($user, 'issues.resolve', $issue);
    }

    /**
     * The point at which the register stops chasing the item. Narrower than
     * resolving (MdaAdmin only in the matrix), because closing is what removes
     * a row from every board an oversight officer reads.
     */
    public function close(User $user, Issue $issue): bool
    {
        return $this->permits($user, 'issues.close', $issue);
    }

    /**
     * The same narrowing the list screens run, asked of one row. The
     * TenantScope on the query also makes a foreign-tenant issue invisible
     * here, so this is a second, independent answer to "is this yours".
     */
    private function isVisible(User $user, Issue $issue): bool
    {
        return Issue::query()->visibleTo($user)->whereKey($issue->getKey())->exists();
    }
}
