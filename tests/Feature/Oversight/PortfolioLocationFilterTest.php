<?php

/**
 * The state portfolio's location filters: `lga` and `geotagged`.
 *
 * The state GIS dashboard drills into this list, so both filters follow the
 * map's rules exactly — a multi-site project is under every LGA it touches,
 * a project is geotagged when a site carries BOTH coordinates, and with an
 * LGA set only that LGA's sites are tested.
 *
 * The portfolio is the one cross-MDA list, so each case asserts both halves:
 * oversight sees every entity's matching projects, and the LGA filter is a
 * resolved model (whereBelongsTo), never a raw id pasted into SQL.
 */

use App\Actions\Oversight\ListProjectsAcrossTenants;
use App\Enums\Role;
use App\Livewire\Oversight\Projects\Portfolio;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

/**
 * @param  list<array{0: Lga, 1: bool}>  $sites  [lga, has both coordinates]
 */
function portfolioProjectWithSites(Tenant $tenant, string $title, array $sites): Project
{
    return app(CurrentTenant::class)->runAs($tenant, function () use ($title, $sites): Project {
        $project = Project::factory()->ongoing()->create(['title' => $title]);

        foreach ($sites as $index => [$lga, $fixed]) {
            $site = ProjectLocation::factory()->for($project)->at($lga)->state(['is_primary' => $index === 0]);

            ($fixed ? $site->coordinates('9.0765000', '7.3986000') : $site->withoutCoordinates())->create();
        }

        return $project;
    });
}

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->x = Lga::factory()->create(['name' => 'Northgate']);
    $this->y = Lga::factory()->create(['name' => 'Southbank']);
    $this->retired = Lga::factory()->inactive()->create(['name' => 'Retiredvale']);

    portfolioProjectWithSites($this->works, 'Northgate Ring Road', [[$this->x, true]]);
    portfolioProjectWithSites($this->works, 'Northgate Unfixed Drain', [[$this->x, false]]);
    // Site A in X without a fix, site B in Y with one.
    portfolioProjectWithSites($this->works, 'Split Span Bridge', [[$this->x, false], [$this->y, true]]);
    portfolioProjectWithSites($this->works, 'Siteless Borehole Scheme', []);

    portfolioProjectWithSites($this->health, 'Northgate Health Centre', [[$this->x, true]]);
    portfolioProjectWithSites($this->health, 'Southbank Maternity Wing', [[$this->y, false]]);
    portfolioProjectWithSites($this->health, 'Retiredvale Outpost', [[$this->retired, true]]);

    actingWithoutTenant();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    // Mandated 2FA enrolment, inside its grace window — these tests are about
    // filtering, not about the enrolment clock.
    $this->stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    actingWithoutTenant();
});

/** Titles the Action returns for a filter set, sorted for stable comparison. */
function portfolioTitles(array $filters): array
{
    return collect((new ListProjectsAcrossTenants)(test()->stateAdmin, $filters)->items())
        ->pluck('title')->sort()->values()->all();
}

/** Titles the component's current query returns. */
function portfolioScreenTitles(mixed $component): array
{
    return collect($component->instance()->projects()->items())->pluck('title')->sort()->values()->all();
}

/* -------------------------------------------------------------------------- */
/* The Action */
/* -------------------------------------------------------------------------- */

it('filters by LGA across every entity, counting a multi-site project in each LGA it touches', function () {
    expect(portfolioTitles(['lga' => $this->x]))
        ->toBe(['Northgate Health Centre', 'Northgate Ring Road', 'Northgate Unfixed Drain', 'Split Span Bridge'])
        ->and(portfolioTitles(['lga' => $this->y]))
        ->toBe(['Southbank Maternity Wing', 'Split Span Bridge']);
});

it('filters to geotagged and not-geotagged projects across every entity', function () {
    expect(portfolioTitles(['geotagged' => true]))
        ->toBe(['Northgate Health Centre', 'Northgate Ring Road', 'Retiredvale Outpost', 'Split Span Bridge'])
        ->and(portfolioTitles(['geotagged' => false]))
        ->toBe(['Northgate Unfixed Drain', 'Siteless Borehole Scheme', 'Southbank Maternity Wing'])
        ->and(portfolioTitles(['geotagged' => null]))->toHaveCount(7);
});

