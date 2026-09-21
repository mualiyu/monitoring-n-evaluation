<?php

/**
 * Notification preferences: "stop emailing me about every project status
 * change".
 *
 * The property that matters is PER CHANNEL, not per notification: muting the
 * email must not also silence the in-app item, because the in-app item is the
 * record that the person was told. Everything here is asserted against a real
 * notification going through a real via(), rather than against the preference
 * column — a preference nobody consults is a checkbox, not a preference.
 *
 * YOUR OWN ACCOUNT ONLY. There is no user parameter and no administrative
 * path: an admin quietly muting a director's overdue-return mail is exactly
 * the silence an audit trail is supposed to make impossible.
 */

use App\Actions\Iam\InviteUser;
use App\Actions\Settings\SaveNotificationPreferences;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Settings\NotificationPreferences;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Iam\UserInvited;
use App\Notifications\Projects\ProjectStatusUpdated;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);
    $this->admin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);
});

/** The notification under test, addressed to whoever is asked. */
function statusNotification(Project $project): ProjectStatusUpdated
{
    return new ProjectStatusUpdated($project, ProjectStatus::InProgress, ProjectStatus::Suspended, 'Contractor withdrew.');
}

/* -------------------------------------------------------------------------- */
/* One channel off, the others on */
/* -------------------------------------------------------------------------- */

it('delivers a category on every channel when nothing is muted', function () {
    expect(statusNotification($this->project)->via($this->officer))
        ->toBe(['database', 'mail']);
});

it('drops only the muted channel and keeps delivering on the rest', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => true],
    ]);

    // The email stops; the in-app item — the record that they were told —
    // does not.
    expect(statusNotification($this->project)->via($this->officer))->toBe(['database'])
        ->and($this->officer->hasMutedNotifications(NotificationCategories::PROJECTS, 'mail'))->toBeTrue()
        ->and($this->officer->hasMutedNotifications(NotificationCategories::PROJECTS, 'database'))->toBeFalse();
});

it('mutes a category without touching any other category', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => false],
    ]);

    expect(statusNotification($this->project)->via($this->officer))->toBe([]);

    // Reporting is a different switch and is untouched.
    expect($this->officer->hasMutedNotifications(NotificationCategories::REPORTING, 'mail'))->toBeFalse();
});

it('really sends nothing on a fully muted category, end to end', function () {
    Notification::fake();

    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => false],
    ]);

    Notification::send([$this->officer, $this->admin], statusNotification($this->project));

    // Laravel handles an empty via() by delivering nothing at all.
    Notification::assertNotSentTo($this->officer, ProjectStatusUpdated::class);
    Notification::assertSentTo($this->admin, ProjectStatusUpdated::class);
});

it('stores mutes sparsely, so a category added later is delivered by default', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => true],
        NotificationCategories::REPORTING => ['mail' => true, 'database' => true],
    ]);

    // Only the switch that is OFF is written. Everything absent is on, so a
    // category shipped by a later module is never silently muted by a
    // preference row written before it existed.
    expect(User::query()->whereKey($this->officer->id)->sole()->notification_preferences)
        ->toBe([NotificationCategories::PROJECTS => ['mail' => false]]);
});

it('clears the column entirely when every switch goes back on', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => false],
    ]);

    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => true, 'database' => true],
    ]);

    expect(User::query()->whereKey($this->officer->id)->sole()->notification_preferences)->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* Categories that cannot be muted */
/* -------------------------------------------------------------------------- */

it('delivers a credential-bearing category whatever the preference says', function () {
    // An invitation carries a credential and an expiry; muting it would lock
    // somebody out while telling them nothing.
    $this->officer->forceFill([
        'notification_preferences' => [NotificationCategories::IAM => ['mail' => false, 'database' => false]],
    ])->save();

    $channels = NotificationCategories::filter(NotificationCategories::IAM, $this->officer, ['mail', 'database']);

    expect($channels)->toBe(['mail', 'database'])
        ->and(NotificationCategories::isMutable(NotificationCategories::IAM))->toBeFalse();
});

it('refuses to write a mute for a non-mutable category at all', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::IAM => ['mail' => false, 'database' => false],
    ]);

    expect(User::query()->whereKey($this->officer->id)->sole()->notification_preferences)->toBeNull();
});

