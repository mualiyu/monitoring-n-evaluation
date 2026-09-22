<?php

/**
 * Notification preferences on the oversight surface, and the email
 * verification notice.
 *
 * Oversight-only accounts (a state M&E director, the governor's office) hold no
 * workspace membership, so the workspace copy of the preferences screen was
 * unreachable for them — and they receive more mail than anyone. The screen is
 * now served on both app surfaces at the same path, from one shared concern.
 */

use App\Enums\Role;
use App\Livewire\Oversight\Settings\NotificationPreferences as OversightPreferences;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationCategories;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->director = userWithRole(Role::StateAdmin);
});

it('serves the preferences screen to an oversight-only account on the oversight host', function () {
    $this->actingAs($this->director);

    $this->get(oversightUrl('/settings/notifications'))
        ->assertOk()
        ->assertSee(__('Notification preferences'))
        ->assertSee(__('Back to notifications'));
});

it('saves an oversight user’s mute, which the notification filter then honours', function () {
    $this->actingAs($this->director);

    Livewire::test(OversightPreferences::class)
        ->set('preferences.'.NotificationCategories::PROJECTS.'.mail', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->director->refresh()->hasMutedNotifications(NotificationCategories::PROJECTS, 'mail'))->toBeTrue()
        ->and($this->director->hasMutedNotifications(NotificationCategories::PROJECTS, 'database'))->toBeFalse();
});

it('keeps tenant roles off the oversight copy', function () {
    $this->actingAs(memberOf(User::factory()->create(), $this->works, Role::MdaAdmin));

    $this->get(oversightUrl('/settings/notifications'))->assertForbidden();
});

it('still serves the workspace copy to workspace staff', function () {
    $this->actingAs(memberOf(User::factory()->create(), $this->works, Role::MeOfficer));

    $this->get(tenantUrl($this->works, '/settings/notifications'))
        ->assertOk()
        ->assertSee(__('Back to workspace settings'));
});

it('links to the preferences from the account menu and the notification centre, on both surfaces', function () {
    $this->actingAs($this->director);
    $this->get(oversightUrl('/notifications'))->assertOk()->assertSee('/settings/notifications', false);

    $this->actingAs(memberOf(User::factory()->create(), $this->works, Role::MeOfficer));
    $this->get(tenantUrl($this->works, '/notifications'))->assertOk()->assertSee('/settings/notifications', false);
});

describe('email verification notice', function () {
    it('renders for an unverified account instead of a 500', function () {
        $this->actingAs(memberOf(User::factory()->unverified()->create(), $this->works, Role::MeOfficer));

        $this->get(tenantUrl($this->works, '/email/verify'))
            ->assertOk()
            ->assertSee(__('Verify your email address'))
            ->assertSee(__('Send the link again'));
    });

    it('confirms a resend in words, not Fortify’s status code', function () {
        $user = memberOf(User::factory()->unverified()->create(), $this->works, Role::MeOfficer);
        $this->actingAs($user);

        $this->from(tenantUrl($this->works, '/email/verify'))
            ->post(tenantUrl($this->works, '/email/verification-notification'))
            ->assertRedirect(tenantUrl($this->works, '/email/verify'))
            ->assertSessionHas('status', __('A new verification link is on its way to your email address.'));
    });
});
