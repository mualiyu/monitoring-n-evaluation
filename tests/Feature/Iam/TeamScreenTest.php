<?php

/**
 * The MDA team screen (auth-surfaces.md §2–3).
 *
 * The Iam action tests already prove the invitation chain, the membership
 * gate and the token lifecycle. What is unproven until here is the SCREEN:
 * that the roster shows the right people to the right role, that the invite
 * form offers only the roles the chain allows (and that the Action refuses
 * the rest even if the form is bypassed), that revocation demands a reason
 * and leaves an audit line — and that one workspace's team page never shows
 * another workspace's people or invitations.
 */

use App\Actions\Iam\InviteUser;
use App\Actions\Iam\RevokeTenantAccess;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Iam\TeamIndex;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\Iam\UserInvited;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(['name' => 'Bola Adeyemi']), $this->works, Role::Consultant);
});

/* -------------------------------------------------------------------------- */
/* Rendering & authorization */
/* -------------------------------------------------------------------------- */

it('renders the roster with members, their roles and their sign-in record', function () {
    $this->officer->forceFill(['last_login_at' => now()->subDay()])->save();

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/team'))
        ->assertOk()
        ->assertSee('Amina Bello')
        ->assertSee('Chidi Okafor')
        ->assertSee('Bola Adeyemi')
        ->assertSee(Role::MeOfficer->label())
        ->assertSee(Role::Consultant->label())
        ->assertSee('1 day ago');
});

it('lets an M&E officer view the roster but offers no invite or revoke controls', function () {
    Livewire::actingAs($this->officer)
        ->test(TeamIndex::class)
        ->assertOk()
        ->assertSee('Amina Bello')
        ->assertDontSeeHtml('wire:click="confirmRevoke(')
        ->assertDontSeeHtml('wire:submit="invite"');
});

