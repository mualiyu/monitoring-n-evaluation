<?php

namespace App\Policies;

use App\Models\ConsolidatedReport;
use App\Models\User;

/**
 * Authority over a state consolidation.
 *
 * ⚠ This policy deliberately does NOT use ChecksTenantAuthority. The platform
 * rule is "permission AND tenant match", and here the second half has no
 * subject: a consolidation is a state artifact that belongs to no MDA (see the
 * model). Reaching for the shared trait would ask whether a record with no
 * tenant_id belongs to the bound tenant — a question with no true answer that
 * would fail closed for everyone, including the secretariat that owns the row.
 *
 * So authority is read from the GLOBAL permission team and nowhere else. That
 * is stricter, not looser: a role held inside an MDA workspace grants nothing
 * here however the request arrived, and AssignRole refuses to place a tenant
 * role in the global team in the first place.
 *
 * Two permissions, two audiences:
 *  - `oversight.reports.view` — every oversight role may READ the state's
 *    roll-ups; that is what oversight is for.
 *  - `oversight.consolidation.manage` — only the secretariat WRITES one.
 *    Compiling, drafting, submitting, approving and publishing are all this
 *    permission; the separation of compiler from approver is a DOMAIN rule
 *    about who already acted on the row, and it lives in
 *    TransitionConsolidationStatus where the chain history can be read.
 */
class ConsolidatedReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsGlobalPermission('oversight.reports.view');
    }

    public function view(User $user, ConsolidatedReport $report): bool
    {
        return $user->holdsGlobalPermission('oversight.reports.view');
    }

    public function create(User $user): bool
    {
        return $user->holdsGlobalPermission('oversight.consolidation.manage');
    }

    /** Editing the narrative. Only while the report is still open to editing. */
    public function update(User $user, ConsolidatedReport $report): bool
    {
        return $this->manages($user) && $report->isEditable();
    }

    /** Running (or re-running) the roll-up. Same window as editing. */
    public function compile(User $user, ConsolidatedReport $report): bool
    {
        return $this->manages($user) && $report->isEditable();
    }

    public function submit(User $user, ConsolidatedReport $report): bool
    {
        return $this->manages($user);
    }

    /**
     * Sending it back for rework. The permission is the same; WHO may not
     * approve after acting is enforced in the chokepoint, not here, because an
     * authorization answer must not depend on chain history a UI would then
     * have to duplicate.
     */
    public function return(User $user, ConsolidatedReport $report): bool
    {
        return $this->manages($user);
    }

    public function approve(User $user, ConsolidatedReport $report): bool
    {
        return $this->manages($user);
    }

    public function publish(User $user, ConsolidatedReport $report): bool
    {
        return $this->manages($user);
    }

    /**
     * Exporting the artifact. Anyone who may read it may take a copy — the
     * export is a rendering of what is already on their screen, and every one
     * of them is recorded in report_exports.
     */
    public function export(User $user, ConsolidatedReport $report): bool
    {
        return $this->view($user, $report);
    }

    private function manages(User $user): bool
    {
        return $user->holdsGlobalPermission('oversight.consolidation.manage');
    }
}