it('shows the fixed categories on the screen rather than hiding them', function () {
    // A person is entitled to know what the platform will always send them.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/settings/notifications'))
        ->assertOk()
        ->assertSee(NotificationCategories::label(NotificationCategories::IAM))
        ->assertSee(NotificationCategories::label(NotificationCategories::PROJECTS));
});

it('hands a notifiable that is not a platform user every channel', function () {
    // An invitation addressed to a bare email has no preferences and gets
    // everything — the filter refuses to guess.
    $anonymous = Notification::route('mail', 'nobody@example.test');

    expect(NotificationCategories::filter(NotificationCategories::PROJECTS, $anonymous, ['mail', 'database']))
        ->toBe(['mail', 'database']);

    // The invitation really is in the category that cannot be muted, so the
    // on-demand notifiable above is the path an invite actually takes.
    $invitation = (new InviteUser)($this->admin, 'new.officer@works.test', Role::MeOfficer, $this->works);

    expect((new UserInvited($invitation, 'plaintext-token'))->notificationCategory())
        ->toBe(NotificationCategories::IAM)
        ->and((new UserInvited($invitation, 'plaintext-token'))->via($anonymous))
        ->toBe(['mail']);
});

/* -------------------------------------------------------------------------- */
/* The screen */
/* -------------------------------------------------------------------------- */

it('saves a person’s own switches from the screen and reads them back', function () {
    $component = Livewire::actingAs($this->officer)
        ->test(NotificationPreferences::class)
        ->assertOk()
        ->assertSet('preferences.'.NotificationCategories::PROJECTS.'.mail', true);

    $component->set('preferences.'.NotificationCategories::PROJECTS.'.mail', false)
        ->call('save');

    expect(statusNotification($this->project)->via(User::query()->whereKey($this->officer->id)->sole()))
        ->toBe(['database']);

    // A fresh mount shows the switch where the person left it.
    Livewire::actingAs($this->officer)
        ->test(NotificationPreferences::class)
        ->assertSet('preferences.'.NotificationCategories::PROJECTS.'.mail', false)
        ->assertSet('preferences.'.NotificationCategories::PROJECTS.'.database', true);
});

it('offers a switch only for the categories a person may actually mute', function () {
    $component = Livewire::actingAs($this->officer)->test(NotificationPreferences::class);

    $switchable = array_keys($component->get('preferences'));
    $mutable = array_keys(array_filter(
        NotificationCategories::all(),
        fn (array $definition): bool => $definition['mutable'],
    ));

    expect($switchable)->toBe($mutable)
        ->and($switchable)->not->toContain(NotificationCategories::IAM);
});

it('records a preference change in the append-only log, with its before and after', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => true],
    ]);

    $entry = Activity::query()->where('description', 'user.notification_preferences_updated')->sole();

    expect($entry->causer_id)->toBe($this->officer->id)
        ->and($entry->subject_id)->toBe($this->officer->id)
        ->and($entry->properties->get('attributes')['notification_preferences'])
        ->toBe([NotificationCategories::PROJECTS => ['mail' => false]]);
});

it('writes no phantom audit row for a save that changed nothing', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => true, 'database' => true],
    ]);

    expect(Activity::query()->where('description', 'user.notification_preferences_updated')->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* A preference is not an administrative lever */
/* -------------------------------------------------------------------------- */

it('offers no path for one person to set another person’s preferences', function () {
    // No user parameter on the screen and none on the Action's public
    // surface beyond "the person doing it". Checked structurally, because a
    // test of the buttons that exist today passes the day a new one is added.
    $component = new ReflectionClass(NotificationPreferences::class);

    foreach ($component->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getParameters() as $parameter) {
            expect((string) $parameter->getType())->not->toContain(User::class);
        }
    }

    expect($component->hasProperty('userId'))->toBeFalse()
        ->and($component->hasProperty('user'))->toBeFalse();
});

it('keeps one person’s preferences off another person’s notifications', function () {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => false],
    ]);

    expect(statusNotification($this->project)->via($this->officer))->toBe([])
        ->and(statusNotification($this->project)->via($this->admin))->toBe(['database', 'mail']);
});

it('follows a person between workspaces — one person, one inbox', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    memberOf($this->officer, $health, Role::MeOfficer);

    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => true],
    ]);

    $healthProject = $this->current->runAs($health, fn (): Project => Project::factory()->ongoing()->create());

    // The preference is global: a consultant working for three ministries has
    // one inbox, not three identities.
    expect(statusNotification($healthProject)->via($this->officer))->toBe(['database']);
});