it('denies the team screen to a workspace role without users.view', function () {
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/team'))
        ->assertForbidden();

    Livewire::actingAs($this->consultant)->test(TeamIndex::class)->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Inviting */
/* -------------------------------------------------------------------------- */

it('lets the MDA admin invite an officer into this workspace', function () {
    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->set('inviteEmail', 'new.officer@works.test')
        ->set('inviteRole', Role::MeOfficer->value)
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = Invitation::query()->where('email', 'new.officer@works.test')->sole();

    expect($invitation->role)->toBe(Role::MeOfficer)
        ->and($invitation->tenant_id)->toBe($this->works->id)
        ->and($invitation->invited_by_id)->toBe($this->admin->id);

    Notification::assertSentOnDemand(UserInvited::class);
});

it('offers the admin only the roles beneath them, and the Action refuses the rest anyway', function () {
    $component = Livewire::actingAs($this->admin)->test(TeamIndex::class);

    // The option list is the chain, verbatim: no MdaAdmin, no oversight roles.
    expect(array_keys($component->instance()->invitableRoles()))
        ->toBe([Role::MeOfficer->value, Role::Consultant->value, Role::FieldMonitor->value]);

    // The form refuses an out-of-chain role…
    $component->set('inviteEmail', 'rival@works.test')
        ->set('inviteRole', Role::MdaAdmin->value)
        ->call('invite')
        ->assertHasErrors(['inviteRole']);

    $component->set('inviteRole', Role::StateAdmin->value)
        ->call('invite')
        ->assertHasErrors(['inviteRole']);

    // …and the Action refuses it even when the form is bypassed entirely.
    expect(fn () => (new InviteUser)($this->admin, 'rival@works.test', Role::MdaAdmin, $this->works))
        ->toThrow(InvalidArgumentException::class);

    expect(Invitation::query()->where('email', 'rival@works.test')->exists())->toBeFalse();
});

it('refuses the invite endpoint to a role without users.invite', function () {
    Livewire::actingAs($this->officer)
        ->test(TeamIndex::class)
        ->set('inviteEmail', 'anyone@works.test')
        ->set('inviteRole', Role::Consultant->value)
        ->call('invite')
        ->assertForbidden();

    expect(Invitation::query()->where('email', 'anyone@works.test')->exists())->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* Pending invitations panel */
/* -------------------------------------------------------------------------- */

it('lists a pending invitation and revokes it from the panel', function () {
    (new InviteUser)($this->admin, 'pending@works.test', Role::Consultant, $this->works);
    $invitation = Invitation::query()->where('email', 'pending@works.test')->sole();

    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->assertSee('pending@works.test')
        ->call('revokeInvitation', $invitation->id);

    expect($invitation->fresh()->revoked_at)->not->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->assertDontSee('pending@works.test');
});

it('re-sends a pending invitation with a rotated link, and surfaces the throttle as a message', function () {
    (new InviteUser)($this->admin, 'slow@works.test', Role::Consultant, $this->works);
    $invitation = Invitation::query()->where('email', 'slow@works.test')->sole();
    $originalHash = $invitation->token_hash;

    $component = Livewire::actingAs($this->admin)->test(TeamIndex::class);

    $component->call('resendInvitation', $invitation->id)->assertHasNoErrors();

    expect($invitation->fresh()->token_hash)->not->toBe($originalHash);

    // The Action allows three re-sends per invitation per hour; the fourth
    // becomes a message on the screen, not a mail out the door.
    $component->call('resendInvitation', $invitation->id);
    $component->call('resendInvitation', $invitation->id);
    $component->call('resendInvitation', $invitation->id);

    expect($component->get('failure'))->toContain('too many times');
});

it('404s an attempt to revoke an invitation that is not on this workspace’s list', function () {
    $healthAdmin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);

    $foreign = app(CurrentTenant::class)->runAs(
        $this->health,
        fn (): Invitation => (new InviteUser)($healthAdmin, 'nurse@health.test', Role::FieldMonitor, $this->health),
    );

    actingOnTenant($this->works);

    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('revokeInvitation', $foreign->id)
        ->assertNotFound();

    expect($foreign->fresh()->revoked_at)->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* Revoking workspace access */
/* -------------------------------------------------------------------------- */

it('revokes a member’s workspace access with a recorded reason', function () {
    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('confirmRevoke', $this->consultant->id)
        ->assertSet('revokingUserId', $this->consultant->id)
        ->set('revokeReason', 'Engagement ended 30 June; final report approved and contract closed out.')
        ->call('revokeAccess')
        ->assertHasNoErrors();

    $membership = TenantMembership::query()
        ->where('tenant_id', $this->works->id)
        ->where('user_id', $this->consultant->id)
        ->sole();

    expect($membership->status)->toBe(MembershipStatus::Suspended);

    actingOnTenant($this->works);
    expect($this->consultant->fresh()->hasRole(Role::Consultant->value))->toBeFalse();

    $entry = Activity::query()->where('description', 'tenant_access.revoked')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->subject_id)->toBe($this->consultant->id)
        ->and($entry->properties->get('reason'))->toContain('Engagement ended');
});

it('refuses to revoke access without a reason worth auditing', function () {
    Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('confirmRevoke', $this->consultant->id)
        ->set('revokeReason', '')
        ->call('revokeAccess')
        ->assertHasErrors('revokeReason');

    $membership = TenantMembership::query()
        ->where('tenant_id', $this->works->id)
        ->where('user_id', $this->consultant->id)
        ->sole();

    expect($membership->status)->toBe(MembershipStatus::Active);
});

it('refuses to let an admin revoke their own workspace access', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->call('confirmRevoke', $this->admin->id)
        ->set('revokeReason', 'Attempting to lock myself out of my own workspace.')
        ->call('revokeAccess');

    expect($component->get('failure'))->toContain('own workspace access');

    $membership = TenantMembership::query()
        ->where('tenant_id', $this->works->id)
        ->where('user_id', $this->admin->id)
        ->sole();

    expect($membership->status)->toBe(MembershipStatus::Active);
});

it('refuses the revoke endpoint to a role without users.manage', function () {
    Livewire::actingAs($this->officer)
        ->test(TeamIndex::class)
        ->call('confirmRevoke', $this->consultant->id)
        ->assertForbidden();
});

it('moves a revoked member into the suspended section, not off the page', function () {
    (new RevokeTenantAccess)($this->consultant, $this->works);
    actingOnTenant($this->works);

    $component = Livewire::actingAs($this->admin)
        ->test(TeamIndex::class)
        ->assertOk()
        ->assertSee('Bola Adeyemi')
        ->assertSee(__('Suspended'));

    expect($component->instance()->suspendedMembers()->pluck('user.name')->all())
        ->toBe(['Bola Adeyemi'])
        ->and($component->instance()->activeMembers()->pluck('user.name')->all())
        ->not->toContain('Bola Adeyemi');
});

/* -------------------------------------------------------------------------- */
/* Tenant isolation */
/* -------------------------------------------------------------------------- */

it('shows one workspace nothing of another workspace’s people or invitations', function () {
    memberOf(User::factory()->create(['name' => 'Ngozi Eze']), $this->health, Role::MeOfficer);
    $healthAdmin = memberOf(User::factory()->create(['name' => 'Musa Ibrahim']), $this->health, Role::MdaAdmin);

    app(CurrentTenant::class)->runAs($this->health, function () use ($healthAdmin): void {
        (new InviteUser)($healthAdmin, 'nurse@health.test', Role::FieldMonitor, $this->health);
    });

    actingOnTenant($this->works);
    (new InviteUser)($this->admin, 'engineer@works.test', Role::FieldMonitor, $this->works);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/team'))
        ->assertOk()
        ->assertSee('engineer@works.test')
        ->assertDontSee('Ngozi Eze')
        ->assertDontSee('Musa Ibrahim')
        ->assertDontSee('nurse@health.test');
});
