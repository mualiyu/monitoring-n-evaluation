<?php

/**
 * The cross-tenant Livewire replay — a security audit finding, kept as a test.
 *
 * THE EXPLOIT, end to end:
 *   1. An officer belongs to Works and to nothing else. `GET health.…/issues`
 *      correctly answers 403.
 *   2. They sign in at `health.…/login` anyway. Fortify is registered
 *      DOMAIN-LESS, so authentication succeeds and the session is authenticated
 *      on that host even though the workspace itself refuses them.
 *   3. They take the `wire:snapshot` their own workspace legitimately gave
 *      them and POST it to Health's Livewire update endpoint. The snapshot
 *      checksum is computed over the snapshot, not the host, so it validates.
 *   4. ResolveTenant binds Ministry of Health. Livewire does NOT re-run
 *      mount(), so the component's `authorize()` never fires.
 *   5. The list query runs — and used to answer 200 with Health's register,
 *      because Project::scopeVisibleTo narrowed nothing for a user holding no
 *      role in the bound team.
 *
 * Two independent gates close it, and this file asserts both, because either
 * one alone would leave a plausible way back in:
 *   - EnsureTenantMembership is now on the tenant update route;
 *   - scopeVisibleTo fails closed for a user with no role in the bound team.
 */

use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);
    $this->outsider = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    Project::factory()->create(['title' => 'Township Road Rehabilitation']);

    actingOnTenant($this->health);
    Project::factory()->create(['title' => 'Cottage Hospital Upgrade']);

    actingWithoutTenant();
});

/** Lift a component's snapshot out of a page the user is genuinely entitled to. */
function snapshotFrom(Tenant $tenant, string $path, string $component): string
{
    $page = test()->get(tenantUrl($tenant, $path))->assertOk();

    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    foreach ($matches[1] ?? [] as $snapshot) {
        $decoded = html_entity_decode($snapshot, ENT_QUOTES);

        if (str_contains($decoded, '"name":"'.$component.'"')) {
            return $decoded;
        }
    }

    test()->fail("No wire:snapshot for [{$component}] on {$path}.");
}

/** Replay a snapshot against ANOTHER tenant's update endpoint. */
function replayAgainst(Tenant $tenant, string $snapshot): TestResponse
{
    return test()->postJson(
        tenantUrl($tenant, app(HandleRequests::class)->getUpdateUri()),
        ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [],
        ]]],
        ['X-Livewire' => '1'],
    );
}

it('refuses a component update replayed against a workspace the user does not belong to', function () {
    $this->actingAs($this->outsider);

    // The page they are entitled to, and the snapshot it hands them.
    $snapshot = snapshotFrom($this->works, '/projects', 'tenant.projects.project-index');

    // The full-page route already refuses them on the neighbour's host…
    $this->get(tenantUrl($this->health, '/projects'))->assertForbidden();

    // …and so, now, does the update endpoint behind it. Before
    // EnsureTenantMembership was added to that route this answered 200 with
    // Ministry of Health's register in the body.
    $response = replayAgainst($this->health, $snapshot);

    expect($response->baseResponse->getStatusCode())->not->toBe(200);
    $response->assertDontSee('Cottage Hospital Upgrade');
});

it('shows a user with no role in the bound workspace nothing at all, not the portfolio', function () {
    // The second gate, asserted at the query rather than the route: even with
    // Health bound and the membership gate somehow passed, a user holding no
    // role in THIS team sees an empty register — not "everyone else sees the
    // tenant portfolio", which is what the leak rode on.
    actingOnTenant($this->health);

    $visible = Project::query()->visibleTo($this->outsider)->pluck('title')->all();

    expect($visible)->toBe([]);
});

it('still shows workspace staff their own register, and a consultant only their assignments', function () {
    actingOnTenant($this->works);

    $staff = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    // The fail-closed branch must not have broken the two legitimate ones.
    expect(Project::query()->visibleTo($staff)->pluck('title')->all())
        ->toBe(['Township Road Rehabilitation'])
        ->and(Project::query()->visibleTo($consultant)->pluck('title')->all())
        ->toBe([]);
});

it('keeps the cross-MDA read working for oversight authority, which holds no tenant role by design', function () {
    actingOnTenant($this->works);

    $stateAdmin = userWithRole(Role::StateAdmin);

    // Oversight permission lives in the GLOBAL team, which no tenant role can
    // reach — so this branch cannot be reached by an MDA user, and the
    // fail-closed default does not break the secretariat.
    expect($stateAdmin->holdsGlobalPermission('projects.view'))->toBeTrue()
        ->and(Project::query()->visibleTo($stateAdmin)->pluck('title')->all())
        ->toBe(['Township Road Rehabilitation']);
});
