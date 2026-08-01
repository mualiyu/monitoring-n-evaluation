<?php

use App\Actions\Iam\AssignRole;
use App\Actions\Iam\GrantTenantAccess;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
*/

/**
 * Base URL for a tenant's subdomain: tenantUrl($tenant, '/projects').
 */
function tenantUrl(Tenant $tenant, string $path = '/'): string
{
    return 'http://'.$tenant->slug.'.'.config('platform.domain').$path;
}

function oversightUrl(string $path = '/'): string
{
    return 'http://oversight.'.config('platform.domain').$path;
}

function portalUrl(string $path = '/'): string
{
    return 'http://'.config('platform.domain').$path;
}

/**
 * Bind a tenant as the current tenancy context (as ResolveTenant would).
 * set() also syncs the spatie permission team.
 */
function actingOnTenant(Tenant $tenant): void
{
    app(CurrentTenant::class)->set($tenant);
}

/**
 * Clear any bound tenancy context (oversight/console context).
 */
function actingWithoutTenant(): void
{
    app(CurrentTenant::class)->forget();
}

/**
 * Ensure a role definition exists (global/null team) — tests migrate without
 * seeding, so definitions are created on demand.
 */
function ensureRoleDefined(Role $role): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeam = $registrar->getPermissionsTeamId();

    $registrar->setPermissionsTeamId(null);
    Spatie\Permission\Models\Role::findOrCreate($role->value, 'web');
    $registrar->setPermissionsTeamId($previousTeam);
}

/**
 * Seed roles + the permission matrix. Tests migrate without seeding, and an
 * unseeded permission answers "no" to everything — which would make an
 * authorization test pass for entirely the wrong reason. Any test that
 * exercises a Policy or an Action calls this first.
 */
function seedPermissions(): void
{
    (new RoleSeeder)->run();
    (new PermissionSeeder)->run();
}

/**
 * Create a user holding $role (role only — no workspace membership).
 * Assignment goes through AssignRole, which enforces the role/team contract.
 */
function userWithRole(Role $role, ?Tenant $tenant = null): User
{
    ensureRoleDefined($role);

    $user = User::factory()->create();
    (new AssignRole)($user, $role, $tenant);

    return $user;
}

/**
 * Grant an existing user full workspace access: membership + tenant role.
 */
function memberOf(User $user, Tenant $tenant, Role $role): User
{
    ensureRoleDefined($role);
    (new GrantTenantAccess)($user, $tenant, $role);

    return $user;
}

/**
 * Create a user with membership + role in $tenant and authenticate as them.
 */
function actingAsMember(Role $role, Tenant $tenant): User
{
    $user = memberOf(User::factory()->create(), $tenant, $role);

    test()->actingAs($user);

    return $user;
}
