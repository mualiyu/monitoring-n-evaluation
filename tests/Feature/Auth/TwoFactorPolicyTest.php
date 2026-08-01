<?php

/**
 * RequireTwoFactor (docs/design/auth-surfaces.md §4): 2FA enrolment is an
 * authorization rule enforced on every request, anchored on the moment the
 * mandating role was granted — so a promotion mid-life starts its own grace
 * window. SuperAdmin gets none. All time here is frozen, never slept through.
 */

use App\Actions\Iam\AssignRole;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-08-01 09:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stamps the two-factor deadline the moment a mandating role is granted', function () {
    $user = User::factory()->create();

    expect($user->two_factor_required_at)->toBeNull();

    memberOf($user, $this->works, Role::MdaAdmin);

    expect($user->fresh()->two_factor_required_at->toDateTimeString())->toBe('2026-08-01 09:00:00');
});

it('stamps no deadline for a role that does not mandate two-factor', function (Role $role) {
    $user = memberOf(User::factory()->create(), $this->works, $role);

    expect($user->fresh()->two_factor_required_at)->toBeNull();
})->with([Role::MeOfficer, Role::Consultant, Role::FieldMonitor]);

it('keeps the original deadline when a second mandating role is granted later', function () {
    $user = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    Carbon::setTestNow('2026-08-04 09:00:00');
    ensureRoleDefined(Role::StateAdmin);
    (new AssignRole)($user, Role::StateAdmin);

    expect($user->fresh()->two_factor_required_at->toDateTimeString())->toBe('2026-08-01 09:00:00');
});

it('lets an MDA admin keep working inside the two-factor grace period', function () {
    actingAsMember(Role::MdaAdmin, $this->works);

    Carbon::setTestNow('2026-08-07 09:00:00'); // day 6 of 7

    $this->get(tenantUrl($this->works))
        ->assertOk()
        ->assertSessionHas('two_factor_deadline'); // the countdown banner
});

it('forces an MDA admin to enrol once the grace period has run out', function () {
    actingAsMember(Role::MdaAdmin, $this->works);

    Carbon::setTestNow('2026-08-08 09:00:01'); // one second past 7 days

    $this->get(tenantUrl($this->works))
        ->assertRedirect(tenantUrl($this->works, '/two-factor/setup'));
});

it('gives the platform super admin no grace period at all', function () {
    $admin = userWithRole(Role::SuperAdmin);

    $this->actingAs($admin)
        ->get(oversightUrl())
        ->assertRedirect(oversightUrl('/two-factor/setup'));
});

it('gives a state admin the standard grace period on the oversight surface', function () {
    $admin = userWithRole(Role::StateAdmin);

    $this->actingAs($admin)->get(oversightUrl())->assertOk();

    Carbon::setTestNow('2026-08-08 09:00:01');

    $this->actingAs($admin)
        ->get(oversightUrl())
        ->assertRedirect(oversightUrl('/two-factor/setup'));
});

it('never forces two-factor on a role that does not mandate it', function (Role $role) {
    actingAsMember($role, $this->works);

    Carbon::setTestNow('2027-08-01 09:00:00'); // a year later

    $this->get(tenantUrl($this->works))->assertOk();
})->with([Role::MeOfficer, Role::Consultant, Role::FieldMonitor]);

it('stops forcing enrolment once two-factor is confirmed', function () {
    $user = actingAsMember(Role::MdaAdmin, $this->works);

    Carbon::setTestNow('2026-08-20 09:00:00'); // well past grace
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->get(tenantUrl($this->works))->assertOk();
});

it('leaves the two-factor setup page reachable while enrolment is being forced', function () {
    actingAsMember(Role::MdaAdmin, $this->works);

    Carbon::setTestNow('2026-08-20 09:00:00');

    $this->get(tenantUrl($this->works, '/two-factor/setup'))
        ->assertOk()
        ->assertSee('Two-factor authentication required');
});

it('leaves sign-out reachable while enrolment is being forced', function () {
    actingAsMember(Role::MdaAdmin, $this->works);

    Carbon::setTestNow('2026-08-20 09:00:00');

    $this->post(tenantUrl($this->works, '/logout'))
        ->assertRedirect(tenantUrl($this->works, '/login'));

    $this->assertGuest();
});

it('does not force enrolment on a tenant host for an oversight role held elsewhere', function () {
    // The tenant permission team cannot see a global role, and membership is
    // the gate anyway: a state admin has no business on this host at all.
    $admin = userWithRole(Role::StateAdmin);

    Carbon::setTestNow('2026-08-20 09:00:00');

    $this->actingAs($admin)
        ->get(tenantUrl($this->works))
        ->assertForbidden();
});
