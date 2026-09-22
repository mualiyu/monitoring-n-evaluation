<?php

/**
 * The register's location filters — `geotagged` and its interaction with `lga`.
 *
 * The GIS dashboard's tiles ("Projects on the map", "Not geotagged", …) drill
 * into this list, and a drill-down that lands on a different number than the
 * tile it came from teaches the user to distrust both. So the rule here is the
 * map's rule, exactly:
 *
 *   geotagged=yes  at least one site with BOTH latitude and longitude
 *   geotagged=no   no such site (a project with no sites at all included)
 *   + lga=X        the test applies to the sites IN X only
 *
 * The table, the stat row and the CSV share one query, so each is proven here
 * rather than assumed.
 */

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Projects\ProjectIndex;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A project with the given sites, created inside $tenant's context so the
 * tenant_id is filled by BelongsToTenant, never by hand.
 *
 * @param  list<array{0: Lga, 1: 'both'|'none'|'latitude-only'}>  $sites
 * @param  array<string, mixed>  $attributes
 */
function registerProjectWithSites(Tenant $tenant, string $reference, string $title, array $sites, array $attributes = []): Project
{
    return app(CurrentTenant::class)->runAs($tenant, function () use ($reference, $title, $sites, $attributes): Project {
        $project = Project::factory()->create(['reference' => $reference, 'title' => $title, ...$attributes]);

        foreach ($sites as $index => [$lga, $fix]) {
            $site = ProjectLocation::factory()->for($project)->at($lga)->state(['is_primary' => $index === 0]);

            $site = match ($fix) {
                'both' => $site->coordinates('9.0765000', '7.3986000'),
                'none' => $site->withoutCoordinates(),
                'latitude-only' => $site->state(['latitude' => '9.0765000', 'longitude' => null]),
            };

            $site->create();
        }

        return $project;
    });
}

function locationFilterCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->x = Lga::factory()->create(['name' => 'Northgate']);
    $this->y = Lga::factory()->create(['name' => 'Southbank']);

    // Works: one of every shape the filter has to tell apart.
    registerProjectWithSites($this->works, 'WRK-GEO', 'Geotagged Ring Road', [[$this->x, 'both']], [
        'status' => ProjectStatus::InProgress,
        'contract_value_total' => '300000000.00',
        'expected_end_date' => now()->subMonth()->toDateString(),   // overdue
    ]);
    registerProjectWithSites($this->works, 'WRK-NOFIX', 'Unfixed Clinic Annex', [[$this->x, 'none']], [
        'contract_value_total' => '50000000.00',
    ]);
    registerProjectWithSites($this->works, 'WRK-HALF', 'Half Fixed Culvert', [[$this->x, 'latitude-only']], [
        'contract_value_total' => '20000000.00',
    ]);
    registerProjectWithSites($this->works, 'WRK-NOSITE', 'Siteless Borehole Scheme', [], [
        'contract_value_total' => '10000000.00',
    ]);
    // Site A in X without a fix, site B in Y with one — the case that decides
    // whether the geotag test is scoped to the LGA filter.
    registerProjectWithSites($this->works, 'WRK-SPLIT', 'Split Span Bridge', [
        [$this->x, 'none'],
        [$this->y, 'both'],
    ], [
        'contract_value_total' => '400000000.00',
    ]);

    // Health: the rows no Works filter combination may ever surface.
    registerProjectWithSites($this->health, 'HLT-GEO', 'Foreign Geotagged Centre', [[$this->x, 'both']]);
    registerProjectWithSites($this->health, 'HLT-NOFIX', 'Foreign Unfixed Post', [[$this->x, 'none']]);
    registerProjectWithSites($this->health, 'HLT-SPLIT', 'Foreign Split Clinic', [[$this->x, 'none'], [$this->y, 'both']]);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
});

/** Titles the component's current query returns, sorted for stable comparison. */
function registerTitles(mixed $component): array
{
    return collect($component->instance()->projects()->items())->pluck('title')->sort()->values()->all();
}

