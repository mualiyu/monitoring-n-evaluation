<?php

namespace App\Jobs\Inspections\Concerns;

use App\Enums\Role;
use App\Models\SiteInspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who hears about a site visit.
 *
 * Three audiences, three different reasons, and they are deliberately NOT the
 * same list:
 *
 *  - the INSPECTOR hears that a visit is in their diary;
 *  - the M&E OFFICERS hear that a report has been filed and is waiting for
 *    their sign-off — the separation guard means the inspector cannot clear
 *    it themselves, so somebody has to be told;
 *  - the MDA ADMINS hear about a failing site the moment the verdict is filed.
 *
 * Deliberately not every user in the MDA. A notification that reaches people
 * who cannot act on it is the fastest way to teach a ministry to filter this
 * sender into a folder nobody opens.
 *
 * These read `users`, which is NOT a tenant-scoped table; the role filter runs
 * in the permission team SetTenantContext has bound, so "the M&E officers"
 * means the ones in THIS workspace.
 */
trait ResolvesInspectionRecipients
{
    /**
     * Officers who can sign off a filed report — minus whoever just filed it,
     * who already knows and who could not review it anyway.
     *
     * @return Collection<int, User>
     */
    protected function reviewers(SiteInspection $inspection): Collection
    {
        return User::query()
            ->role([Role::MeOfficer->value, Role::MdaAdmin->value])
            ->where('is_active', true)
            ->whereNot('id', $inspection->submitted_by_id ?? 0)
            ->get();
    }

    /**
     * The people who can halt a payment or call a contractor in.
     *
     * @return Collection<int, User>
     */
    protected function admins(): Collection
    {
        return User::query()
            ->role(Role::MdaAdmin->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * The lead inspector, when the account is still usable.
     *
     * @return Collection<int, User>
     */
    protected function inspector(SiteInspection $inspection): Collection
    {
        return User::query()
            ->whereKey($inspection->lead_inspector_id)
            ->where('is_active', true)
            ->get();
    }
}
