<?php

/**
 * ExemptFromTwoFactor + RequireTwoFactor (docs/design/auth-surfaces.md §4):
 * an explicit, audited exemption releases an account from the role-mandated
 * enrolment without touching any second factor it has actually enrolled.
 * Time is frozen throughout, never slept through.
 */

use App\Actions\Iam\ExemptFromTwoFactor;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Carbon::setTestNow('2026-08-01 09:00:00');

    // Not `works`: the demo seeder below creates that slug itself.
    $this->lands = Tenant::factory()->create(['name' => 'Ministry of Lands', 'slug' => 'lands']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Middleware behaviour
|--------------------------------------------------------------------------
*/

it('lets an exempted super admin onto the oversight surface without enrolling', function () {
    $admin = userWithRole(Role::SuperAdmin);
    (new ExemptFromTwoFactor)(null, $admin);

    $this->actingAs($admin)
        ->get(oversightUrl())
        ->assertOk()
        ->assertSessionMissing('two_factor_deadline');
});

it('lets an exempted MDA admin keep working past the grace period', function () {
    $user = actingAsMember(Role::MdaAdmin, $this->lands);
    (new ExemptFromTwoFactor)(null, $user);

    Carbon::setTestNow('2026-08-20 09:00:00'); // well past the 7-day grace

    $this->get(tenantUrl($this->lands))
        ->assertOk()
        ->assertSessionMissing('two_factor_deadline');
});

it('shows an exempted account the setup page as a voluntary visit, not a mandate', function () {
    $user = actingAsMember(Role::MdaAdmin, $this->lands);
    (new ExemptFromTwoFactor)(null, $user);

    Carbon::setTestNow('2026-08-20 09:00:00');

    $this->get(tenantUrl($this->lands, '/two-factor/setup'))
        ->assertOk()
        ->assertDontSee('Two-factor authentication required');
});

it('restores the mandate the moment the exemption is lifted', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $action = new ExemptFromTwoFactor;

    $action(null, $admin);
    $action(null, $admin, false);

    expect($admin->fresh()->two_factor_exempted_at)->toBeNull();

    $this->actingAs($admin)
        ->get(oversightUrl())
        ->assertRedirect(oversightUrl('/two-factor/setup'));
});

/*
|--------------------------------------------------------------------------
| The action: authority, self-protection, audit
|--------------------------------------------------------------------------
*/

it('lets a state admin with global users.manage exempt another account, and logs it', function () {
    seedPermissions();

    $actor = userWithRole(Role::StateAdmin);
    $subject = userWithRole(Role::StateAdmin);

    (new ExemptFromTwoFactor)($actor, $subject);

    expect($subject->fresh()->two_factor_exempted_at->toDateTimeString())->toBe('2026-08-01 09:00:00');

    $entry = Activity::query()->where('description', 'user.two_factor_exempted')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($actor->id)
        ->and($entry->subject_id)->toBe($subject->id);
});

it('logs the lift as its own audit event', function () {
    seedPermissions();

    $actor = userWithRole(Role::StateAdmin);
    $subject = userWithRole(Role::StateAdmin);
    $action = new ExemptFromTwoFactor;

    $action($actor, $subject);
    $action($actor, $subject, false);

    expect(Activity::query()->where('description', 'user.two_factor_exemption_lifted')->exists())->toBeTrue()
        ->and($subject->fresh()->two_factor_exempted_at)->toBeNull();
});

it('refuses an actor whose users.manage is only tenant-scoped', function () {
    seedPermissions();

    // MdaAdmin holds users.manage in the MDA's team, never in the global one.
    $actor = memberOf(User::factory()->create(), $this->lands, Role::MdaAdmin);
    $subject = userWithRole(Role::StateAdmin);

    expect(fn () => (new ExemptFromTwoFactor)($actor, $subject))
        ->toThrow(AuthorizationException::class)
        ->and($subject->fresh()->two_factor_exempted_at)->toBeNull();
});

it('refuses self-exemption even for the platform operator', function () {
    seedPermissions();

    $admin = userWithRole(Role::SuperAdmin);

    expect(fn () => (new ExemptFromTwoFactor)($admin, $admin))
        ->toThrow(InvalidArgumentException::class)
        ->and($admin->fresh()->two_factor_exempted_at)->toBeNull();
});

it('refuses an actor-less exemption outside the console', function () {
    $subject = userWithRole(Role::SuperAdmin);

    // Flip the cached flag the way a web SAPI would have set it; the test
    // application is discarded after this case, so nothing leaks.
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);

    expect(fn () => (new ExemptFromTwoFactor)(null, $subject))
        ->toThrow(RuntimeException::class)
        ->and($subject->fresh()->two_factor_exempted_at)->toBeNull();
});

it('is idempotent and writes no phantom audit rows', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $action = new ExemptFromTwoFactor;

    $action(null, $admin);
    $action(null, $admin);

    expect(Activity::query()->where('description', 'user.two_factor_exempted')->count())->toBe(1);
});

it('leaves an enrolled second factor untouched', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $admin->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    (new ExemptFromTwoFactor)(null, $admin);

    $fresh = $admin->fresh();

    expect($fresh->two_factor_confirmed_at->toDateTimeString())->toBe('2026-08-01 09:00:00')
        ->and(decrypt($fresh->two_factor_secret))->toBe('secret');
});

/*
|--------------------------------------------------------------------------
| Demo seeder
|--------------------------------------------------------------------------
*/

it('seeds the demo platform admin exempt and leaves the graced admins under the rule', function () {
    seedPermissions();
    (new DemoTenantSeeder)->run();

    $platformAdmin = User::query()->where('email', 'admin@mne.test')->firstOrFail();
    $stateAdmin = User::query()->where('email', 'state@mne.test')->firstOrFail();
    $mdaAdmin = User::query()->where('email', 'mda-admin@works.mne.test')->firstOrFail();

    expect($platformAdmin->two_factor_exempted_at)->not->toBeNull()
        ->and($stateAdmin->two_factor_exempted_at)->toBeNull()
        ->and($mdaAdmin->two_factor_exempted_at)->toBeNull();

    $this->actingAs($platformAdmin)->get(oversightUrl())->assertOk();
});