/* -------------------------------------------------------------------------- */
/* The URL contract, through the real route */
/* -------------------------------------------------------------------------- */

it('reads geotagged=yes from the URL and lists only projects with a site fixed on both axes', function () {
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects?geotagged=yes'))
        ->assertOk()
        ->assertSee('Geotagged Ring Road')
        ->assertSee('Split Span Bridge')
        ->assertDontSee('Unfixed Clinic Annex')
        // One coordinate is not a fix — the map cannot draw it either.
        ->assertDontSee('Half Fixed Culvert')
        ->assertDontSee('Siteless Borehole Scheme')
        ->assertDontSee('Foreign Geotagged Centre');
});

it('reads geotagged=no from the URL and lists the projects the map cannot draw', function () {
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects?geotagged=no'))
        ->assertOk()
        ->assertSee('Unfixed Clinic Annex')
        ->assertSee('Half Fixed Culvert')
        // No sites at all is the purest case of "not geotagged".
        ->assertSee('Siteless Borehole Scheme')
        ->assertDontSee('Geotagged Ring Road')
        ->assertDontSee('Split Span Bridge')
        ->assertDontSee('Foreign Unfixed Post');
});

it('applies the geotag test to the filtered LGA’s sites only, exactly as the map does', function () {
    // Site B (Southbank) is fixed: the bridge is on the Southbank map.
    expect(registerTitles(Livewire::withQueryParams(['lga' => (string) $this->y->id, 'geotagged' => 'yes'])
        ->actingAs($this->admin)->test(ProjectIndex::class)))
        ->toBe(['Split Span Bridge']);

    // Its Northgate site has no fix, so it is NOT on the Northgate map…
    expect(registerTitles(Livewire::withQueryParams(['lga' => (string) $this->x->id, 'geotagged' => 'yes'])
        ->actingAs($this->admin)->test(ProjectIndex::class)))
        ->toBe(['Geotagged Ring Road']);

    // …and therefore IS in Northgate's "not geotagged" count — while a project
    // with no Northgate site at all is in neither.
    expect(registerTitles(Livewire::withQueryParams(['lga' => (string) $this->x->id, 'geotagged' => 'no'])
        ->actingAs($this->admin)->test(ProjectIndex::class)))
        ->toBe(['Half Fixed Culvert', 'Split Span Bridge', 'Unfixed Clinic Annex']);

    expect(registerTitles(Livewire::withQueryParams(['lga' => (string) $this->y->id, 'geotagged' => 'no'])
        ->actingAs($this->admin)->test(ProjectIndex::class)))
        ->toBe([]);
});

