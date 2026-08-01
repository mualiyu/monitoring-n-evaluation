<?php

/**
 * Invitations (docs/design/auth-surfaces.md §3): the platform has no public
 * registration, so an invitation token is the only way an account is ever
 * created. The token is the credential — stored hashed, single use, expiring,
 * and delivered to a link on the host of the workspace it grants.
 */

use App\Actions\Iam\InviteUser;
use App\Actions\Iam\RevokeInvitation;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Iam\UserInvited;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Every invitation link mailed to $email, oldest first — read back the way an
 * invitee reads it: out of the message, never out of the database (the
 * plaintext token is never stored).
 *
 * @return list<string>
 */
function invitationLinksSentTo(string $email): array
{
    return Notification::sent(
        new AnonymousNotifiable,
        UserInvited::class,
        fn (UserInvited $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routeNotificationFor('mail') === $email,
    )
        ->map(fn (UserInvited $notification) => (string) $notification->toMail(new AnonymousNotifiable)->actionUrl)
        ->values()
        ->all();
}

function invitationTokenIn(string $link): string
{
    return basename((string) parse_url($link, PHP_URL_PATH));
}

/** The single live invitation link for $email (fails loudly if there is not exactly one). */
function onlyInvitationLinkSentTo(string $email): string
{
    $links = invitationLinksSentTo($email);

    expect($links)->toHaveCount(1);

    return $links[0];
}

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow('2026-08-01 09:00:00');

    // Acceptance assigns whichever role the invitation carries, so every role
    // definition must exist here exactly as RoleSeeder creates them on deploy.
    foreach (Role::cases() as $role) {
        ensureRoleDefined($role);
    }

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('lets a state admin invite an MDA administrator into a workspace', function () {
    $inviter = userWithRole(Role::StateAdmin);

    $invitation = (new InviteUser)($inviter, '  Perm.Sec@Works.test ', Role::MdaAdmin, $this->works);

    expect($invitation->email)->toBe('perm.sec@works.test')
        ->and($invitation->role)->toBe(Role::MdaAdmin)
        ->and($invitation->tenant_id)->toBe($this->works->id)
        ->and($invitation->invited_by_id)->toBe($inviter->id)
        ->and($invitation->expires_at->toDateTimeString())->toBe('2026-08-08 09:00:00')
        ->and($invitation->isPending())->toBeTrue();

    Notification::assertSentOnDemandTimes(UserInvited::class, 1);
});

it('lets an MDA administrator invite a consultant into their own workspace', function () {
    actingOnTenant($this->works);
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $invitation = (new InviteUser)($admin, 'consultant@firm.test', Role::Consultant, $this->works);

    expect($invitation->role)->toBe(Role::Consultant)
        ->and($invitation->tenant_id)->toBe($this->works->id);
});

it('stores the invitation token only as a hash, never in plaintext', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    $token = invitationTokenIn(onlyInvitationLinkSentTo('perm.sec@works.test'));

    expect($token)->toHaveLength(64);

    $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
    $this->assertDatabaseHas('invitations', ['token_hash' => hash('sha256', $token)]);
});

it('queues the invitation mail instead of sending it inside the request', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    Notification::assertSentOnDemand(
        UserInvited::class,
        fn (UserInvited $notification) => $notification instanceof ShouldQueue,
    );
});

it('sends a workspace invitation to a link on that workspace host', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    expect(onlyInvitationLinkSentTo('perm.sec@works.test'))
        ->toStartWith(tenantUrl($this->works, '/invitations/'));
});

it('sends an oversight invitation to a link on the oversight host', function () {
    (new InviteUser)(userWithRole(Role::SuperAdmin), 'reviewer@bureau.test', Role::DataQualityReviewer);

    expect(onlyInvitationLinkSentTo('reviewer@bureau.test'))
        ->toStartWith(oversightUrl('/invitations/'));
});

it('creates a verified account with workspace access when an invitation is accepted', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);
    $link = onlyInvitationLinkSentTo('perm.sec@works.test');

    $this->get($link)->assertOk()->assertSee('Ministry of Works');

    $this->post($link, [
        'name' => 'Amina Bello',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertRedirect();

    $user = User::query()->where('email', 'perm.sec@works.test')->sole();

    expect($user->name)->toBe('Amina Bello')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_active)->toBeTrue();

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $this->works->id,
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    actingOnTenant($this->works);
    expect($user->fresh()->hasRole(Role::MdaAdmin->value))->toBeTrue();

    $this->assertDatabaseHas('invitations', [
        'email' => 'perm.sec@works.test',
        'accepted_user_id' => $user->id,
    ]);

    $this->assertAuthenticatedAs(User::query()->where('email', 'perm.sec@works.test')->sole());
});

it('lands a new member on the workspace they just joined', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    $this->post(onlyInvitationLinkSentTo('perm.sec@works.test'), [
        'name' => 'Amina Bello',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertRedirect(rtrim(tenantUrl($this->works), '/'));
});

it('adds a second workspace to an existing account without asking for a password', function () {
    $officer = memberOf(User::factory()->create(['email' => 'officer@state.test']), $this->works, Role::MeOfficer);

    actingOnTenant($this->health);
    $admin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);
    (new InviteUser)($admin, 'officer@state.test', Role::Consultant, $this->health);
    actingWithoutTenant();

    $this->post(onlyInvitationLinkSentTo('officer@state.test'))->assertRedirect();

    expect(User::query()->where('email', 'officer@state.test')->count())->toBe(1);

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $this->health->id,
        'user_id' => $officer->id,
        'status' => 'active',
    ]);
});

