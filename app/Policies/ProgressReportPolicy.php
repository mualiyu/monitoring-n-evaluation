<?php

namespace App\Policies;

use App\Models\ProgressReport;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (the shared trait), plus the two rules specific
 * to progress reports (progress-reporting.md §4):
 *
 *  1. `view` narrows through Project::scopeVisibleTo, so a consultant sees
 *     reports on the projects they are assigned to and no others.
 *  2. `update` / `discard` are AUTHOR rules on top of the permission: holding
 *     `reports.create` lets you write your own return, not edit someone else's.
 *
 * What is NOT here: the approval-chain separation guards (reviewer ≠ submitter,
 * approver ≠ reviewer). Those are domain rules about *who already acted on
 * this row*, they are enforced in TransitionProgressReportStatus, and putting
 * them here would make an authorization answer depend on chain history that a
 * UI would have to duplicate. The structural half of the guard — a consultant
 * holds neither `reports.review` nor `reports.approve` — is right here.
 */
class ProgressReportPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'reports.view');
    }

    public function view(User $user, ProgressReport $report): bool
    {
        if (! $this->permits($user, 'reports.view', $report)) {
            return false;
        }

        // On the oversight surface no tenant is bound, the visibility query
        // has nothing to scope itself to, and the project-level roles it
        // narrows on cannot be held globally in the first place.
        return ! app(CurrentTenant::class)->bound() || $this->isVisible($user, $report);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'reports.create');
    }

    /**
     * Editing a draft. Author-only, because two people typing into one return
     * is how a figure gets overwritten between the officer's screen and the
     * consultant's. An M&E officer who needs to correct a consultant's draft
     * returns it instead — that leaves a record.
     */
    public function update(User $user, ProgressReport $report): bool
    {
        return $this->permits($user, 'reports.create', $report)
            && $report->created_by_id === $user->id
            && $report->isEditable();
    }

    /**
     * Filing the return. The author files their own; an M&E officer or MDA
     * admin (anyone holding `reports.review`) may file a stalled draft on the
     * on-behalf path — and `submitted_by_id` records which of the two it was,
     * which is exactly what the separation guards later read.
     */
    public function submit(User $user, ProgressReport $report): bool
    {
        if (! $this->permits($user, 'reports.submit', $report)) {
            return false;
        }

        return $report->created_by_id === $user->id || $this->permits($user, 'reports.review', $report);
    }

    public function review(User $user, ProgressReport $report): bool
    {
        return $this->permits($user, 'reports.review', $report);
    }

    public function approve(User $user, ProgressReport $report): bool
    {
        return $this->permits($user, 'reports.approve', $report);
    }

    public function discard(User $user, ProgressReport $report): bool
    {
        return $this->permits($user, 'reports.create', $report)
            && $report->created_by_id === $user->id;
    }

    private function isVisible(User $user, ProgressReport $report): bool
    {
        return ProgressReport::query()
            ->visibleTo($user)
            ->whereKey($report->getKey())
            ->exists();
    }
}
