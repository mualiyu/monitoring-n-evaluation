<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission → role matrix (projects module design §3). Idempotent:
 * permissions are findOrCreate'd and each role's set is synced, so re-running
 * converges rather than accumulating.
 *
 * Runs in the global (null-team) context: role definitions are global, and
 * role_has_permissions carries no team column — a role means the same thing in
 * every MDA; which MDA's records it applies to is the TenantScope's job.
 *
 * SuperAdmin holds every permission EXPLICITLY — there is no Gate::before
 * bypass anywhere in this platform, because a scope bypass dressed as a role
 * is exactly how cross-tenant leaks happen.
 *
 * Depends on RoleSeeder having defined the roles (DatabaseSeeder order).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        app(CurrentTenant::class)->runWithoutTenant(function (): void {
            $matrix = $this->matrix();

            foreach (array_keys($matrix) as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            foreach (RoleEnum::cases() as $role) {
                $permissions = array_keys(array_filter(
                    $matrix,
                    fn (array $roles): bool => in_array($role, $roles, true),
                ));

                Role::findByName($role->value, 'web')->syncPermissions($permissions);
            }
        });

        $registrar->forgetCachedPermissions();
    }

    /**
     * permission => roles that hold it.
     *
     * Two readings of the design's matrix had to be settled here:
     *  - ExecutiveViewer and DataQualityReviewer get `contractors.view` but
     *    NOT `.create`: both are read-only oversight roles (design §7 asserts
     *    "ExecutiveViewer is read-only"), and registry writes are a tenant-user
     *    or oversight-management act.
     *  - `documents.delete` is absent from the matrix table; it follows
     *    `projects.delete` (SuperAdmin + MdaAdmin), the roles that already own
     *    record removal.
     *
     * @return array<string, list<RoleEnum>>
     */
    private function matrix(): array
    {
        $oversight = [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::ExecutiveViewer, RoleEnum::DataQualityReviewer];
        $mdaStaff = [RoleEnum::MdaAdmin, RoleEnum::MeOfficer];
        $field = [RoleEnum::Consultant, RoleEnum::FieldMonitor];

        return [
            // Projects
            'projects.view' => [...$oversight, ...$mdaStaff, ...$field], // field roles are further narrowed to assigned projects
            'projects.create' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'projects.update' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'projects.delete' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],
            'projects.award' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'projects.status.update' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'projects.certify' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],
            'projects.close' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],
            'projects.suspend' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],
            'projects.cancel' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],
            'projects.progress.update' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'projects.assign' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            // Publishing is the Phase 3 portal gate; nothing reads it yet, but
            // the authority to flip it is deliberately narrow from day one.
            'projects.publish' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],

            // Contracts
            'contracts.view' => [...$oversight, ...$mdaStaff],
            'contracts.create' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'contracts.update' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'contracts.delete' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],

            // Contractors — global registry: create is wide, management is not.
            'contractors.view' => [...$oversight, ...$mdaStaff],
            'contractors.create' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, ...$mdaStaff],
            'contractors.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin],

            // Indicators — consultants read the framework they report against
            // but never edit it; activation (which unlocks readings) is an
            // M&E act, not a contractor's.
            'indicators.view' => [...$oversight, ...$mdaStaff, RoleEnum::Consultant],
            'indicators.create' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'indicators.update' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'indicators.activate' => [RoleEnum::SuperAdmin, ...$mdaStaff],

            // Documents — collection-level rules (photos only for field roles)
            // live in config/documents.php, not in the permission name.
            'documents.view' => [...$oversight, ...$mdaStaff, ...$field],
            'documents.upload' => [RoleEnum::SuperAdmin, ...$mdaStaff, ...$field],
            'documents.delete' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],

            // Progress reporting (progress-reporting.md §4). The approval
            // chain is a permission structure before it is a guard: a
            // consultant holds `reports.create|submit` and NEVER `.review` or
            // `.approve`, which is why "a consultant cannot approve their own
            // report" needs no runtime check to be true. The identity guards
            // in TransitionProgressReportStatus exist for the on-behalf path,
            // where an M&E officer submits and could otherwise clear it.
            //
            // FieldMonitor gets `reports.view` only: an inspector reads the
            // return they are verifying but does not file it.
            'reports.view' => [...$oversight, ...$mdaStaff, ...$field],
            'reports.create' => [RoleEnum::SuperAdmin, ...$mdaStaff, RoleEnum::Consultant],
            'reports.submit' => [RoleEnum::SuperAdmin, ...$mdaStaff, RoleEnum::Consultant],
            'reports.review' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            // Approval moves the project's attested figures through
            // RecordProjectProgress, so it sits with the same authority that
            // already holds `projects.progress.update` at director level.
            'reports.approve' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],
            'reports.waive' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],

            // Indicator readings & the results framework (Phase 2). Recording
            // an actual is delivery work; VALIDATING one is assurance, so the
            // two never sit with the same person — the Data Quality Reviewer
            // role exists precisely to break that loop (plan §2). Publishing
            // is narrower still: it is what makes a figure quotable outside
            // the platform.
            'frameworks.view' => [...$oversight, ...$mdaStaff, RoleEnum::Consultant],
            'frameworks.manage' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'indicators.readings.record' => [RoleEnum::SuperAdmin, ...$mdaStaff, RoleEnum::Consultant],
            'indicators.readings.submit' => [RoleEnum::SuperAdmin, ...$mdaStaff, RoleEnum::Consultant],
            'indicators.readings.validate' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::DataQualityReviewer],
            'indicators.readings.publish' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin],
            'indicators.library.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin],

            // Monitoring lifecycle (Phase 2). A FieldMonitor conducts and
            // files an inspection but never reviews one — the inspector is
            // not the assurance over their own field work.
            'commencement.view' => [...$oversight, ...$mdaStaff, ...$field],
            'commencement.issue' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'inspections.view' => [...$oversight, ...$mdaStaff, ...$field],
            'inspections.schedule' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'inspections.conduct' => [RoleEnum::SuperAdmin, ...$mdaStaff, RoleEnum::FieldMonitor],
            'inspections.review' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'certificates.view' => [...$oversight, ...$mdaStaff, ...$field],
            'certificates.issue' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],

            // Challenges register + exception reports. Raising is wide on
            // purpose: the manual's whole point is that the person who SEES
            // the problem records it, including the contractor.
            'issues.view' => [...$oversight, ...$mdaStaff, ...$field],
            'issues.create' => [RoleEnum::SuperAdmin, ...$mdaStaff, ...$field],
            'issues.update' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'issues.resolve' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'issues.close' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],
            'exceptions.view' => [...$oversight, ...$mdaStaff, ...$field],
            'exceptions.create' => [RoleEnum::SuperAdmin, ...$mdaStaff, ...$field],
            'exceptions.resolve' => [RoleEnum::SuperAdmin, ...$mdaStaff],

            // Annual work plans (manual §5): each activity hangs off an output
            // indicator, so managing a plan is the same authority as managing
            // the framework it reports into. Approval is the director's.
            'workplans.view' => [...$oversight, ...$mdaStaff, ...$field],
            'workplans.manage' => [RoleEnum::SuperAdmin, ...$mdaStaff],
            'workplans.approve' => [RoleEnum::SuperAdmin, RoleEnum::MdaAdmin],

            // Evaluations + the recommendations follow-up register. The
            // secretariat commissions evaluations of MDAs, so StateAdmin holds
            // management rights here that it does not hold over an MDA's own
            // delivery records.
            'evaluations.view' => [...$oversight, ...$mdaStaff],
            'evaluations.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, ...$mdaStaff],
            'evaluations.approve' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],
            'recommendations.view' => [...$oversight, ...$mdaStaff],
            'recommendations.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, ...$mdaStaff],

            // Stakeholder feedback (portal). Moderation is an MDA act over its
            // own projects and a state act everywhere; the field roles hold
            // none of it — a contractor moderating complaints about their own
            // work is the conflict of interest the portal exists to expose.
            'feedback.view' => [...$oversight, ...$mdaStaff],
            'feedback.moderate' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, ...$mdaStaff],
            'feedback.respond' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, ...$mdaStaff],

            // Oversight
            'oversight.portfolio.view' => $oversight,
            'oversight.compliance.view' => $oversight,
            // The cross-MDA reports desk and the consolidation workspace.
            // Consolidation WRITES a state artifact, so it is secretariat-only
            // while reading the desk is open to every oversight role.
            'oversight.reports.view' => $oversight,
            'oversight.consolidation.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin],
            'oversight.validation.review' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::DataQualityReviewer],
            'oversight.audit.view' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin],
            // Provisioning an MDA workspace is platform administration: it
            // creates a subdomain and a permission team, not a record.
            'tenants.view' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::ExecutiveViewer],
            'tenants.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin],
            'settings.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],

            // Users & workspace membership (auth-surfaces.md §2–3). These
            // decide who may SEE and OPERATE the member-management screens;
            // WHICH roles an inviter may offer is a separate contract that
            // lives on the enum (Role::invitableBy) and inside InviteUser.
            // ExecutiveViewer and DataQualityReviewer hold none of them:
            // staffing the platform is administration, not analysis, and both
            // are read-only roles (design §7).
            'users.view' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, ...$mdaStaff],
            'users.invite' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],
            'users.manage' => [RoleEnum::SuperAdmin, RoleEnum::StateAdmin, RoleEnum::MdaAdmin],
        ];
    }
}
