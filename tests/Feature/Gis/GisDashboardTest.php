<?php

/**
 * The GIS dashboards: the state map on oversight, the workspace map on each
 * MDA host. Tenancy isolation first — a map is a JSON blob of every visible
 * site, which is exactly where a leak would hide from review.
 */

use App\Enums\ProjectMapCategory;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Oversight\Gis\GisDashboard as OversightGisDashboard;
use App\Livewire\Tenant\Gis\GisDashboard as TenantGisDashboard;
use App\Livewire\Tenant\Projects\ProjectIndex;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectLocation;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gis\ProjectMapBuilder;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-09-21 10:00:00');

    seedPermissions();

    $this->central = Lga::factory()->create(['name' => 'Central', 'code' => 'CEN']);
    $this->riverside = Lga::factory()->create(['name' => 'Riverside', 'code' => 'RIV']);
    $this->roads = Sector::factory()->create(['name' => 'Roads', 'is_active' => true]);
    $this->health = Sector::factory()->create(['name' => 'Health', 'is_active' => true]);

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->ministryOfHealth = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->road = mappedProject($this->central, ['title' => 'Township Road Rehabilitation', 'sector_id' => $this->roads->id], 'ongoing');
    $this->lateRoad = mappedProject($this->riverside, ['title' => 'Ferry Point Bridge Approach', 'sector_id' => $this->roads->id], 'behindSchedule');
    $this->ungeotagged = Project::factory()->ongoing()->create(['title' => 'Unsurveyed Culvert Works', 'sector_id' => $this->roads->id]);
    ProjectLocation::factory()->for($this->ungeotagged)->at($this->central)->withoutCoordinates()->primary()->create();

    actingOnTenant($this->ministryOfHealth);
    $this->clinic = mappedProject($this->central, ['title' => 'Cottage Hospital Upgrade', 'sector_id' => $this->health->id], 'completed');

    actingWithoutTenant();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A project in the CURRENT tenant with one geotagged site in $lga. */
function mappedProject(Lga $lga, array $attributes, string $state): Project
{
    $project = Project::factory()->{$state}()->create($attributes);

    ProjectLocation::factory()->for($project)->at($lga)
        ->coordinates('7.2570000', '5.2050000')
        ->primary()
        ->create(['site_name' => $attributes['title'].' — main site']);

    return $project;
}

/** The named component's snapshot, lifted from a page as the browser holds it. */
function gisSnapshot(string $url, string $component): string
{
    $page = (string) test()->get($url)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $s): string => html_entity_decode($s, ENT_QUOTES))
        ->first(fn (string $s): bool => str_contains($s, '"name":"'.$component.'"'));

    expect($snapshot)->not->toBeNull();

    return $snapshot;
}

