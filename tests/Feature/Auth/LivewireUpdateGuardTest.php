<?php

/**
 * The per-request account gates on Livewire component updates.
 *
 * The update routes used to carry only `web` + the header guard, so `active`
 * and `2fa.require` from routes/tenant.php ran when the page was served and
 * then never again: an account deactivated while a tab sat open could keep
 * POSTing writes through wire:click until it next requested a full page.
 * Policies do not close it, because is_active is not a permission and
 * SetUserActive deliberately leaves roles and memberships intact.
 *
 * Livewire's persistent-middleware list cannot fix this: it only fires for a
 * route whose name ends in `livewire.update`, and these routes are named
 * `*.livewire-update` on purpose so Livewire's URI generation never picks the
 * tenant route (it carries a {tenant} domain parameter other surfaces cannot
 * supply). The gates are therefore explicit route middleware.
 *
 * These cases drive the REAL endpoint with a REAL snapshot, because that is
 * the only path where the middleware question arises — Livewire::test() never
 * crosses HTTP.
 */

use App\Actions\Iam\ExemptFromTwoFactor;
use App\Actions\Iam\RevokeTenantAccess;
use App\Enums\Role;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireTwoFactor;
use App\Livewire\Tenant\Projects\ProjectEdit;
use App\Models\Project;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 09:00:00');

    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->sector = Sector::factory()->create(['name' => 'Transport', 'is_active' => true]);

    $this->project = Project::factory()->for($this->works)->ongoing()->create([
        'reference' => 'PRJ-GUARD-01',
        'title' => 'Township Road Rehabilitation',
        'sector_id' => $this->sector->id,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The snapshot the browser would be holding after rendering a screen.
 *
 * Captured SEPARATELY from the POST below, and deliberately so: the whole
 * scenario is "a tab that was legitimately opened, then the account changed
 * underneath it". Re-rendering at POST time would just get the redirect and
 * prove nothing.
 */
function componentSnapshot(Tenant $tenant, string $path): string
{
    $page = test()->get(tenantUrl($tenant, $path))->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}

/** POST a component update to the real endpoint, exactly as the browser does. */
function postComponentUpdate(Tenant $tenant, string $snapshot, string $method, array $params = []): TestResponse
{
    return test()->postJson(
        tenantUrl($tenant, app(HandleRequests::class)->getUpdateUri()),
        ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]]],
        ['X-Livewire' => '1'],
    );
}

it('puts the account gates on both app surfaces’ update routes', function (string $name) {
    // Explicit route middleware, NOT Livewire's persistent-middleware list:
    // that mechanism only fires for a route whose name ends in
    // `livewire.update`, and these are named `*.livewire-update` on purpose.
    // See TenancyServiceProvider.
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())
        ->toContain(EnsureAccountIsActive::class)
        ->toContain(RequireTwoFactor::class);
})->with([
    'tenant' => ['tenant.livewire-update'],
    'oversight' => ['oversight.livewire-update'],
]);

it('refuses a component update from an account deactivated mid-session', function () {
    $this->actingAs($this->officer);

    // The tab is opened while the account is active.
    $snapshot = componentSnapshot($this->works, '/projects/'.$this->project->ulid.'/edit');

    // Oversight deactivates them. Roles and membership survive by design, so
    // the Policy still says yes — only the `active` gate says no.
    $this->officer->forceFill(['is_active' => false])->save();

    postComponentUpdate($this->works, $snapshot, 'save')->assertStatus(302);

    expect($this->project->fresh()->title)->toBe('Township Road Rehabilitation');
});

it('lets an active account complete the same component update', function () {
    // The control: without this, the case above would pass on any breakage.
    $this->actingAs($this->officer);

    $snapshot = componentSnapshot($this->works, '/projects/'.$this->project->ulid.'/edit');

    postComponentUpdate($this->works, $snapshot, 'save')->assertOk();
});

it('refuses a component update once a role’s two-factor deadline has passed', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->actingAs($admin);

    // Opened inside the enrolment grace window.
    $snapshot = componentSnapshot($this->works, '/projects/'.$this->project->ulid.'/edit');

    // Grace runs out with no second factor confirmed.
    Carbon::setTestNow('2026-10-20 09:00:00');

    postComponentUpdate($this->works, $snapshot, 'save')->assertStatus(302);

    expect($this->project->fresh()->title)->toBe('Township Road Rehabilitation');
});

it('still serves component updates to an exempted admin past the deadline', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    (new ExemptFromTwoFactor)(null, $admin);

    $this->actingAs($admin);

    $snapshot = componentSnapshot($this->works, '/projects/'.$this->project->ulid.'/edit');

    Carbon::setTestNow('2026-10-20 09:00:00');

    postComponentUpdate($this->works, $snapshot, 'save')->assertOk();
});

/*
|--------------------------------------------------------------------------
| The write path authorizes on its own, not just via mount()
|--------------------------------------------------------------------------
*/

it('authorizes save() independently of the mount guard', function () {
    // Every other authorization case runs mount() first, so the mount guard
    // alone would satisfy them. This one mounts as someone entitled, then
    // removes the permission before calling the mutating method.
    $component = Livewire::actingAs($this->officer)
        ->test(ProjectEdit::class, ['project' => $this->project])
        ->set('title', 'Renamed Without Authority');

    // Signature is (user, tenant, ?actor, ?reason) — the reason is the FOURTH
    // argument; passing it third would name it as the acting user.
    (new RevokeTenantAccess)($this->officer, $this->works, null, 'Reassigned.');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('save')->assertForbidden();

    expect($this->project->fresh()->title)->toBe('Township Road Rehabilitation');
});
