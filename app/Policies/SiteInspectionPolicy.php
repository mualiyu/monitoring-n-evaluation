<?php

namespace App\Policies;

use App\Models\SiteInspection;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match (the shared trait), plus the rules specific to
 * site inspections:
 *
 *  1. `view` narrows through SiteInspection::scopeVisibleTo, so a consultant
 *     sees visits on the projects they are assigned to, and a field monitor
 *     additionally sees any visit they personally lead.
 *  2. `conduct` is a LEAD-INSPECTOR rule on top of the permission: holding
 *     `inspections.conduct` lets you conduct the visits assigned to you, not
 *     to walk into someone else's. MDA staff (who also hold
 *     `inspections.review`) can conduct any visit in the workspace — a
 *     director covering for an absent monitor is normal, and the chokepoint's
 *     separation guard is what stops them then signing it off.
 *
 * What is NOT here: the separation-of-duties guard (the inspector never
 * reviews their own inspection). That is a domain rule about who already acted
 * on this row, it is enforced in TransitionInspectionStatus, and putting it
 * here would make an authorization answer depend on chain history that a UI
 * would have to duplicate. The structural half of the guard — a FieldMonitor
 * holds `inspections.conduct` and never `inspections.review` — is right here.
 */
class SiteInspectionPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'inspections.view');
    }

    public function view(User $user, SiteInspection $inspection): bool
    {
        if (! $this->permits($user, 'inspections.view', $inspection)) {
            return false;
        }

        // On the oversight surface no tenant is bound, the visibility query
        // has nothing to scope itself to, and the project-level roles it
        // narrows on cannot be held globally in the first place.
        return ! app(CurrentTenant::class)->bound() || $this->isVisible($user, $inspection);
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'inspections.schedule');
    }

    /**
     * Editing the diary entry itself (date, team, instrument) before the visit
     * happens. Once the inspector is on site the conduct form owns the record.
     */
    public function update(User $user, SiteInspection $inspection): bool
    {
        return $this->permits($user, 'inspections.schedule', $inspection)
            && $inspection->status->isOpen();
    }

    /**
     * Opening the conduct form, answering the checklist, filing the report.
     * The named lead always may; so may MDA staff, who cover for absent
     * monitors and type up visits made on paper.
     */
    public function conduct(User $user, SiteInspection $inspection): bool
    {
        if (! $this->permits($user, 'inspections.conduct', $inspection)) {
            return false;
        }

        return $inspection->lead_inspector_id === $user->id
            || $this->permits($user, 'inspections.review', $inspection);
    }

    public function review(User $user, SiteInspection $inspection): bool
    {
        return $this->permits($user, 'inspections.review', $inspection);
    }

    /**
     * Taking a visit out of the diary. A scheduling act, so it sits with
     * `inspections.schedule` — and the lifecycle table already refuses to
     * cancel anything that has been filed.
     */
    public function cancel(User $user, SiteInspection $inspection): bool
    {
        return $this->permits($user, 'inspections.schedule', $inspection)
            && $inspection->status->isOpen();
    }

    private function isVisible(User $user, SiteInspection $inspection): bool
    {
        return SiteInspection::query()
            ->visibleTo($user)
            ->whereKey($inspection->getKey())
            ->exists();
    }
}
