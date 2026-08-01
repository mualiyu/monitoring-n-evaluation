<?php

/**
 * LoginResponse (docs/design/auth-surfaces.md §5.5): where a successful sign-in
 * lands is decided by the surface the credentials were presented on, never by
 * the user's roles alone — and an intended URL is honoured only when it points
 * at the very host being signed in to (host-only cookies make a cross-host
 * intended URL an open-redirect-shaped hole).
 */

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

const REDIRECT_PASSWORD = 'Str0ng!Passw0rd#2026';

beforeEach(function () {
    Carbon::setTestNow('2026-08-01 09:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sends a workspace member to that workspace dashboard after signing in', function () {
    $officer = memberOf(
        User::factory()->create(['password' => REDIRECT_PASSWORD]),
        $this->works,
        Role::MeOfficer,
    );

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $officer->email,
        'password' => REDIRECT_PASSWORD,
    ])->assertRedirect(rtrim(tenantUrl($this->works), '/'));
});

it('refuses to sign a non-member into a workspace and points them at their own', function () {
    $officer = memberOf(
        User::factory()->create(['password' => REDIRECT_PASSWORD]),
        $this->health,
        Role::MeOfficer,
    );

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $officer->email,
        'password' => REDIRECT_PASSWORD,
    ])
        ->assertForbidden()
        ->assertSee('Ministry of Health');
});

it('sends a state admin to the oversight dashboard after signing in', function () {
    $admin = userWithRole(Role::StateAdmin); // stamped inside the 2FA grace window
    $admin->forceFill(['password' => REDIRECT_PASSWORD])->save();

    $this->post(oversightUrl('/login'), [
        'email' => $admin->email,
        'password' => REDIRECT_PASSWORD,
    ])->assertRedirect(rtrim(oversightUrl(), '/'));
});

it('never lets a workspace-only account onto the oversight dashboard', function () {
    $officer = memberOf(
        User::factory()->create(['password' => REDIRECT_PASSWORD]),
        $this->works,
        Role::MeOfficer,
    );

    $this->followingRedirects()->post(oversightUrl('/login'), [
        'email' => $officer->email,
        'password' => REDIRECT_PASSWORD,
    ])->assertForbidden();
});

it('ignores an intended URL that points at another host', function () {
    $officer = memberOf(
        User::factory()->create(['password' => REDIRECT_PASSWORD]),
        $this->works,
        Role::MeOfficer,
    );

    $this->withSession(['url.intended' => tenantUrl($this->health, '/projects')]);

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $officer->email,
        'password' => REDIRECT_PASSWORD,
    ])->assertRedirect(rtrim(tenantUrl($this->works), '/'));
});

it('honours an intended URL on the host being signed in to', function () {
    $officer = memberOf(
        User::factory()->create(['password' => REDIRECT_PASSWORD]),
        $this->works,
        Role::MeOfficer,
    );

    $this->withSession(['url.intended' => tenantUrl($this->works, '/two-factor/setup')]);

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $officer->email,
        'password' => REDIRECT_PASSWORD,
    ])->assertRedirect(tenantUrl($this->works, '/two-factor/setup'));
});

it('sends a signed-out user back to the sign-in form on the host they left', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->works);

    $this->post(tenantUrl($this->works, '/logout'))
        ->assertRedirect(tenantUrl($this->works, '/login'));

    $this->assertGuest();
    expect($officer->fresh())->not->toBeNull();
});