/** POST an update to $host's endpoint from a "fresh process", as a browser does. */
function postGisUpdate(string $host, string $snapshot, array $updates = []): TestResponse
{
    app()->forgetScopedInstances();

    return test()->postJson(
        'http://'.$host.app(HandleRequests::class)->getUpdateUri(),
        ['components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]]],
        ['X-Livewire' => '1'],
    );
}

/** @return list<string> */
function pinTitles(array $map): array
{
    return array_values(array_unique(array_column($map['pins'], 'title')));
}

describe('workspace map', function () {
    it('shows a workspace only its own sites, over real HTTP', function () {
        $this->actingAs($this->officer);

        $this->get(tenantUrl($this->works, '/gis'))
            ->assertOk()
            ->assertSee('Township Road Rehabilitation')
            ->assertSee('Ferry Point Bridge Approach')
            ->assertDontSee('Cottage Hospital Upgrade');
    });

    it('never lets the neighbouring workspace see them either', function () {
        $admin = memberOf(User::factory()->create(), $this->ministryOfHealth, Role::MdaAdmin);
        $this->actingAs($admin);

        $this->get(tenantUrl($this->ministryOfHealth, '/gis'))
            ->assertOk()
            ->assertSee('Cottage Hospital Upgrade')
            ->assertDontSee('Township Road Rehabilitation')
            ->assertDontSee('Ferry Point Bridge Approach');
    });

    it('refuses a signed-in user who is not a member of the workspace', function () {
        $this->actingAs($this->officer);

        $this->get(tenantUrl($this->ministryOfHealth, '/gis'))->assertForbidden();
    });

    it('narrows a consultant to the projects they are assigned to', function () {
        actingOnTenant($this->works);
        ProjectAssignment::factory()->consultant()->forUser($this->consultant)->forProject($this->road)->create();
        $this->actingAs($this->consultant);

        $map = Livewire::test(TenantGisDashboard::class)->instance()->map;

        expect(pinTitles($map))->toBe(['Township Road Rehabilitation'])
            ->and($map['totals']['projects'])->toBe(1);
    });

    it('counts geotagged sites on the map and ungeotagged projects apart', function () {
        actingOnTenant($this->works);
        $this->actingAs($this->officer);

        $map = Livewire::test(TenantGisDashboard::class)->instance()->map;

        expect($map['totals'])->toMatchArray(['projects' => 2, 'sites' => 2, 'overdue' => 1, 'unmapped' => 1])
            ->and(pinTitles($map))->not->toContain('Unsurveyed Culvert Works');
    });

    it('draws an overdue project in the overdue category, ahead of the rest', function () {
        actingOnTenant($this->works);
        $this->actingAs($this->officer);

        $map = Livewire::test(TenantGisDashboard::class)->instance()->map;

        expect($map['pins'][0])->toMatchArray([
            'title' => 'Ferry Point Bridge Approach',
            'category' => ProjectMapCategory::Overdue->value,
            'overdue' => true,
        ])
            ->and($map['by_category'][ProjectMapCategory::Overdue->value])->toBe(1)
            ->and($map['by_category'][ProjectMapCategory::InProgress->value])->toBe(1);
    });

    it('filters by LGA, status and overdue, and redraws the map on each change', function () {
        actingOnTenant($this->works);
        $this->actingAs($this->officer);

        $component = Livewire::test(TenantGisDashboard::class)
            ->set('lga', (string) $this->riverside->id)
            ->assertDispatched('project-map-updated');

        expect(pinTitles($component->instance()->map))->toBe(['Ferry Point Bridge Approach']);

        $component->set('lga', '')->set('overdue', true);
        expect(pinTitles($component->instance()->map))->toBe(['Ferry Point Bridge Approach']);

        $component->set('overdue', false)->set('status', ProjectStatus::Completed->value);
        expect($component->instance()->map['pins'])->toBe([]);

        $component->call('clearFilters')->assertDispatched('project-map-updated');
        expect($component->instance()->map['totals']['projects'])->toBe(2);
    });

    it('focuses one LGA from the breakdown table and counts distinct projects per area', function () {
        actingOnTenant($this->works);
        // A second site of the same road, in the same LGA: one project, two sites.
        ProjectLocation::factory()->for($this->road)->at($this->central)->coordinates('7.2600000', '5.2100000')->create();
        $this->actingAs($this->officer);

        $component = Livewire::test(TenantGisDashboard::class);
        $central = collect($component->instance()->map['by_lga'])->firstWhere('lga_id', $this->central->id);

        expect($central)->toMatchArray(['projects' => 1, 'sites' => 2]);

        $component->call('focusLga', $this->central->id)->assertSet('lga', (string) $this->central->id);
        expect(pinTitles($component->instance()->map))->toBe(['Township Road Rehabilitation']);
    });

    it('ignores a filter id that matches no row instead of trusting the URL', function () {
        actingOnTenant($this->works);
        $this->actingAs($this->officer);

        $map = Livewire::withQueryParams(['lga' => '999999', 'status' => 'not-a-status'])
            ->test(TenantGisDashboard::class)
            ->instance()->map;

        expect($map['totals']['projects'])->toBe(2);
    });

    it('embeds staff-entered titles as inert data, never as markup', function () {
        actingOnTenant($this->works);
        mappedProject($this->central, ['title' => '</script><script>alert(1)</script>'], 'ongoing');
        $this->actingAs($this->officer);

        $html = (string) $this->get(tenantUrl($this->works, '/gis'))->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>');
    });

    it('serves a filter change through the real update endpoint from a fresh process', function () {
        $this->actingAs($this->officer);

        $page = (string) $this->get(tenantUrl($this->works, '/gis'))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);
        $snapshot = collect($matches[1])
            ->map(fn (string $s): string => html_entity_decode($s, ENT_QUOTES))
            ->first(fn (string $s): bool => str_contains($s, '"name":"tenant.gis.gis-dashboard"'));

        expect($snapshot)->not->toBeNull();

        app()->forgetScopedInstances();

        $response = $this->postJson(
            tenantUrl($this->works, app(HandleRequests::class)->getUpdateUri()),
            ['components' => [[
                'snapshot' => $snapshot,
                'updates' => ['lga' => (string) $this->riverside->id],
                'calls' => [],
            ]]],
            ['X-Livewire' => '1'],
        )->assertOk();

        $dispatch = collect($response->json('components.0.effects.dispatches'))->firstWhere('name', 'project-map-updated');

        expect(array_column($dispatch['params']['pins'], 'title'))->toBe(['Ferry Point Bridge Approach']);
    });
});

describe('drill-down', function () {
    it('links every workspace tile to a register that reproduces its number', function () {
        actingOnTenant($this->works);
        // A finished-on-paper project still flagged in progress: overdue by its
        // dates, NOT overdue by the platform's rule (actual end recorded).
        mappedProject($this->riverside, ['title' => 'Recorded Finish Culvert', 'actual_end_date' => now()->subMonth()->toDateString()], 'behindSchedule');
        $this->actingAs($this->officer);

        $gis = Livewire::test(TenantGisDashboard::class)->set('lga', (string) $this->riverside->id);
        $totals = $gis->instance()->map['totals'];

        $registerCount = function (array $query): int {
            return Livewire::withQueryParams($query)
                ->test(ProjectIndex::class)
                ->instance()->projects->total();
        };

        $lga = (string) $this->riverside->id;

        expect($registerCount(['lga' => $lga, 'geotagged' => 'yes']))->toBe($totals['projects'])
            ->and($registerCount(['lga' => $lga, 'geotagged' => 'yes', 'overdue' => 1]))->toBe($totals['overdue'])
            ->and($registerCount(['lga' => $lga, 'geotagged' => 'no']))->toBe($totals['unmapped'])
            ->and($totals['overdue'])->toBe(1);
    });
});

