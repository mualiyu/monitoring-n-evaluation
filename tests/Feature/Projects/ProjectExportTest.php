<?php

/**
 * The CSV export on the project register (projects-module.md §5).
 *
 * Every other read on that screen is proven by what the Livewire response
 * renders. The export is not: it is a network-callable method that streams
 * rows straight past the view layer, so a scoping mistake there produces a
 * file nobody looks at on screen and everybody opens in Excel. The rows it
 * writes therefore get their own isolation proof — tenant boundary, role
 * narrowing, and the authorization gate on the method itself.
 *
 * The assertions read the streamed bytes rather than a response body, because
 * that is the artifact the user actually receives.
 */

use App\Actions\Projects\UnassignProjectMember;
use App\Enums\Role;
use App\Livewire\Tenant\Projects\ProjectIndex;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
});

/**
 * Run the export and return the bytes it streams. The callback writes to
 * php://output, so an output buffer is what captures the real file.
 */
function exportedCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/* -------------------------------------------------------------------------- */
/* Tenant boundary */
/* -------------------------------------------------------------------------- */

it('writes only the bound workspace’s projects into the CSV', function () {
    Project::factory()->for($this->works)->create([
        'reference' => 'WRK-0001',
        'title' => 'Township Road Rehabilitation',
    ]);

    app(CurrentTenant::class)->runAs($this->health, fn () => Project::factory()->for($this->health)->create([
        'reference' => 'HLT-0001',
        'title' => 'Model Primary Health Centre',
    ]));

    actingOnTenant($this->works);

    $csv = exportedCsv(
        Livewire::actingAs($this->admin)->test(ProjectIndex::class)->instance()->export()
    );

    expect($csv)->toContain('WRK-0001')
        ->and($csv)->toContain('Township Road Rehabilitation')
        // The row that must never be in this file, by reference AND by title —
        // a leak that only drops the title is still a leak.
        ->and($csv)->not->toContain('HLT-0001')
        ->and($csv)->not->toContain('Model Primary Health Centre');
});

it('exports nothing at all from a workspace with no projects of its own', function () {
    app(CurrentTenant::class)->runAs($this->health, fn () => Project::factory()->count(3)->for($this->health)->create());

    actingOnTenant($this->works);

    $csv = exportedCsv(
        Livewire::actingAs($this->admin)->test(ProjectIndex::class)->instance()->export()
    );

    // Header row + the UTF-8 BOM, and not one data line: an empty portfolio
    // must produce an empty file, never "everyone else's".
    expect(array_values(array_filter(explode("\n", trim($csv)))))->toHaveCount(1)
        ->and($csv)->toContain('Reference');
});

/* -------------------------------------------------------------------------- */
/* Role narrowing — the export runs the same visibleTo() query as the list */
/* -------------------------------------------------------------------------- */

it('narrows the CSV to a consultant’s own assignments', function () {
    $assigned = Project::factory()->for($this->works)->create([
        'reference' => 'ASG-0001',
        'title' => 'Assigned Road Project',
    ]);

    Project::factory()->for($this->works)->create([
        'reference' => 'UNA-0001',
        'title' => 'Unassigned Bridge Project',
    ]);

    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $assigned->id,
        'user_id' => $consultant->id,
    ]);

    $csv = exportedCsv(
        Livewire::actingAs($consultant)->test(ProjectIndex::class)->instance()->export()
    );

    // Same workspace, same screen, same filter set — only the assignment
    // narrowing differs, which is exactly the rule under test.
    expect($csv)->toContain('ASG-0001')
        ->and($csv)->not->toContain('UNA-0001');
});

it('drops a project from the CSV the moment the consultant is unassigned', function () {
    $project = Project::factory()->for($this->works)->create(['reference' => 'REV-0001']);
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $assignment = ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $project->id,
        'user_id' => $consultant->id,
    ]);

    (new UnassignProjectMember)($assignment, $this->admin);

    $csv = exportedCsv(
        Livewire::actingAs($consultant)->test(ProjectIndex::class)->instance()->export()
    );

    expect($csv)->not->toContain('REV-0001');
});

it('exports exactly the rows the active filter leaves on screen', function () {
    Project::factory()->for($this->works)->create(['reference' => 'SOL-0001', 'title' => 'Solar Street Lighting']);
    Project::factory()->for($this->works)->create(['reference' => 'WAT-0001', 'title' => 'Rural Water Scheme']);

    $component = Livewire::actingAs($this->admin)
        ->test(ProjectIndex::class)
        ->set('search', 'Solar')
        ->assertSee('Solar Street Lighting')
        ->assertDontSee('Rural Water Scheme');

    // "The figures on screen, nothing else" — a filtered list whose export
    // quietly widens to the whole portfolio is a disclosure, not a convenience.
    $csv = exportedCsv($component->instance()->export());

    expect($csv)->toContain('SOL-0001')
        ->and($csv)->not->toContain('WAT-0001');
});

/* -------------------------------------------------------------------------- */
/* The gate on the method itself */
/* -------------------------------------------------------------------------- */

it('refuses the export to a member holding no project permissions', function () {
    Project::factory()->for($this->works)->create(['reference' => 'SEC-0001']);

    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    setPermissionsTeamId($this->works->id);
    $stranger->roles()->detach();
    $stranger->forgetCachedPermissions();

    Livewire::actingAs($stranger)
        ->test(ProjectIndex::class)
        ->assertForbidden();
});

it('re-authorizes inside export(), not only at mount', function () {
    Project::factory()->for($this->works)->create(['reference' => 'SEC-0002']);

    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $component = Livewire::actingAs($officer)->test(ProjectIndex::class)->assertOk();

    // export() is network-callable: a client can invoke it directly, long after
    // mount() passed and after the permission behind it was taken away. The
    // guard has to be on the method, which is what this proves.
    setPermissionsTeamId($this->works->id);
    $officer->roles()->detach();
    $officer->forgetCachedPermissions();
    // The authenticated instance is the one the component will ask, and it is
    // still holding the roles it loaded at mount — spatie caches them on the
    // model, so revoking in the database alone would prove nothing here.
    $officer->unsetRelation('roles')->unsetRelation('permissions');

    $component->call('export')->assertForbidden();
});
