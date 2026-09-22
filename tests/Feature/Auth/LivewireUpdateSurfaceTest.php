<?php

/**
 * Every Livewire update route binds the surface it serves.
 *
 * MatchLivewireComponentToSurface (and DocumentPanel's signed-link builder)
 * read CurrentSurface on the UPDATE request. ResolveSurface only runs on the
 * page routes, so the update routes left the surface unbound and it fell back
 * to Portal — every tenant and oversight form interaction answered 404 in the
 * browser.
 *
 * The existing HTTP tests never saw it: within one test, the page GET and the
 * update POST share an application container, so the surface ResolveSurface
 * bound for the page was still sitting there when the POST arrived. A real
 * browser's POST lands in a fresh PHP process. These cases reproduce that by
 * forgetting the request-scoped state between the two requests.
 */

use App\Enums\Role;
use App\Models\Project;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->project = Project::factory()->for($this->works)->ongoing()->create([
        'sector_id' => Sector::factory()->create(['is_active' => true])->id,
    ]);

    actingWithoutTenant();
});

/** The named component's snapshot, lifted from a page exactly as the browser holds it. */
function surfaceSnapshot(string $url, string $component): string
{
    $page = test()->get($url)->assertOk();

    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    foreach ($matches[1] ?? [] as $snapshot) {
        $decoded = html_entity_decode($snapshot, ENT_QUOTES);

        if (str_contains($decoded, '"name":"'.$component.'"')) {
            return $decoded;
        }
    }

    test()->fail("No wire:snapshot for [{$component}] on {$url}.");
}

/**
 * POST a $refresh to the update endpoint on $host, from a "fresh process":
 * nothing the page request bound may leak into the update request.
 */
function refreshFromFreshProcess(string $host, string $snapshot): TestResponse
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

it('serves a tenant form’s component updates on the tenant host', function () {
    $this->actingAs($this->officer);

    $snapshot = surfaceSnapshot(tenantUrl($this->works, '/projects/create'), 'tenant.projects.project-create');

    refreshFromFreshProcess('works.'.config('platform.domain'), $snapshot)->assertOk();
});

it('serves an oversight component’s updates on the oversight host', function () {
    $this->actingAs(userWithRole(Role::ExecutiveViewer));

    $snapshot = surfaceSnapshot(oversightUrl('/portfolio'), 'oversight.projects.portfolio');

    refreshFromFreshProcess('oversight.'.config('platform.domain'), $snapshot)->assertOk();
});

it('serves the portal’s own component updates on the apex', function () {
    $snapshot = surfaceSnapshot(portalUrl('/projects'), 'portal.project-browser');

    refreshFromFreshProcess(config('platform.domain'), $snapshot)->assertOk();
});

it('still refuses a component replayed onto another surface’s endpoint', function () {
    // The control: binding the surface must not have turned the guard off.
    $this->actingAs($this->officer);

    $snapshot = surfaceSnapshot(tenantUrl($this->works, '/projects/create'), 'tenant.projects.project-create');

    refreshFromFreshProcess(config('platform.domain'), $snapshot)->assertNotFound();
});