describe('pin cap', function () {
    it('caps the markers, flags it, and never lets the cap hide an overdue site', function () {
        // Three mapped works sites; the overdue one is the OLDEST status change,
        // so recency ordering alone would drop it first.
        $map = app(CurrentTenant::class)->runAs($this->works, fn (): array => (new ProjectMapBuilder(maxPins: 1))(Project::query(), []));

        expect($map['capped'])->toBeTrue()
            ->and($map['pins'])->toHaveCount(1)
            ->and($map['pins'][0]['title'])->toBe('Ferry Point Bridge Approach')
            // The figures still count everything the cap left off the map.
            ->and($map['totals']['projects'])->toBe(2)
            ->and($map['totals']['sites'])->toBe(2);
    });

    it('does not flag a map that fits under the cap', function () {
        $map = app(CurrentTenant::class)->runAs($this->works, fn (): array => (new ProjectMapBuilder(maxPins: 2))(Project::query(), []));

        expect($map['capped'])->toBeFalse()->and($map['pins'])->toHaveCount(2);
    });
});

describe('update endpoint', function () {
    it('refuses a workspace map snapshot replayed on the oversight host', function () {
        $this->actingAs($this->officer);
        $snapshot = gisSnapshot(tenantUrl($this->works, '/gis'), 'tenant.gis.gis-dashboard');

        postGisUpdate('oversight.'.config('platform.domain'), $snapshot)->assertNotFound();
    });

    it('refuses the state map snapshot replayed on a workspace host', function () {
        $viewer = userWithRole(Role::ExecutiveViewer);
        memberOf($viewer, $this->works, Role::MeOfficer);
        $this->actingAs($viewer);
        $snapshot = gisSnapshot(oversightUrl('/gis'), 'oversight.gis.gis-dashboard');

        postGisUpdate('works.'.config('platform.domain'), $snapshot)->assertNotFound();
    });

    it('stops serving the state map the moment oversight authority is withdrawn', function () {
        $viewer = userWithRole(Role::ExecutiveViewer);
        $this->actingAs($viewer);
        $snapshot = gisSnapshot(oversightUrl('/gis'), 'oversight.gis.gis-dashboard');

        // Livewire does not re-run mount(); the Action's own check must refuse.
        $viewer->roles()->detach();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        postGisUpdate('oversight.'.config('platform.domain'), $snapshot, ['status' => 'in_progress'])->assertForbidden();
    });

    it('sends a guest holding a state map snapshot to sign in, not a 500', function () {
        $this->actingAs(userWithRole(Role::ExecutiveViewer));
        $snapshot = gisSnapshot(oversightUrl('/gis'), 'oversight.gis.gis-dashboard');
        auth()->logout();

        postGisUpdate('oversight.'.config('platform.domain'), $snapshot)->assertRedirect();
    });
});

describe('state map', function () {
    it('shows oversight every entity’s sites, with the entity named on each pin', function () {
        $this->actingAs(userWithRole(Role::ExecutiveViewer));

        $this->get(oversightUrl('/gis'))
            ->assertOk()
            ->assertSee('Township Road Rehabilitation')
            ->assertSee('Cottage Hospital Upgrade');

        $map = Livewire::test(OversightGisDashboard::class)->instance()->map;

        expect(collect($map['pins'])->firstWhere('title', 'Cottage Hospital Upgrade')['entity'])->toBe('Ministry of Health')
            ->and($map['totals'])->toMatchArray(['projects' => 3, 'unmapped' => 1]);
    });

    it('narrows the state map to one entity', function () {
        $this->actingAs(userWithRole(Role::StateAdmin));

        $component = Livewire::test(OversightGisDashboard::class)->set('tenantId', (string) $this->ministryOfHealth->id);

        expect(pinTitles($component->instance()->map))->toBe(['Cottage Hospital Upgrade']);
    });

    it('keeps tenant roles off the state map', function (Role $role) {
        $user = memberOf(User::factory()->create(), $this->works, $role);
        $this->actingAs($user);

        $this->get(oversightUrl('/gis'))->assertForbidden();
    })->with([
        'MDA admin' => [Role::MdaAdmin],
        'M&E officer' => [Role::MeOfficer],
        'consultant' => [Role::Consultant],
    ]);

    it('sends a guest to sign in on both surfaces', function () {
        $this->get(oversightUrl('/gis'))->assertRedirect();
        $this->get(tenantUrl($this->works, '/gis'))->assertRedirect();
    });
});
