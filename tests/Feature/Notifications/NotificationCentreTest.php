<?php

/**
 * The bell and the notification centre, on both app shells.
 *
 * WHY NOTIFICATIONS ARE NOT TENANT-OWNED: a notification belongs to a PERSON,
 * and one person may hold memberships in several MDAs — a consultant working
 * for three ministries has one inbox, not three identities. So the boundary
 * asserted here is the USER, and the workspace filter on top of it is a filter
 * on the notification's own payload, not a tenancy scope pretending to be one.
 *
 * Both properties are proven: a person never sees another person's items on
 * any surface, and on a workspace subdomain they see that workspace's items
 * plus the ones that belong to them rather than to any ministry.
 */

use App\Enums\Role;
use App\Livewire\Oversight\Notifications\NotificationCentre as OversightNotificationCentre;
use App\Livewire\Shared\NotificationBell;
use App\Livewire\Tenant\Notifications\NotificationCentre as TenantNotificationCentre;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationFeed;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Put one database notification in a person's inbox, optionally belonging to
 * a workspace. Written directly because the point under test is the FEED, not
 * whichever module happened to raise the item.
 */
function inboxItem(User $user, ?Tenant $tenant, string $headline, ?CarbonImmutable $at = null): DatabaseNotification
{
    $notification = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Projects\\ProjectStatusUpdated',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => [
            'type' => 'project.status_changed',
            // NotificationLink::summary() builds the row's line from the
            // payload, so the text a test asserts on has to live where the
            // renderer actually reads it.
            'project_title' => $headline,
            'project_ulid' => (string) Str::ulid(),
            'tenant_id' => $tenant?->id,
        ],
        'read_at' => null,
    ]);

    $notification->setCreatedAt($at ?? CarbonImmutable::now());
    $notification->setUpdatedAt($at ?? CarbonImmutable::now());
    $notification->save();

    return $notification;
}

beforeEach(function () {
    seedPermissions();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 9));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    // A consultant who works for both ministries — one account, one inbox.
    $this->consultant = memberOf(User::factory()->create(['name' => 'Bola Adeyemi']), $this->works, Role::Consultant);
    memberOf($this->consultant, $this->health, Role::Consultant);

    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);

    actingOnTenant($this->works);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* The unread count is per user */
/* -------------------------------------------------------------------------- */

it('counts only the signed-in person’s unread items', function () {
    inboxItem($this->consultant, $this->works, 'Your road project was suspended');
    inboxItem($this->consultant, $this->works, 'A return is waiting for you');
    inboxItem($this->officer, $this->works, 'Not for the consultant at all');

    $bell = Livewire::actingAs($this->consultant)->test(NotificationBell::class);

    expect($bell->instance()->unreadCount())->toBe(2);

    $otherBell = Livewire::actingAs($this->officer)->test(NotificationBell::class);

    expect($otherBell->instance()->unreadCount())->toBe(1);
});

it('never shows one person another person’s items in the dropdown', function () {
    inboxItem($this->officer, $this->works, 'Reserved for the M&E officer');
    inboxItem($this->consultant, $this->works, 'Reserved for the consultant');

    Livewire::actingAs($this->consultant)
        ->test(NotificationBell::class)
        ->assertSee('Reserved for the consultant')
        ->assertDontSee('Reserved for the M&E officer');
});

it('404s an attempt to mark another person’s notification as read', function () {
    $theirs = inboxItem($this->officer, $this->works, 'Reserved for the M&E officer');

    // Scoped to the signed-in person's own feed, so another account's
    // notification id resolves to nothing rather than to a 403 that would
    // confirm it exists.
    Livewire::actingAs($this->consultant)
        ->test(NotificationBell::class)
        ->call('markRead', $theirs->id)
        ->assertNotFound();

    Livewire::actingAs($this->consultant)
        ->test(TenantNotificationCentre::class)
        ->call('markRead', $theirs->id)
        ->assertNotFound();

    Livewire::actingAs($this->consultant)
        ->test(TenantNotificationCentre::class)
        ->call('markUnread', $theirs->id)
        ->assertNotFound();

    expect(DatabaseNotification::query()->whereKey($theirs->id)->sole()->read_at)->toBeNull();
});