it('refuses to create an account with a password below the platform policy', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    $this->post(onlyInvitationLinkSentTo('perm.sec@works.test'), [
        'name' => 'Amina Bello',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'perm.sec@works.test')->exists())->toBeFalse();
    $this->assertGuest();
});

it('refuses an invitation whose deadline has passed', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);
    $link = onlyInvitationLinkSentTo('perm.sec@works.test');

    Carbon::setTestNow('2026-08-08 09:00:01'); // one second past the 7-day TTL

    $this->get($link)->assertStatus(410);
    $this->post($link, [
        'name' => 'Amina Bello',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertStatus(410);

    expect(User::query()->where('email', 'perm.sec@works.test')->exists())->toBeFalse();
});

it('refuses an invitation that has been revoked', function () {
    $inviter = userWithRole(Role::StateAdmin);
    $invitation = (new InviteUser)($inviter, 'perm.sec@works.test', Role::MdaAdmin, $this->works);
    $link = onlyInvitationLinkSentTo('perm.sec@works.test');

    (new RevokeInvitation)($inviter, $invitation);

    $this->get($link)->assertStatus(410);
    $this->post($link, [
        'name' => 'Amina Bello',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertStatus(410);

    expect(User::query()->where('email', 'perm.sec@works.test')->exists())->toBeFalse();
});

it('refuses a token that has already been used and grants no second membership', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);
    $link = onlyInvitationLinkSentTo('perm.sec@works.test');

    $profile = [
        'name' => 'Amina Bello',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ];

    $this->post($link, $profile)->assertRedirect();

    Auth::logout();
    $this->flushSession();

    $this->post($link, $profile)->assertStatus(410);

    expect(User::query()->where('email', 'perm.sec@works.test')->count())->toBe(1)
        ->and(DB::table('tenant_user')->count())->toBe(1);
});

it('refuses an unknown token with a 404 that confirms nothing', function () {
    $this->get(tenantUrl($this->works, '/invitations/'.str_repeat('a', 64)))->assertNotFound();
    $this->post(tenantUrl($this->works, '/invitations/'.str_repeat('a', 64)))->assertNotFound();
});

it('grants access only to the workspace named in the token, whichever host it is opened on', function () {
    (new InviteUser)(userWithRole(Role::StateAdmin), 'perm.sec@works.test', Role::MdaAdmin, $this->works);
    $token = invitationTokenIn(onlyInvitationLinkSentTo('perm.sec@works.test'));

    $this->post(tenantUrl($this->health, '/invitations/'.$token), [
        'name' => 'Amina Bello',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ]);

    $user = User::query()->where('email', 'perm.sec@works.test')->sole();

    $this->assertDatabaseMissing('tenant_user', [
        'tenant_id' => $this->health->id,
        'user_id' => $user->id,
    ]);

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $this->works->id,
        'user_id' => $user->id,
        'status' => 'active',
    ]);
});

it('stops an MDA administrator from minting another MDA administrator', function () {
    actingOnTenant($this->works);
    $admin = userWithRole(Role::MdaAdmin, $this->works);

    (new InviteUser)($admin, 'rival@works.test', Role::MdaAdmin, $this->works);
})->throws(InvalidArgumentException::class, 'may invite [mda-admin]');

it('stops an MDA administrator from inviting oversight roles', function (Role $role) {
    actingOnTenant($this->works);
    $admin = userWithRole(Role::MdaAdmin, $this->works);

    expect(fn () => (new InviteUser)($admin, 'outsider@state.test', $role))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('invitations', 0);
})->with(Role::oversightRoles());

it('stops a consultant from inviting anyone at all', function () {
    actingOnTenant($this->works);
    $consultant = userWithRole(Role::Consultant, $this->works);

    expect(fn () => (new InviteUser)($consultant, 'friend@firm.test', Role::FieldMonitor, $this->works))
        ->toThrow(InvalidArgumentException::class);
});

it('stops an MDA administrator from inviting into a workspace they do not belong to', function () {
    actingOnTenant($this->works);
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    expect(fn () => (new InviteUser)($admin, 'stranger@health.test', Role::Consultant, $this->health))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('invitations', 0);
});

it('revokes the previous pending invitation when the same person is invited again', function () {
    $inviter = userWithRole(Role::StateAdmin);

    $first = (new InviteUser)($inviter, 'perm.sec@works.test', Role::MdaAdmin, $this->works);
    $second = (new InviteUser)($inviter, 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    expect($first->fresh()->isPending())->toBeFalse()
        ->and($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->fresh()->isPending())->toBeTrue();

    [$firstLink, $secondLink] = invitationLinksSentTo('perm.sec@works.test');

    $this->get($firstLink)->assertStatus(410);
    $this->get($secondLink)->assertOk();
});

it('keeps a re-invitation to another workspace alive when one workspace re-invites', function () {
    $inviter = userWithRole(Role::StateAdmin);

    $forWorks = (new InviteUser)($inviter, 'perm.sec@state.test', Role::MdaAdmin, $this->works);
    (new InviteUser)($inviter, 'perm.sec@state.test', Role::MdaAdmin, $this->health);

    expect($forWorks->fresh()->isPending())->toBeTrue();
});

it('keeps every invitation as a permanent audit row', function () {
    $inviter = userWithRole(Role::StateAdmin);
    $invitation = (new InviteUser)($inviter, 'perm.sec@works.test', Role::MdaAdmin, $this->works);

    (new RevokeInvitation)($inviter, $invitation);

    $this->assertDatabaseCount('invitations', 1);
    expect(Invitation::query()->find($invitation->id))->not->toBeNull();
});
