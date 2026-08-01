<?php

/**
 * Login hardening (docs/design/auth-surfaces.md §5): throttling instead of
 * permanent account locks (an attacker must not be able to lock a Permanent
 * Secretary out before a budget deadline), a throttle key that is per host so
 * one workspace cannot lock a user out of every other one, an is_active gate
 * that also catches deactivation mid-session, and an audit trail for both the
 * successful and the suspicious cases.
 */

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

const HARDENING_PASSWORD = 'Str0ng!Passw0rd#2026';

beforeEach(function () {
    Carbon::setTestNow('2026-08-01 09:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->officer = memberOf(
        User::factory()->create(['password' => HARDENING_PASSWORD]),
        $this->works,
        Role::MeOfficer,
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

it('throttles a sixth sign-in attempt within a minute on a workspace host', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(tenantUrl($this->works, '/login'), [
            'email' => $this->officer->email,
            'password' => 'wrong-password',
        ])->assertStatus(302);
    }

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

it('keeps a lockout on one workspace host from locking the same user out of another', function () {
    memberOf($this->officer, $this->health, Role::Consultant);

    foreach (range(1, 5) as $attempt) {
        $this->post(tenantUrl($this->works, '/login'), [
            'email' => $this->officer->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);

    $this->post(tenantUrl($this->health, '/login'), [
        'email' => $this->officer->email,
        'password' => 'wrong-password',
    ])->assertStatus(302);
});

it('never permanently locks an account, so the throttle expires on its own', function () {
    foreach (range(1, 6) as $attempt) {
        $this->post(tenantUrl($this->works, '/login'), [
            'email' => $this->officer->email,
            'password' => 'wrong-password',
        ]);
    }

    Carbon::setTestNow('2026-08-01 09:02:00');

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => 'wrong-password',
    ])->assertStatus(302);
});

it('ejects an account that is deactivated mid-session, with a neutral message', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works))
        ->assertOk();

    $this->officer->forceFill(['is_active' => false])->save();

    $this->get(tenantUrl($this->works))
        ->assertRedirect(tenantUrl($this->works, '/login'))
        ->assertSessionHas('status', 'This account is not active. Contact your administrator.');

    $this->assertGuest();
});

it('ejects a deactivated account on the oversight surface too', function () {
    $admin = userWithRole(Role::StateAdmin);
    $admin->forceFill(['is_active' => false])->save();

    $this->actingAs($admin)
        ->get(oversightUrl())
        ->assertRedirect(oversightUrl('/login'));

    $this->assertGuest();
});

it('refuses to authenticate a soft-deleted user', function () {
    $this->officer->delete();

    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => HARDENING_PASSWORD,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('records the time and address of a successful sign-in', function () {
    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => HARDENING_PASSWORD,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($this->officer);

    $officer = $this->officer->fresh();

    expect($officer->last_login_at?->toDateTimeString())->toBe('2026-08-01 09:00:00')
        ->and($officer->last_login_ip)->toBe('127.0.0.1');
});

it('writes an audit entry for a successful sign-in, with the host it happened on', function () {
    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => HARDENING_PASSWORD,
    ])->assertRedirect();

    $entry = Activity::query()->where('description', 'auth.login')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->officer->id)
        ->and($entry->properties['host'])->toBe('works.'.config('platform.domain'))
        ->and($entry->properties['ip'])->toBe('127.0.0.1');
});

it('keeps the login timestamp out of the model audit trail', function () {
    $this->post(tenantUrl($this->works, '/login'), [
        'email' => $this->officer->email,
        'password' => HARDENING_PASSWORD,
    ]);

    expect(Activity::query()->where('description', 'updated')->exists())->toBeFalse();
});

it('writes an audit entry when a lockout happens', function () {
    // NB: the Lockout event is the audit hook; see the report on which code
    // path is expected to dispatch it under the route-level login limiter.
    event(new Lockout(Request::create(
        tenantUrl($this->works, '/login'),
        'POST',
        ['email' => $this->officer->email],
        server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Test/1.0'],
    )));

    $entry = Activity::query()->where('description', 'auth.lockout')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['email'])->toBe($this->officer->email)
        ->and($entry->properties['ip'])->toBe('198.51.100.7')
        ->and($entry->properties['host'])->toBe('works.'.config('platform.domain'))
        ->and($entry->properties['user_agent'])->toBe('Test/1.0');
});
