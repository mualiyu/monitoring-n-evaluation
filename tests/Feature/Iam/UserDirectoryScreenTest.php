<?php

/**
 * The oversight user directory (auth-surfaces.md §2–3).
 *
 * The screen where the state sees every account, its authority and its
 * workspaces — and works its two levers: provisioning per the invitation
 * chain (oversight roles state-wide, MdaAdmins into a chosen workspace) and
 * the platform-wide is_active switch. The denial cases matter as much as the
 * happy path: a tenant role must never reach this surface, a read-only
 * oversight role must never operate it, and nobody deactivates themselves.
 */

use App\Actions\Iam\InviteUser;
use App\Actions\Iam\SetUserActive;
use App\Enums\Role;
use App\Livewire\Oversight\Iam\UserDirectory;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Iam\UserInvited;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->stateAdmin->forceFill(['name' => 'Folake Adeola', 'two_factor_required_at' => now()])->save(); // inside the grace window

    $this->mdaAdmin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);

    // The oversight surface binds no tenant — every assertion runs in the
    // context the real requests run in.
    app(CurrentTenant::class)->forget();
});

/* -------------------------------------------------------------------------- */
/* Rendering & authorization */
/* -------------------------------------------------------------------------- */

it('renders the directory with every account, its roles and its workspaces', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/users'))
        ->assertOk()
        ->assertSee('Folake Adeola')
        ->assertSee(Role::StateAdmin->label())
        ->assertSee('Amina Bello')
        ->assertSee(Role::MdaAdmin->label())
        ->assertSee('Ministry of Works');
});

it('denies the directory to a tenant-role user, however senior in their own workspace', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/users'))
        ->assertForbidden();
});

it('denies the directory to a read-only oversight role', function () {
    $viewer = userWithRole(Role::ExecutiveViewer);

    Livewire::actingAs($viewer)->test(UserDirectory::class)->assertForbidden();
});

it('narrows the directory by name or email', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->set('search', 'Amina');

    expect($component->instance()->users->getCollection()->pluck('name')->all())
        ->toBe(['Amina Bello']);

    $component->set('search', $this->stateAdmin->email);

    expect($component->instance()->users->getCollection()->pluck('name')->all())
        ->toBe(['Folake Adeola']);
});

/* -------------------------------------------------------------------------- */
/* Activate / deactivate */
/* -------------------------------------------------------------------------- */

it('deactivates an account from the directory, and reactivates it', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->call('confirmSetActive', $this->mdaAdmin->id, false)
        ->assertSet('togglingUserId', $this->mdaAdmin->id)
        ->call('applySetActive')
        ->assertHasNoErrors();

    expect($this->mdaAdmin->fresh()->is_active)->toBeFalse();

    $entry = Activity::query()->where('description', 'user.deactivated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->stateAdmin->id)
        ->and($entry->subject_id)->toBe($this->mdaAdmin->id);

    Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->call('confirmSetActive', $this->mdaAdmin->id, true)
        ->call('applySetActive');

    expect($this->mdaAdmin->fresh()->is_active)->toBeTrue()
        ->and(Activity::query()->where('description', 'user.activated')->exists())->toBeTrue();
});

it('refuses to let an admin deactivate their own account', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->call('confirmSetActive', $this->stateAdmin->id, false)
        ->call('applySetActive');

    expect($component->get('failure'))->toContain('own account')
        ->and($this->stateAdmin->fresh()->is_active)->toBeTrue();
});

it('refuses the deactivation action itself without state-level users.manage', function () {
    $viewer = userWithRole(Role::ExecutiveViewer);

    expect(fn () => (new SetUserActive)($viewer, $this->mdaAdmin, false))
        ->toThrow(AuthorizationException::class);

    expect($this->mdaAdmin->fresh()->is_active)->toBeTrue();
});

/* -------------------------------------------------------------------------- */
/* Provisioning — the invitation chain from the oversight end */
/* -------------------------------------------------------------------------- */

it('lets a state admin invite an oversight role, scoped to no workspace', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->set('inviteEmail', 'reviewer@state.test')
        ->set('inviteRole', Role::DataQualityReviewer->value)
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = Invitation::query()->where('email', 'reviewer@state.test')->sole();

    expect($invitation->role)->toBe(Role::DataQualityReviewer)
        ->and($invitation->tenant_id)->toBeNull();

    Notification::assertSentOnDemand(UserInvited::class);
});

it('lets a state admin invite an MDA administrator into a chosen workspace', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->set('inviteEmail', 'perm.sec@health.test')
        ->set('inviteRole', Role::MdaAdmin->value)
        ->set('inviteTenantId', (string) $this->health->id)
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = Invitation::query()->where('email', 'perm.sec@health.test')->sole();

    expect($invitation->role)->toBe(Role::MdaAdmin)
        ->and($invitation->tenant_id)->toBe($this->health->id);
});

it('demands a workspace before a workspace-scoped role can be offered', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(UserDirectory::class)
        ->set('inviteEmail', 'perm.sec@health.test')
        ->set('inviteRole', Role::MdaAdmin->value)
        ->set('inviteTenantId', '')
        ->call('invite')
        ->assertHasErrors(['inviteTenantId']);

    expect(Invitation::query()->where('email', 'perm.sec@health.test')->exists())->toBeFalse();
});

it('offers the state admin exactly the chain’s roles, and the Action refuses the rest anyway', function () {
    $component = Livewire::actingAs($this->stateAdmin)->test(UserDirectory::class);

    // Oversight provisions oversight roles and MdaAdmins — never workspace staff.
    expect(array_keys($component->instance()->invitableRoles()))->toBe([
        Role::StateAdmin->value,
        Role::ExecutiveViewer->value,
        Role::DataQualityReviewer->value,
        Role::MdaAdmin->value,
    ]);

    $component->set('inviteEmail', 'officer@works.test')
        ->set('inviteRole', Role::MeOfficer->value)
        ->call('invite')
        ->assertHasErrors(['inviteRole']);

    expect(fn () => (new InviteUser)($this->stateAdmin, 'officer@works.test', Role::MeOfficer, $this->works))
        ->toThrow(InvalidArgumentException::class);

    expect(Invitation::query()->where('email', 'officer@works.test')->exists())->toBeFalse();
});

it('shows workspace-issued invitations alongside oversight ones, named for their workspace', function () {
    app(CurrentTenant::class)->runAs($this->works, function (): void {
        (new InviteUser)($this->mdaAdmin, 'engineer@works.test', Role::FieldMonitor, $this->works);
    });
    app(CurrentTenant::class)->forget();

    (new InviteUser)($this->stateAdmin, 'viewer@state.test', Role::ExecutiveViewer);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/users'))
        ->assertOk()
        ->assertSee('engineer@works.test')
        ->assertSee('viewer@state.test')
        ->assertSee(__('State-level'));
});