it('marks all read for one person only, leaving everyone else’s inbox alone', function () {
    inboxItem($this->consultant, $this->works, 'One');
    inboxItem($this->consultant, $this->works, 'Two');
    $theirs = inboxItem($this->officer, $this->works, 'Not mine');

    Livewire::actingAs($this->consultant)
        ->test(TenantNotificationCentre::class)
        ->call('markAllRead');

    expect(NotificationFeed::unreadCount($this->consultant, $this->works))->toBe(0)
        ->and(DatabaseNotification::query()->whereKey($theirs->id)->sole()->read_at)->toBeNull()
        ->and(NotificationFeed::unreadCount($this->officer, $this->works))->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* Mark read / unread */
/* -------------------------------------------------------------------------- */

it('marks one item read and puts it back again', function () {
    $item = inboxItem($this->consultant, $this->works, 'Your road project was suspended');

    $centre = Livewire::actingAs($this->consultant)->test(TenantNotificationCentre::class);

    expect($centre->instance()->unreadCount())->toBe(1);

    $centre->call('markRead', $item->id);

    expect(DatabaseNotification::query()->whereKey($item->id)->sole()->read_at)->not->toBeNull()
        ->and($centre->instance()->unreadCount())->toBe(0);

    $centre->call('markUnread', $item->id);

    expect(DatabaseNotification::query()->whereKey($item->id)->sole()->read_at)->toBeNull()
        ->and($centre->instance()->unreadCount())->toBe(1);
});

it('filters the centre down to unread by default, and to everything on demand', function () {
    $read = inboxItem($this->consultant, $this->works, 'Already dealt with');
    inboxItem($this->consultant, $this->works, 'Still waiting');

    $read->markAsRead();

    $centre = Livewire::actingAs($this->consultant)
        ->test(TenantNotificationCentre::class)
        ->assertSet('filter', 'unread');

    expect($centre->instance()->notifications()->total())->toBe(1);

    $centre->set('filter', 'all');

    expect($centre->instance()->notifications()->total())->toBe(2);
});

it('shows the bell no more than its preview, and links through to the whole desk', function () {
    foreach (range(1, 9) as $n) {
        inboxItem($this->consultant, $this->works, 'Item '.$n);
    }

    $bell = Livewire::actingAs($this->consultant)->test(NotificationBell::class);

    expect($bell->instance()->unreadCount())->toBe(9)
        ->and($bell->instance()->recent())->toHaveCount(5)
        ->and($bell->instance()->centreUrl())->toBe(route('tenant.notifications.index'));
});

/* -------------------------------------------------------------------------- */
/* Which feed each surface reads */
/* -------------------------------------------------------------------------- */

it('shows a workspace subdomain that workspace’s items, plus the ones that are the person’s own', function () {
    inboxItem($this->consultant, $this->works, 'Works: your road project was suspended');
    inboxItem($this->consultant, $this->health, 'Health: your clinic project was suspended');
    inboxItem($this->consultant, null, 'Your account role changed');

    actingOnTenant($this->works);

    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/notifications?filter=all'))
        ->assertOk()
        ->assertSee('Works: your road project was suspended')
        // An item with no workspace belongs to the PERSON and follows them
        // everywhere.
        ->assertSee('Your account role changed')
        ->assertDontSee('Health: your clinic project was suspended');

    actingOnTenant($this->health);
    URL::defaults(['tenant' => $this->health->slug]);

    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->health, '/notifications?filter=all'))
        ->assertOk()
        ->assertSee('Health: your clinic project was suspended')
        ->assertSee('Your account role changed')
        ->assertDontSee('Works: your road project was suspended');
});

