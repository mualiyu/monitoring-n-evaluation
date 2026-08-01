<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles are defined once, globally (tenant_id null on the role row). Team
 * scoping happens at assignment time: assigning a tenant role while a tenant
 * team id is set records the membership for that MDA only.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Role definitions are global (null team): assignable in any tenant
        // context, with membership recorded per-tenant on the pivot.
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            foreach (RoleEnum::cases() as $role) {
                Role::findOrCreate($role->value, 'web');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
