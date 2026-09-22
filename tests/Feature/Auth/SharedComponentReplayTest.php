<?php

/**
 * Replaying a `shared.*` component snapshot must never outlive the authority
 * that rendered it.
 *
 * `shared.*` components are allowed on BOTH app surfaces by
 * MatchLivewireComponentToSurface. Until the update routes bound their surface
 * (BindSurface), every such update 404'd in the browser — a bug, but one that
 * also happened to hide this: ActivityTimeline authorized in mount() only, and
 * Livewire does not re-run mount() on an update. A snapshot lifted from the
 * oversight entity page could be replayed by a guest, by any other MDA's
 * member, or on a foreign tenant host, and the audit trail — before/after
 * values included — came back in the response.
 *
 * Two independent gates now close it: `auth` on the app-surface update routes
 * (no guest Livewire component exists on those hosts), and the timeline
 * re-authorizing on every request (hydrate), not just the first.
 */

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    // A change on the record, so the timeline has an entry to leak.
    $this->works->update(['name' => 'Ministry of Works & Housing']);

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->healthMember = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);

    // The snapshot a legitimate oversight viewer's browser holds.
    $this->actingAs($this->stateAdmin);
    $page = (string) $this->get(oversightUrl('/entities/works'))->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);
    $this->snapshot = collect($matches[1])
        ->map(fn (string $snapshot): string => html_entity_decode($snapshot, ENT_QUOTES))
        ->first(fn (string $snapshot): bool => str_contains($snapshot, '"name":"shared.activity-timeline"'));

    expect($this->snapshot)->not->toBeNull();

    auth()->logout();
    app()->forgetScopedInstances();
});

/** Replay the timeline snapshot on $host, asking for a re-render. */
function replayTimeline(string $host, string $snapshot): TestResponse
{
    app()->forgetScopedInstances();

    return test()->postJson(
        'http://'.$host.app(HandleRequests::class)->getUpdateUri(),
        ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]],
        ['X-Livewire' => '1'],
    );
}

it('sends a guest replaying the timeline on the oversight host to sign in', function () {
    // A redirect, not a 401: the platform renders JSON errors only for api/*
    // (bootstrap/app.php), so `auth` answers the way it does for any page.
    $response = replayTimeline('oversight.'.config('platform.domain'), $this->snapshot);

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toContain('/login')
        ->and((string) $response->getContent())->not->toContain('Ministry of Works &amp; Housing');
});

it('refuses another MDA’s member replaying it on the oversight host', function () {
    $this->actingAs($this->healthMember);

    $response = replayTimeline('oversight.'.config('platform.domain'), $this->snapshot);

    expect($response->status())->toBe(403)
        ->and((string) $response->getContent())->not->toContain('Ministry of Works &amp; Housing');
});

it('refuses it replayed on a foreign tenant host by that tenant’s member', function () {
    $this->actingAs($this->healthMember);

    $response = replayTimeline('health.'.config('platform.domain'), $this->snapshot);

    expect($response->status())->toBe(403)
        ->and((string) $response->getContent())->not->toContain('Ministry of Works &amp; Housing');
});

it('still serves the timeline to the oversight viewer it was rendered for', function () {
    // The control: without it, the refusals above pass on any breakage.
    $this->actingAs($this->stateAdmin);

    replayTimeline('oversight.'.config('platform.domain'), $this->snapshot)->assertOk();
});

it('puts auth on both app-surface update routes, and only those', function (string $name, bool $expected) {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->getName() === $name);

    expect(in_array('auth', $route->gatherMiddleware(), true))->toBe($expected);
})->with([
    'oversight' => ['oversight.livewire-update', true],
    'tenant' => ['tenant.livewire-update', true],
    // The public portal has a guest Livewire component of its own.
    'portal' => ['livewire.update', false],
]);
