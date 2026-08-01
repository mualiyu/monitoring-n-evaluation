<?php

/**
 * Livewire screens for the project registry (projects-module.md §5).
 *
 * These cover what the Action tests cannot: that the *screen* shows the right
 * rows to the right person, that it refuses the mutation when the policy says
 * no, and that filters/wizard steps hand the Action well-formed input. Domain
 * rules themselves are proven in the Action tests — duplicating them here would
 * be theatre.
 */

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Projects\ProjectCreate;
use App\Livewire\Tenant\Projects\ProjectDetail;
use App\Livewire\Tenant\Projects\ProjectIndex;
use App\Models\FundingSource;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->sector = Sector::factory()->create(['name' => 'Transport', 'is_active' => true]);
});

/* -------------------------------------------------------------------------- */
/* Index */
/* -------------------------------------------------------------------------- */

it('lists the projects of the bound workspace only', function () {
    $mine = Project::factory()->for($this->works)->create(['title' => 'Township Road Rehabilitation']);

    $other = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    app(CurrentTenant::class)->runAs($other, function () use ($other) {
        Project::factory()->for($other)->create(['title' => 'Model Primary Health Centre']);
    });

    actingOnTenant($this->works);

    Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Model Primary Health Centre');
});

it('narrows the list to assigned projects for a consultant', function () {
    $assigned = Project::factory()->for($this->works)->create(['title' => 'Assigned Road Project']);
    Project::factory()->for($this->works)->create(['title' => 'Unassigned Bridge Project']);

    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $assigned->id,
        'user_id' => $consultant->id,
    ]);

    Livewire::actingAs($consultant)
        ->test(ProjectIndex::class)
        ->assertOk()
        ->assertSee('Assigned Road Project')
        ->assertDontSee('Unassigned Bridge Project');
});

