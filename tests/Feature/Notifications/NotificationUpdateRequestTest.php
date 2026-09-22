<?php

/**
 * The bell and the notification centre, driven over HTTP the way a browser
 * drives them: the page is served, then every click is a POST to the Livewire
 * update endpoint on the same host, landing in a FRESH PHP process.
 *
 * Livewire::test() cannot see the difference, and neither can a feature test
 * that lets the page GET and the update POST share one container: whatever the
 * page request bound (the surface, the tenant, the URL defaults) is still
 * sitting there when the POST arrives. That is exactly how every tenant and
 * oversight component update — the bell's "Mark read" and "Mark all read"
 * included — could 404 in a real browser while the suite stayed green. So the
 * request-scoped state is dropped between the two requests here, as a new
 * process would drop it.
 */

use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationFeed;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    actingWithoutTenant();
});

function inboxRow(User $user, ?Tenant $tenant, ?string $projectUlid = null, string $title = 'Township Road Rehabilitation'): DatabaseNotification
{
    return DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Projects\\ProjectStatusUpdated',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => [
            'type' => 'project.status_changed',
            'project_ulid' => $projectUlid ?? (string) Str::ulid(),
            'project_title' => $title,
            'project_reference' => 'WKS/2026/001',
            'tenant_id' => $tenant?->id,
        ],
        'read_at' => null,
    ]);
}

/** Every component snapshot on a page, keyed by component name. */
function snapshotsOn(string $url): array
{
    $page = test()->get($url)->assertOk();

    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    $byName = [];

    foreach ($matches[1] ?? [] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES);
        $name = json_decode($snapshot, true)['memo']['name'] ?? null;

        if (is_string($name)) {
            $byName[$name] = $snapshot;
        }
    }

    return $byName;
}

/** POST one component call to $host's update endpoint, from a "fresh process". */
function callFromFreshProcess(string $host, string $snapshot, string $method, array $params = []): TestResponse
{
    app()->forgetScopedInstances();
    (fn () => $this->routeUrl()->defaultParameters = [])->call(app('url'));

    return test()->postJson(
        'http://'.$host.app(HandleRequests::class)->getUpdateUri(),
        ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]]],
        ['X-Livewire' => '1'],
    );
}

function renderedHtml(TestResponse $response): string
{
    return (string) ($response->json('components.0.effects.html') ?? '');
}

/* -------------------------------------------------------------------------- */
/* Workspace surface */
/* -------------------------------------------------------------------------- */

it('marks one item read from the bell on the workspace host', function () {
    $item = inboxRow($this->officer, $this->works, $this->project->ulid);

    $this->actingAs($this->officer);
    $bell = snapshotsOn(tenantUrl($this->works, '/'))['shared.notification-bell'] ?? null;

    expect($bell)->not->toBeNull();

    $response = callFromFreshProcess('works.mne.test', $bell, 'markRead', [$item->id])->assertOk();

    expect($item->fresh()->read_at)->not->toBeNull()
        // The re-render still links to the record, on this workspace's host —
        // the URL default the update request rebuilt, not one left over from
        // the page.
        ->and(renderedHtml($response))->toContain('http://works.mne.test/projects/'.$this->project->ulid);
});

it('marks everything read from the bell on the workspace host, and nothing in another workspace', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    memberOf($this->officer, $health, Role::MeOfficer);

    inboxRow($this->officer, $this->works);
    inboxRow($this->officer, $this->works);
    $elsewhere = inboxRow($this->officer, $health);

    $this->actingAs($this->officer);
    $bell = snapshotsOn(tenantUrl($this->works, '/'))['shared.notification-bell'];

    callFromFreshProcess('works.mne.test', $bell, 'markAllRead')->assertOk();

    expect(NotificationFeed::unreadCount($this->officer, $this->works))->toBe(0)
        ->and($elsewhere->fresh()->read_at)->toBeNull();
});

it('refreshes the bell on its poll from the workspace host', function () {
    $this->actingAs($this->officer);
    $bell = snapshotsOn(tenantUrl($this->works, '/'))['shared.notification-bell'];

    inboxRow($this->officer, $this->works, title: 'Arrived after the page loaded');

    $response = callFromFreshProcess('works.mne.test', $bell, '$refresh')->assertOk();

    expect(renderedHtml($response))->toContain('Arrived after the page loaded');
});

it('marks read and unread from the notification centre on the workspace host', function () {
    $item = inboxRow($this->officer, $this->works);

    $this->actingAs($this->officer);
    $centre = snapshotsOn(tenantUrl($this->works, '/notifications'))['tenant.notifications.notification-centre'];

    $afterRead = callFromFreshProcess('works.mne.test', $centre, 'markRead', [$item->id])->assertOk();

    expect($item->fresh()->read_at)->not->toBeNull();

    callFromFreshProcess('works.mne.test', $afterRead->json('components.0.snapshot'), 'markUnread', [$item->id])->assertOk();

    expect($item->fresh()->read_at)->toBeNull();
});

it('refuses to mark another person’s item read through the workspace endpoint', function () {
    $other = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $theirs = inboxRow($other, $this->works);

    $this->actingAs($this->officer);
    $bell = snapshotsOn(tenantUrl($this->works, '/'))['shared.notification-bell'];

    callFromFreshProcess('works.mne.test', $bell, 'markRead', [$theirs->id])->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* Oversight surface */
/* -------------------------------------------------------------------------- */

it('marks one item read from the bell on the oversight host, linking to the state project page', function () {
    $viewer = userWithRole(Role::ExecutiveViewer);
    $item = inboxRow($viewer, $this->works, $this->project->ulid);

    $this->actingAs($viewer);
    $bell = snapshotsOn(oversightUrl('/notifications'))['shared.notification-bell'];

    $response = callFromFreshProcess('oversight.mne.test', $bell, 'markRead', [$item->id])->assertOk();

    expect($item->fresh()->read_at)->not->toBeNull()
        ->and(renderedHtml($response))->toContain('http://oversight.mne.test/projects/'.$this->project->ulid);
});

it('marks everything read from the oversight notification centre', function () {
    $viewer = userWithRole(Role::ExecutiveViewer);
    inboxRow($viewer, $this->works);
    inboxRow($viewer, null);

    $this->actingAs($viewer);
    $centre = snapshotsOn(oversightUrl('/notifications'))['oversight.notifications.notification-centre'];

    callFromFreshProcess('oversight.mne.test', $centre, 'markAllRead')->assertOk();

    expect(NotificationFeed::unreadCount($viewer, null))->toBe(0);
});
