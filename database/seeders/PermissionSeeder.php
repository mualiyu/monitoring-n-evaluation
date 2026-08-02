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

            // Oversight
            'oversight.portfolio.view' => $oversight,
            'oversight.compliance.view' => $oversight,

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
