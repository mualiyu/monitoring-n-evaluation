<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match, plus the same project-visibility narrowing the
 * commencement policy applies (see CommencementNoticePolicy for why it is a
 * query rather than a relation read).
 *
 * `certificates.issue` is deliberately narrower than `certificates.view`:
 * everyone in the reporting chain may read the register, and only SuperAdmin
 * and MdaAdmin sign a completion certificate — the same authority that holds
 * `projects.certify`, because the two acts are one act.
 */
class CertificatePolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'certificates.view');
    }

    public function view(User $user, Certificate $certificate): bool
    {
        if (! $this->permits($user, 'certificates.view', $certificate)) {
            return false;
        }

        return ! app(CurrentTenant::class)->bound()
            || Project::query()->visibleTo($user)->whereKey($certificate->project_id)->exists();
    }

    /**
     * The project is passed rather than a certificate: the row does not exist
     * until the signature does.
     */
    public function issue(User $user, ?Project $project = null): bool
    {
        return $this->permits($user, 'certificates.issue', $project);
    }

    /**
     * Withdrawal is the same authority as issue. Anything looser would let a
     * role that cannot sign a certificate unsign one.
     */
    public function revoke(User $user, Certificate $certificate): bool
    {
        return $this->permits($user, 'certificates.issue', $certificate);
    }
}
