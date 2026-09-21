<?php

namespace App\Policies;

use App\Models\ReportExport;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Authority over a generated artifact.
 *
 * The register is GLOBAL — it records oversight exports (which cross every MDA
 * and belong to none) beside workspace exports (which do not) — so
 * `generated_for_tenant_id` is provenance rather than a scope key and the
 * tenancy half of "permission AND tenant match" is enforced HERE instead of by
 * the database. That makes this policy the only thing standing between a
 * workspace export and another workspace, so read the `download` rule closely.
 *
 * There is no `delete`. An export register is an audit record of data leaving
 * a government platform (rules/security.md — append-only); files are pruned by
 * retention, the row that says who took them never is.
 */
class ReportExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsGlobalPermission('oversight.reports.view');
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->reachable($user, $export);
    }

    /**
     * Handing the file over.
     *
     * Three gates, all of which must pass:
     *  1. the artifact is ready, unexpired and still on disk;
     *  2. if it was generated FOR a workspace and a workspace is bound now,
     *     it is the same workspace — this is the cross-tenant gate, and it is
     *     the reason a global register is safe;
     *  3. the requester either generated it or holds oversight read authority.
     */
    public function download(User $user, ReportExport $export): bool
    {
        return $export->isDownloadable() && $this->reachable($user, $export);
    }

    public function create(User $user): bool
    {
        return $user->holdsGlobalPermission('oversight.reports.view');
    }

    private function reachable(User $user, ReportExport $export): bool
    {
        if (! $this->provenanceMatches($export)) {
            return false;
        }

        // The generator can always retrieve their own artifact — they already
        // held the authority that produced it, and the filters that produced
        // it are recorded against their name.
        return $export->generated_by_id === $user->id
            || $user->holdsGlobalPermission('oversight.reports.view');
    }

    /**
     * A workspace-scoped artifact is reachable from ITS workspace, or from the
     * oversight surface where no workspace is bound at all (the state may read
     * every MDA's artifacts; that is what the surface is for). A workspace may
     * never reach another workspace's artifact — which is the case a global
     * register has to get right, and the one the isolation test proves.
     */
    private function provenanceMatches(ReportExport $export): bool
    {
        $bound = app(CurrentTenant::class)->id();

        if ($export->generated_for_tenant_id === null || $bound === null) {
            return true;
        }

        return $export->generated_for_tenant_id === $bound;
    }
}
