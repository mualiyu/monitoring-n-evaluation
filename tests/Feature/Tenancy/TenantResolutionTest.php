<?php

use App\Enums\Role;
use App\Models\Tenant;

it('redirects guests on a tenant subdomain to that host\'s login', function () {
    $tenant = Tenant::factory()->create(['slug' => 'works']);

    $this->get(tenantUrl($tenant))
        ->assertRedirect()
        ->assertRedirectContains('/login');
});

it('resolves an active tenant subdomain to its workspace for a member', function () {
    $tenant = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingAsMember(Role::MeOfficer, $tenant);

    $this->get(tenantUrl($tenant))
        ->assertOk()
        ->assertSee('Ministry of Works');
});

it('returns 404 for an unknown tenant subdomain', function () {
    $this->get('http://ghost.'.config('platform.domain').'/')
        ->assertNotFound();
});

it('returns 404 for an inactive tenant', function () {
    $tenant = Tenant::factory()->inactive()->create(['slug' => 'dormant']);

    $this->get(tenantUrl($tenant))->assertNotFound();
});

it('returns 404 for a soft-deleted tenant', function () {
    $tenant = Tenant::factory()->create(['slug' => 'gone']);
    $tenant->delete();

    $this->get(tenantUrl($tenant))->assertNotFound();
});

it('404s reserved subdomains even if a tenant somehow claims the slug', function () {
    Tenant::factory()->create(['slug' => 'api']);

    $this->get('http://api.'.config('platform.domain').'/')->assertNotFound();
});

it('rejects multi-label subdomain hosts', function () {
    Tenant::factory()->create(['slug' => 'works']);

    $this->get('http://evil.works.'.config('platform.domain').'/')->assertNotFound();
});

it('requires an oversight role on the oversight surface', function () {
    $this->get(oversightUrl())
        ->assertRedirect()
        ->assertRedirectContains('/login');

    $admin = userWithRole(Role::StateAdmin);
    $admin->forceFill(['two_factor_required_at' => now()])->save(); // inside grace

    $this->actingAs($admin)
        ->get(oversightUrl())
        ->assertOk()
        ->assertSee('State dashboard');
});

it('serves the public portal on the apex domain without auth', function () {
    $this->get(portalUrl())
        ->assertOk()
        ->assertSee(config('platform.instance.name'));
});

it('keeps each workspace scoped to its own subdomain', function () {
    Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);

    actingAsMember(Role::MeOfficer, $works);

    $this->get(tenantUrl($works))
        ->assertOk()
        ->assertSee('Ministry of Works')
        ->assertDontSee('Ministry of Health');
});

it('403s a member of another workspace, listing their own workspaces', function () {
    $works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingAsMember(Role::MeOfficer, $works);

    $this->get(tenantUrl($health))
        ->assertForbidden()
        ->assertSee('Ministry of Works'); // their real workspace is offered
});

it('gives oversight admins no tenant backdoor', function () {
    $tenant = Tenant::factory()->create(['slug' => 'works']);
    $admin = userWithRole(Role::StateAdmin);
    $admin->forceFill(['two_factor_required_at' => now()])->save();

    $this->actingAs($admin)
        ->get(tenantUrl($tenant))
        ->assertForbidden();
});
