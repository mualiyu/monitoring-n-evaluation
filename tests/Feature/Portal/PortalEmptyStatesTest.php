<?php

/**
 * The screens as a state sees them on day one, and as an MDA with a clean desk
 * sees them every other day.
 *
 * Empty states are the branch nobody renders in review and nobody covers in a
 * component test, which is why a broken route() call inside one ships. Every
 * empty state in this module is therefore rendered here over real HTTP — the
 * tenant ones especially, because their calls-to-action build subdomain routes
 * and Livewire::test() never resolves a subdomain at all.
 */

use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    actingWithoutTenant();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
});

it('tells a visitor honestly that nothing has been published', function () {
    $this->get(portalUrl('/'))
        ->assertOk()
        ->assertSee('Nothing has been published yet')
        // Zeros, not invented figures.
        ->assertSee('awaiting first publication');

    $this->get(portalUrl('/projects'))
        ->assertOk()
        ->assertSee('Nothing has been published yet');

    $this->get(portalUrl('/map'))
        ->assertOk()
        ->assertSee('No project sites have been published yet');
});

it('offers a way out of an over-filtered project list', function () {
    $this->get(portalUrl('/projects?q=nothing-matches-this'))
        ->assertOk()
        ->assertSee('No published project matches those filters')
        ->assertSee(route('portal.projects.index'), escape: false);
});

it('renders the MDA publishing queue empty state with a working call to action', function () {
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/publishing'))
        ->assertOk()
        ->assertSee('Nothing is eligible for publication yet')
        // The link is built with route(), so a wrong binding key fails loudly
        // here instead of 404ing silently for a user.
        ->assertSee(route('tenant.projects.index', ['tenant' => $this->works->slug]), escape: false);
});

it('renders the MDA feedback queue empty state with a working call to action', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/feedback'))
        ->assertOk()
        ->assertSee('Nobody has written in yet')
        ->assertSee(route('tenant.publishing.index', ['tenant' => $this->works->slug]), escape: false);
});

it('renders both state queue empty states with working calls to action', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/publishing'))
        ->assertOk()
        ->assertSee('No entity has a publishable project yet');

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/feedback'))
        ->assertOk()
        ->assertSee('Nobody has written in yet')
        ->assertSee(route('oversight.publishing.index'), escape: false);
});

it('renders the publishing queues once a draft exists but nothing is eligible', function () {
    actingOnTenant($this->works);
    // Draft is never a candidate, so the queue is still empty — and must still
    // say so rather than rendering a blank table body.
    Project::factory()->draft()->create(['title' => 'Draft Perimeter Fencing']);
    actingWithoutTenant();

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/publishing'))
        ->assertOk()
        ->assertSee('Nothing is eligible for publication yet')
        ->assertDontSee('Draft Perimeter Fencing');
});
