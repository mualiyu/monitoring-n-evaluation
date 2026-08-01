<?php

/**
 * EnsureTenantMembership (docs/design/auth-surfaces.md §2.3): membership is the
 * hard gate — you are in this workspace or you are not, and no role opens a
 * backdoor. Surface-level denial is a deliberate, informative 403; record-level
 * denial stays a silent 404 (asserted in the module tests that own records).
 */

use App\Actions\Iam\RevokeTenantAccess;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
});

it('lets an active member into the workspace they belong to', function () {
    actingAsMember(Role::MeOfficer, $this->works);

    $this->get(tenantUrl($this->works))
        ->assertOk()
        ->assertSee('Ministry of Works');
});

it('refuses the very same member on another workspace host', function () {
    actingAsMember(Role::MeOfficer, $this->works);

    $this->get(tenantUrl($this->health))
        ->assertForbidden()
        ->assertSee('have access to Ministry of Health', false);
});

it('refuses a member whose workspace access has been revoked', function () {
    $user = actingAsMember(Role::Consultant, $this->works);

    $this->get(tenantUrl($this->works))->assertOk();

    (new RevokeTenantAccess)($user, $this->works);

    $this->get(tenantUrl($this->works))->assertForbidden();
});

it('keeps the revoked membership row as the record that access once existed', function () {
    $user = actingAsMember(Role::Consultant, $this->works);

    (new RevokeTenantAccess)($user, $this->works);

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $this->works->id,
        'user_id' => $user->id,
        'status' => 'suspended',
    ]);
});

it('offers the denied user a link to the workspaces they actually belong to', function () {
    $user = actingAsMember(Role::MeOfficer, $this->works);
    memberOf($user, Tenant::factory()->create(['name' => 'Ministry of Water', 'slug' => 'water']), Role::MeOfficer);

    $this->get(tenantUrl($this->health))
        ->assertForbidden()
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Water')
        ->assertSee(tenantUrl($this->works), false)
        ->assertDontSee('Ministry of Health</a>', false);
});

it('does not offer a suspended workspace on the no-access page', function () {
    $user = actingAsMember(Role::MeOfficer, $this->works);
    $water = Tenant::factory()->create(['name' => 'Ministry of Water', 'slug' => 'water']);
    memberOf($user, $water, Role::MeOfficer);

    (new RevokeTenantAccess)($user, $water);

    $this->get(tenantUrl($this->health))
        ->assertForbidden()
        ->assertSee('Ministry of Works')
        ->assertDontSee('Ministry of Water');
});

it('gives a state admin without a membership no backdoor into a workspace', function () {
    $admin = userWithRole(Role::StateAdmin);

    $this->actingAs($admin)
        ->get(tenantUrl($this->works))
        ->assertForbidden();
});

it('gives a super admin without a membership no backdoor into a workspace', function () {
    $admin = userWithRole(Role::SuperAdmin);

    $this->actingAs($admin)
        ->get(tenantUrl($this->works))
        ->assertForbidden();
});

it('sends an unauthenticated visitor to the sign-in form on the host they asked for', function () {
    $this->get(tenantUrl($this->works))
        ->assertRedirect(tenantUrl($this->works, '/login'));

    $this->get(tenantUrl($this->health))
        ->assertRedirect(tenantUrl($this->health, '/login'));
});

it('treats an authenticated stranger as a member of nothing', function () {
    $this->actingAs(User::factory()->create())
        ->get(tenantUrl($this->works))
        ->assertForbidden()
        ->assertSee('not a member of this workspace', false);
});

it('lets a user hold separate memberships in two workspaces', function () {
    $user = actingAsMember(Role::MeOfficer, $this->works);
    memberOf($user, $this->health, Role::Consultant);

    $this->get(tenantUrl($this->works))->assertOk();
    $this->get(tenantUrl($this->health))->assertOk();
});
