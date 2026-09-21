<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (the shared trait), plus the two rules specific
 * to evaluations:
 *
 *  1. `view` narrows through Evaluation::scopeVisibleTo, so a user who cannot
 *     see a project cannot see the evaluation OF that project. Programme-level
 *     evaluations (no project) are visible to anyone the permission admitted.
 *  2. `update` is a STATE rule on top of the permission: findings freeze when
 *     the report goes up for review, so holding `evaluations.manage` lets you
 *     write a draft, not rewrite an approved finding.
 *
 * What is NOT here: the separation-of-duties guard (the lead cannot approve
 * their own evaluation). That is a domain rule about *who already acted on
 * this row*, it is enforced in TransitionEvaluationStatus, and putting it here
 * would make an authorization answer depend on roster history that every
 * screen would then have to duplicate. The STRUCTURAL half of the guard — an
 * M&E officer holds `evaluations.manage` but not `evaluations.approve` — is
 * right here in the seeded matrix.
 */
class EvaluationPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'evaluations.view');
    }

    public function view(User $user, Evaluation $evaluation): bool
    {
        if (! $this->permits($user, 'evaluations.view', $evaluation)) {
            return false;
        }

        // On the oversight surface no tenant is bound, the visibility query
        // has nothing to scope itself to, and the project-level roles it
        // narrows on cannot be held globally in the first place.
        return ! app(CurrentTenant::class)->bound() || $evaluation->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'evaluations.manage');
    }

    /**
     * Writing the commission, the team, the scorecard and the report. The
     * state check is the freeze: an approved evaluation's findings are what an
     * approver signed for, and a module that let them be edited afterwards
     * would make the signature worthless.
     */
    public function update(User $user, Evaluation $evaluation): bool
    {
        return $this->permits($user, 'evaluations.manage', $evaluation)
            && $evaluation->isEditable();
    }

    /** Sending the drafted report up for review — the team's own act. */
    public function submit(User $user, Evaluation $evaluation): bool
    {
        return $this->permits($user, 'evaluations.manage', $evaluation);
    }

    public function approve(User $user, Evaluation $evaluation): bool
    {
        return $this->permits($user, 'evaluations.approve', $evaluation);
    }

    /**
     * Publication is the portal gate (rules/security.md): what becomes public
     * is an explicit act by the authority that can also approve, never a side
     * effect of approval.
     */
    public function publish(User $user, Evaluation $evaluation): bool
    {
        return $this->permits($user, 'evaluations.approve', $evaluation);
    }

    /**
     * Cancelling a commission. Deliberately the APPROVE authority rather than
     * `manage`: abandoning an evaluation is the one move that can make an
     * inconvenient finding go away, so it sits with the same people who would
     * otherwise have to approve it, and it always carries a reason.
     */
    public function cancel(User $user, Evaluation $evaluation): bool
    {
        return $this->permits($user, 'evaluations.approve', $evaluation);
    }

    /**
     * Raising follow-up entries from this evaluation. The recommendation's own
     * policy governs the register; this answers "may you write against THIS
     * evaluation", which is the evaluation's question.
     */
    public function recommend(User $user, Evaluation $evaluation): bool
    {
        return $this->permits($user, 'recommendations.manage', $evaluation);
    }
}