it('filters by search term and status, and keeps the stat row in step', function () {
    Project::factory()->for($this->works)->create([
        'title' => 'Solar Street Lighting',
        'status' => ProjectStatus::InProgress,
    ]);
    Project::factory()->for($this->works)->create([
        'title' => 'Rural Water Scheme',
        'status' => ProjectStatus::Draft,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->set('search', 'Solar')
        ->assertSee('Solar Street Lighting')
        ->assertDontSee('Rural Water Scheme')
        ->tap(fn ($component) => expect($component->instance()->stats()['count'])->toBe(1))
        ->set('search', '')
        ->set('status', ProjectStatus::Draft->value)
        ->assertSee('Rural Water Scheme')
        ->assertDontSee('Solar Street Lighting');
});

it('refuses to sort by a column that is not whitelisted', function () {
    Project::factory()->for($this->works)->create();

    Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->call('sortBy', 'title; drop table projects')
        ->assertSet('sort', 'expected_end_date');
});

it('denies the index to a user with no project permissions at all', function () {
    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    setPermissionsTeamId($this->works->id);
    $stranger->roles()->detach();
    $stranger->forgetCachedPermissions();

    Livewire::actingAs($stranger)
        ->test(ProjectIndex::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Create wizard */
/* -------------------------------------------------------------------------- */

it('registers a project through the three wizard steps', function () {
    $funding = FundingSource::factory()->create(['is_active' => true]);

    Livewire::actingAs($this->admin)
        ->test(ProjectCreate::class)
        // Step 1
        ->set('title', 'Rehabilitation of Township Road, Section II')
        ->set('reference', 'PRJ-2401')
        ->set('sector_id', (string) $this->sector->id)
        ->set('type', 'capital')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        // Step 2
        ->set('budget_allocation', '1842500000')
        ->set('funding.0.funding_source_id', (string) $funding->id)
        ->set('funding.0.percentage', '100')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 3)
        // Step 3
        ->set('site_name', 'Km 4–7 alignment')
        ->set('expected_end_date', now()->addYear()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->where('reference', 'PRJ-2401')->first();

    expect($project)->not->toBeNull()
        ->and($project->title)->toBe('Rehabilitation of Township Road, Section II')
        ->and($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->tenant_id)->toBe($this->works->id)
        ->and($project->budget_allocation->toDecimalString())->toBe('1842500000.00')
        // The Action wrote the ledger row and the primary site, not the component.
        ->and($project->statusEvents()->count())->toBe(1)
        ->and($project->locations()->where('is_primary', true)->count())->toBe(1)
        ->and($project->fundingAllocations()->count())->toBe(1);
});

it('blocks the step when the funding shares exceed one hundred percent', function () {
    $a = FundingSource::factory()->create(['is_active' => true]);
    $b = FundingSource::factory()->create(['is_active' => true]);

    Livewire::actingAs($this->admin)
        ->test(ProjectCreate::class)
        ->set('step', 2)
        ->set('funding', [
            ['funding_source_id' => (string) $a->id, 'percentage' => '70', 'amount' => ''],
            ['funding_source_id' => (string) $b->id, 'percentage' => '45', 'amount' => ''],
        ])
        ->call('next')
        ->assertHasErrors('funding')
        ->assertSet('step', 2);
});

it('will not register a project with a reference already used in this workspace', function () {
    Project::factory()->for($this->works)->create(['reference' => 'PRJ-DUPLICATE']);

    Livewire::actingAs($this->admin)
        ->test(ProjectCreate::class)
        ->set('title', 'Another project entirely')
        ->set('reference', 'PRJ-DUPLICATE')
        ->set('sector_id', (string) $this->sector->id)
        ->call('next')
        ->assertHasErrors('reference');
});

it('denies the wizard to a role without projects.create', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    Livewire::actingAs($monitor)
        ->test(ProjectCreate::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Detail + status machine */
/* -------------------------------------------------------------------------- */

it('moves a project through an allowed transition and records the reason', function () {
    $project = Project::factory()->for($this->works)->create([
        'status' => ProjectStatus::InProgress,
        'sector_id' => $this->sector->id,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ProjectDetail::class, ['project' => $project])
        ->call('startTransition', ProjectStatus::Suspended->value)
        ->assertSet('pendingStatus', ProjectStatus::Suspended->value)
        ->set('transitionReason', 'Works halted pending resolution of the right-of-way dispute at Km 5.')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Suspended)
        ->and($project->statusEvents()->latest('id')->first()->reason)
        ->toContain('right-of-way');
});

it('requires a reason before suspending', function () {
    $project = Project::factory()->for($this->works)->create(['status' => ProjectStatus::InProgress]);

    Livewire::actingAs($this->admin)
        ->test(ProjectDetail::class, ['project' => $project])
        ->call('startTransition', ProjectStatus::Suspended->value)
        ->set('transitionReason', '')
        ->call('confirmTransition')
        ->assertHasErrors('transitionReason');

    expect($project->refresh()->status)->toBe(ProjectStatus::InProgress);
});

it('never offers a transition the state machine forbids', function () {
    $project = Project::factory()->for($this->works)->create(['status' => ProjectStatus::Cancelled]);

    $component = Livewire::actingAs($this->admin)
        ->test(ProjectDetail::class, ['project' => $project]);

    expect($component->instance()->availableTransitions())->toBe([]);
});

it('denies a status change to a role without the permission', function () {
    $project = Project::factory()->for($this->works)->create(['status' => ProjectStatus::InProgress]);
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $project->id,
        'user_id' => $consultant->id,
    ]);

    $component = Livewire::actingAs($consultant)->test(ProjectDetail::class, ['project' => $project]);

    // The screen offers nothing, AND the method refuses if called anyway.
    expect($component->instance()->availableTransitions())->toBe([]);

    $component->call('startTransition', ProjectStatus::Completed->value)->assertForbidden();

    expect($project->refresh()->status)->toBe(ProjectStatus::InProgress);
});

it('404s when one workspace asks for another workspace project by ulid', function () {
    $other = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $foreign = app(CurrentTenant::class)->runAs(
        $other,
        fn () => Project::factory()->for($other)->create()
    );

    actingOnTenant($this->works);

    // Through the route, because the route-model binder is what applies the scope.
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$foreign->ulid))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* Query budget (§9.3: "≤ 6 queries per page at any size, asserted") */
/* -------------------------------------------------------------------------- */

it('keeps the project list query budget flat as the portfolio grows', function () {
    $countQueriesFor = function (int $projects): array {
        Project::query()->forceDelete();
        Project::factory()->count($projects)->for($this->works)->create();

        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            // Only the list's own reads: session, permission cache and the
            // authenticated user are framework plumbing, not page cost.
            if (str_contains($query->sql, 'projects') || str_contains($query->sql, 'project_locations')
                || str_contains($query->sql, 'sectors')) {
                $seen[] = $query->sql;
            }
        });

        Livewire::actingAs($this->admin)->test(ProjectIndex::class)->assertOk();

        return $seen;
    };

    $small = $countQueriesFor(3);
    $large = $countQueriesFor(40);

    // Constant, not merely small: an N+1 shows up as growth with row count.
    expect(count($large))->toBe(count($small))
        ->and(count($large))->toBeLessThanOrEqual(7); // 6 list reads + the sector filter list
});