it('counts the bell per workspace out of the same inbox', function () {
    inboxItem($this->consultant, $this->works, 'Works item');
    inboxItem($this->consultant, $this->health, 'Health item one');
    inboxItem($this->consultant, $this->health, 'Health item two');
    inboxItem($this->consultant, null, 'Personal item');

    expect(NotificationFeed::unreadCount($this->consultant, $this->works))->toBe(2)   // works + personal
        ->and(NotificationFeed::unreadCount($this->consultant, $this->health))->toBe(3)  // health ×2 + personal
        // Unfiltered — the state surface binds no tenant.
        ->and(NotificationFeed::unreadCount($this->consultant, null))->toBe(4);
});

it('shows the state surface one desk, unfiltered by workspace', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    inboxItem($stateAdmin, $this->works, 'Works: a return is overdue');
    inboxItem($stateAdmin, $this->health, 'Health: a return is overdue');
    inboxItem($stateAdmin, null, 'A state-level escalation');

    actingWithoutTenant();

    $this->actingAs($stateAdmin)
        ->get(oversightUrl('/notifications?filter=all'))
        ->assertOk()
        ->assertSee('Works: a return is overdue')
        ->assertSee('Health: a return is overdue')
        ->assertSee('A state-level escalation');

    $centre = Livewire::actingAs($stateAdmin)->test(OversightNotificationCentre::class);

    expect($centre->instance()->unreadCount())->toBe(3);
});

it('marks all read on a workspace surface without clearing the other workspace’s items', function () {
    inboxItem($this->consultant, $this->works, 'Works item');
    inboxItem($this->consultant, $this->health, 'Health item');
    inboxItem($this->consultant, null, 'Personal item');

    actingOnTenant($this->works);

    Livewire::actingAs($this->consultant)
        ->test(TenantNotificationCentre::class)
        ->call('markAllRead');

    // Works + the personal item are cleared; the Health item is untouched,
    // because the officer was standing in Works when they cleared the desk.
    expect(NotificationFeed::unreadCount($this->consultant, $this->works))->toBe(0)
        ->and(NotificationFeed::unreadCount($this->consultant, $this->health))->toBe(1)
        ->and(NotificationFeed::unreadCount($this->consultant, null))->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* Ordering, empties and access */
/* -------------------------------------------------------------------------- */

it('reads the desk newest first', function () {
    inboxItem($this->consultant, $this->works, 'Oldest', CarbonImmutable::create(2026, 6, 1, 9));
    inboxItem($this->consultant, $this->works, 'Newest', CarbonImmutable::create(2026, 6, 14, 9));
    inboxItem($this->consultant, $this->works, 'Middle', CarbonImmutable::create(2026, 6, 7, 9));

    $rows = Livewire::actingAs($this->consultant)
        ->test(TenantNotificationCentre::class)
        ->instance()
        ->notifications();

    expect($rows->pluck('data.project_title')->all())->toBe(['Newest', 'Middle', 'Oldest']);
});

it('shows an empty desk as a designed state rather than a blank screen', function () {
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/notifications'))
        ->assertOk()
        ->assertSee(__('Nothing unread'));
});

it('opens the desk to any member without a second permission check', function () {
    // A notification was already addressed to this person by whatever raised
    // it; a second authority check here could only hide something they were
    // deliberately sent.
    inboxItem($this->consultant, $this->works, 'Your road project was suspended');

    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/notifications'))
        ->assertOk()
        ->assertSee('Your road project was suspended');
});

it('refuses the desk and the bell to a guest', function () {
    $this->get(tenantUrl($this->works, '/notifications'))->assertRedirect();
    $this->get(oversightUrl('/notifications'))->assertRedirect();

    Livewire::test(TenantNotificationCentre::class)->assertForbidden();
    Livewire::test(OversightNotificationCentre::class)->assertForbidden();
});
