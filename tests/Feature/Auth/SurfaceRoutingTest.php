<?php

/**
 * ResolveSurface (docs/design/auth-surfaces.md §1.2–§1.3): Fortify registers one
 * domain-less set of auth routes, and the surface decides which of them each host
 * may actually serve. Everything here is host-driven, so every case hits a real
 * subdomain.
 */

use App\Enums\Role;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
});

it('serves no registration route on any surface, because access is invitation-only', function (string $method) {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->call($method, tenantUrl($this->tenant, '/register'))->assertNotFound();
    $this->call($method, oversightUrl('/register'))->assertNotFound();
    $this->call($method, portalUrl('/register'))->assertNotFound();
})->with(['GET', 'POST']);

it('serves the sign-in form on a tenant workspace host', function () {
    $this->get(tenantUrl($this->tenant, '/login'))
        ->assertOk()
        ->assertSee('Sign in');
});

it('serves the sign-in form on the oversight host', function () {
    $this->get(oversightUrl('/login'))
        ->assertOk()
        ->assertSee('Sign in');
});

it('hides interactive sign-in from the public portal apex', function () {
    $this->get(portalUrl('/login'))->assertNotFound();
});

it('hides the two-factor challenge and logout from the public portal apex', function (string $method, string $path) {
    $this->call($method, portalUrl($path))->assertNotFound();
})->with([
    ['GET', '/two-factor-challenge'],
    ['POST', '/two-factor-challenge'],
    ['POST', '/logout'],
]);

it('serves password reset only on the apex, where queued reset links always point', function () {
    $this->get(portalUrl('/forgot-password'))
        ->assertOk()
        ->assertSee('email', false);
});

it('redirects a password reset request on a tenant host to the apex', function () {
    $this->get(tenantUrl($this->tenant, '/forgot-password'))
        ->assertRedirect(portalUrl('/forgot-password'));
});

it('redirects a password reset request on the oversight host to the apex', function () {
    $this->get(oversightUrl('/forgot-password'))
        ->assertRedirect(portalUrl('/forgot-password'));
});

it('redirects a password reset link opened on a tenant host to the apex, token intact', function () {
    $this->get(tenantUrl($this->tenant, '/reset-password/some-token?email=officer%40works.test'))
        ->assertRedirect(portalUrl('/reset-password/some-token?email=officer%40works.test'));
});

it('404s an auth route on an unknown subdomain instead of offering a login form', function () {
    $this->get('http://ghost.'.config('platform.domain').'/login')->assertNotFound();
});

it('404s an auth route on an inactive tenant subdomain', function () {
    $dormant = Tenant::factory()->inactive()->create(['slug' => 'dormant']);

    $this->get(tenantUrl($dormant, '/login'))->assertNotFound();
});

it('gives every surface its own livewire update route so component calls keep their tenancy', function () {
    $routes = Route::getRoutes();

    $tenantRoute = $routes->getByName('tenant.livewire-update');
    $oversightRoute = $routes->getByName('oversight.livewire-update');
    $portalRoute = $routes->getByName('livewire.update');

    expect($tenantRoute)->not->toBeNull()
        ->and($oversightRoute)->not->toBeNull()
        ->and($portalRoute)->not->toBeNull();

    expect($tenantRoute->getDomain())->toBe('{tenant}.'.config('platform.domain'))
        ->and($oversightRoute->getDomain())->toBe('oversight.'.config('platform.domain'))
        ->and($portalRoute->getDomain())->toBe(config('platform.domain'));

    expect($tenantRoute->methods())->toContain('POST')
        ->and($oversightRoute->methods())->toContain('POST')
        ->and($portalRoute->methods())->toContain('POST');

    expect($tenantRoute->gatherMiddleware())->toContain(ResolveTenant::class);
});

/*
| The three assertions above prove the routes EXIST with the right domains.
| They passed while every oversight component update 404'd, because existence
| is not dispatch: the router walks same-path routes in registration order and
| '{tenant}.<domain>' matches any single-label host, so a wildcard registered
| first swallows 'oversight.' and ResolveTenant 404s it as a reserved slug.
| These cases pin the resolution itself, per host.
*/
it('dispatches each host to its own livewire update route', function (string $host, string $expected) {
    $request = Request::create(
        'http://'.$host.'.'.config('platform.domain').app(HandleRequests::class)->getUpdateUri(),
        'POST',
    );

    $matched = Route::getRoutes()->match($request);

    expect($matched->getName())->toBe($expected);
})->with([
    'oversight host' => ['oversight', 'oversight.livewire-update'],
    'tenant host' => ['works', 'tenant.livewire-update'],
]);

it('completes a real livewire round trip on the oversight host', function () {
    seedPermissions();

    $admin = userWithRole(Role::StateAdmin);
    $this->actingAs($admin);

    // Drive it the way the browser does: render the screen, take the snapshot
    // the client would hold, and post it back to the update endpoint. Nothing
    // here is a Livewire internal — Livewire::test() bypasses HTTP entirely,
    // which is exactly why the 404 survived a green suite.
    $page = $this->get(oversightUrl('/users'))->assertOk();

    expect($page->getContent())->toContain('wire:snapshot');

    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    $snapshot = html_entity_decode($matches[1] ?? '', ENT_QUOTES);

    $response = $this->postJson(
        oversightUrl(app(HandleRequests::class)->getUpdateUri()),
        ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]],
        ['X-Livewire' => '1'],
    );

    // A 404 here is the wildcard-capture regression: the tenant route would
    // have swallowed this host and ResolveTenant would reject 'oversight' as
    // a reserved slug.
    $response->assertOk()->assertJsonStructure(['components']);
});

it('registers no domain-less livewire update route that would bypass tenant resolution', function () {
    $domainless = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true)
            && str_ends_with($route->uri(), '/update')
            && str_contains($route->uri(), 'livewire')
            && $route->getDomain() === null);

    expect($domainless)->toBeEmpty();
});