it('applies the geotag test to the filtered LGA’s sites only, as the map does', function () {
    expect(portfolioTitles(['lga' => $this->y, 'geotagged' => true]))->toBe(['Split Span Bridge'])
        ->and(portfolioTitles(['lga' => $this->x, 'geotagged' => true]))
        ->toBe(['Northgate Health Centre', 'Northgate Ring Road'])
        ->and(portfolioTitles(['lga' => $this->x, 'geotagged' => false]))
        ->toBe(['Northgate Unfixed Drain', 'Split Span Bridge'])
        ->and(portfolioTitles(['lga' => $this->y, 'geotagged' => false]))
        ->toBe(['Southbank Maternity Wing']);
});

it('combines the location filters with the entity filter without a hand-written tenant clause', function () {
    expect(portfolioTitles(['tenant' => $this->health, 'lga' => $this->x]))->toBe(['Northgate Health Centre'])
        ->and(portfolioTitles(['tenant' => $this->works, 'lga' => $this->x, 'geotagged' => false]))
        ->toBe(['Northgate Unfixed Drain', 'Split Span Bridge']);
});

/* -------------------------------------------------------------------------- */
/* The screen and the URL contract */
/* -------------------------------------------------------------------------- */

it('reads lga and geotagged from the URL on the real oversight route', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/portfolio?lga='.$this->x->id.'&geotagged=yes'))
        ->assertOk()
        ->assertSee('Northgate Ring Road')
        ->assertSee('Northgate Health Centre')
        ->assertDontSee('Split Span Bridge')
        ->assertDontSee('Northgate Unfixed Drain')
        ->assertDontSee('Southbank Maternity Wing');

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/portfolio?lga='.$this->x->id.'&geotagged=no'))
        ->assertOk()
        ->assertSee('Split Span Bridge')
        ->assertSee('Northgate Unfixed Drain')
        ->assertDontSee('Northgate Ring Road')
        ->assertDontSee('Siteless Borehole Scheme');
});

it('lists every entity’s projects in one LGA through the screen', function () {
    $component = Livewire::withQueryParams(['lga' => (string) $this->y->id])
        ->actingAs($this->stateAdmin)
        ->test(Portfolio::class);

    expect(portfolioScreenTitles($component))->toBe(['Southbank Maternity Wing', 'Split Span Bridge']);
});

it('ignores an LGA id that matches no active LGA, and a geotagged value outside the contract', function () {
    foreach (['999999', 'abc', (string) $this->retired->id] as $lga) {
        $component = Livewire::withQueryParams(['lga' => $lga, 'geotagged' => 'maybe'])
            ->actingAs($this->stateAdmin)
            ->test(Portfolio::class);

        // Ignored, not "matches nothing": the list is the unfiltered portfolio.
        expect(portfolioScreenTitles($component))->toHaveCount(7, "lga={$lga}");
    }
});

it('offers active LGAs and the geotag choice in the filter bar', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(Portfolio::class)
        ->assertSeeHtml('wire:model.live="lga"')
        ->assertSeeHtml('wire:model.live="geotagged"')
        ->assertSee('Any location')
        ->assertSee('Not geotagged');

    // Only the LGA select's own options — ids alone would also match the
    // entity and sector selects on the same bar.
    $html = $component->html();
    $lgaSelect = substr($html, strpos($html, 'id="lga"'), strpos($html, '</select>', strpos($html, 'id="lga"')) - strpos($html, 'id="lga"'));

    expect($lgaSelect)->toContain('<option value="'.$this->x->id.'"')
        ->and($lgaSelect)->toContain('Southbank')
        // A retired LGA is not offered — and, as above, not honoured from the URL.
        ->and($lgaSelect)->not->toContain('Retiredvale');
});

it('clears the location filters with the rest and returns to page one when they change', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(Portfolio::class)
        ->set('lga', (string) $this->x->id)
        ->set('geotagged', 'no')
        ->assertSee('Clear filters')
        ->tap(fn ($component) => expect($component->instance()->hasFilters())->toBeTrue())
        ->call('clearFilters')
        ->assertSet('lga', '')
        ->assertSet('geotagged', '')
        ->tap(fn ($component) => expect(portfolioScreenTitles($component))->toHaveCount(7))
        ->call('setPage', 3)
        ->set('lga', (string) $this->y->id)
        ->tap(fn ($component) => expect($component->instance()->getPage())->toBe(1))
        ->call('setPage', 3)
        ->set('geotagged', 'yes')
        ->tap(fn ($component) => expect($component->instance()->getPage())->toBe(1));
});

it('shows the filtered empty state when the location filters match nothing', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(Portfolio::class)
        ->set('lga', (string) $this->y->id)
        ->set('geotagged', 'no')
        ->set('tenantId', (string) $this->works->id)
        ->assertSee('No projects match these filters across any entity.');
});
