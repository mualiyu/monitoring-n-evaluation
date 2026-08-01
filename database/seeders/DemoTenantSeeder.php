<?php

namespace Database\Seeders;

use App\Actions\Iam\AssignRole;
use App\Actions\Iam\GrantTenantAccess;
use App\Enums\Role;
use App\Enums\TenantType;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * Two demo MDAs with a user per role — the local development state.
 * Demo-only names; production tenants are onboarded through oversight.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        // Demo credentials (factory password) must never reach production.
        if (app()->isProduction()) {
            return;
        }

        $assignRole = new AssignRole;
        $current = app(CurrentTenant::class);
        $current->forget();

        $oversightUsers = [
            ['Platform Admin', 'admin@mne.test', Role::SuperAdmin],
            ['State M&E Director', 'state@mne.test', Role::StateAdmin],
            ['Executive Viewer', 'governor@mne.test', Role::ExecutiveViewer],
        ];

        foreach ($oversightUsers as [$name, $email, $role]) {
            $assignRole(
                User::factory()->create(['name' => $name, 'email' => $email]),
                $role,
            );
        }

        $tenants = [
            ['name' => 'Ministry of Works & Infrastructure', 'slug' => 'works', 'type' => TenantType::Ministry],
            ['name' => 'Ministry of Health', 'slug' => 'health', 'type' => TenantType::Ministry],
        ];

        $grantAccess = new GrantTenantAccess;

        foreach ($tenants as $attributes) {
            $tenant = Tenant::factory()->create($attributes);

            foreach (Role::tenantRoles() as $role) {
                // Membership (hard gate) + role (soft gate) in one grant.
                $grantAccess(
                    User::factory()->create([
                        'name' => $role->label().' — '.$tenant->name,
                        'email' => $role->value.'@'.$tenant->slug.'.mne.test',
                    ]),
                    $tenant,
                    $role,
                );
            }
        }

        $current->forget();
    }
}