it('ignores a geotagged value outside the contract rather than guessing', function () {
    $component = Livewire::withQueryParams(['geotagged' => 'maybe'])
        ->actingAs($this->admin)
        ->test(ProjectIndex::class);

    expect(registerTitles($component))->toHaveCount(5)
        // A value that constrains nothing is not a filter, so the screen does
        // not claim "no projects match your filters".
        ->and($component->instance()->hasFilters())->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The screen: filter bar, stat row, export */
/* -------------------------------------------------------------------------- */

it('offers the geotag filter in the filter bar, not only in the URL', function () {
    Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->assertSeeHtml('wire:model.live="geotagged"')
        ->assertSee('Any location')
        ->assertSee('Geotagged')
        ->assertSee('Not geotagged')
        ->set('geotagged', 'yes')
        ->assertSee('Geotagged Ring Road')
        ->assertDontSee('Unfixed Clinic Annex')
        ->assertSee('Clear filters');
});

it('keeps the stat row on the same rows as the geotag filter', function () {
    // The SUM comes back in the engine's own decimal spelling; normalise it
    // the way the stat card does before comparing.
    $value = fn (array $stats): string => Money::fromDecimalString($stats['contract_value'])->toDecimalString();

    $mapped = Livewire::actingAs($this->admin)->test(ProjectIndex::class)->set('geotagged', 'yes')->instance()->stats();

    expect($mapped['count'])->toBe(2)
        ->and($value($mapped))->toBe('700000000.00')
        ->and($mapped['in_progress'])->toBe(1)
        ->and($mapped['overdue'])->toBe(1);

    $unmapped = Livewire::actingAs($this->admin)->test(ProjectIndex::class)->set('geotagged', 'no')->instance()->stats();

    expect($unmapped['count'])->toBe(3)
        ->and($value($unmapped))->toBe('80000000.00')
        ->and($unmapped['overdue'])->toBe(0);

    $southbank = Livewire::actingAs($this->admin)->test(ProjectIndex::class)
        ->set('lga', (string) $this->y->id)
        ->set('geotagged', 'yes')
        ->instance()
        ->stats();

    expect($southbank['count'])->toBe(1)
        ->and($value($southbank))->toBe('400000000.00');
});

it('exports exactly the rows the geotag filter leaves on screen', function () {
    $csv = locationFilterCsv(
        Livewire::withQueryParams(['geotagged' => 'no'])
            ->actingAs($this->admin)
            ->test(ProjectIndex::class)
            ->instance()
            ->export()
    );

    expect($csv)->toContain('WRK-NOFIX')
        ->and($csv)->toContain('WRK-HALF')
        ->and($csv)->toContain('WRK-NOSITE')
        ->and($csv)->not->toContain('WRK-GEO')
        ->and($csv)->not->toContain('WRK-SPLIT')
        ->and($csv)->not->toContain('HLT-');

    $northgate = locationFilterCsv(
        Livewire::withQueryParams(['lga' => (string) $this->x->id, 'geotagged' => 'yes'])
            ->actingAs($this->admin)
            ->test(ProjectIndex::class)
            ->instance()
            ->export()
    );

    expect($northgate)->toContain('WRK-GEO')
        ->and($northgate)->not->toContain('WRK-SPLIT')
        ->and($northgate)->not->toContain('HLT-');
});

it('clears the geotag filter with the rest, and returns to page one when it changes', function () {
    Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->set('geotagged', 'no')
        ->set('lga', (string) $this->x->id)
        ->call('clearFilters')
        ->assertSet('geotagged', '')
        ->assertSet('lga', '')
        ->call('setPage', 3)
        ->set('geotagged', 'yes')
        ->tap(fn ($component) => expect($component->instance()->getPage())->toBe(1));
});

it('names the geotag filter in the empty state and lets the user clear it', function () {
    Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->set('lga', (string) $this->y->id)
        ->set('geotagged', 'no')
        ->assertSee('No projects match the filters you have set.')
        ->call('clearFilters')
        ->assertSee('Geotagged Ring Road');
});

/* -------------------------------------------------------------------------- */
/* Isolation under every combination */
/* -------------------------------------------------------------------------- */

it('never surfaces another workspace’s project under any location filter combination', function () {
    foreach (['', (string) $this->x->id, (string) $this->y->id] as $lga) {
        foreach (['', 'yes', 'no', 'junk'] as $geotagged) {
            foreach ([false, true] as $overdue) {
                $component = Livewire::withQueryParams(array_filter([
                    'lga' => $lga,
                    'geotagged' => $geotagged,
                    'overdue' => $overdue ? '1' : '',
                ]))->actingAs($this->admin)->test(ProjectIndex::class);

                $label = "lga={$lga} geotagged={$geotagged} overdue=".($overdue ? '1' : '0');

                expect(collect($component->instance()->projects()->items())->pluck('tenant_id')->unique()->all())
                    ->each->toBe($this->works->id, $label);

                $component->assertDontSee('Foreign Geotagged Centre')
                    ->assertDontSee('Foreign Unfixed Post')
                    ->assertDontSee('Foreign Split Clinic');

                expect(locationFilterCsv($component->instance()->export()))->not->toContain('HLT-', $label);
            }
        }
    }
});
