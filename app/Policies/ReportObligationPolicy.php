<?php

namespace App\Policies;

use App\Models\ReportObligation;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;

/**
 * Obligations are the compliance record of an MDA, so they are readable by
 * anyone who may read reports and waivable only by the two roles that answer
 * for compliance: the MDA admin and state oversight (progress-reporting.md §4).
 */
class ReportObligationPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'reports.view');
    }

    public function view(User $user, ReportObligation $obligation): bool
    {
        return $this->permits($user, 'reports.view', $obligation);
    }

    public function waive(User $user, ReportObligation $obligation): bool
    {
        return $this->permits($user, 'reports.waive', $obligation);
    }
}
