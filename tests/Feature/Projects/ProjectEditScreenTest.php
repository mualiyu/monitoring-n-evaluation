<?php

/**
 * The project edit screen (design §5, `/projects/{project}/edit`).
 *
 * The route did not exist while the detail header and the index row menu both
 * linked to it, so every "Edit details" click was a 404. These cases pin the
 * route, the authorization matrix, the certification freeze as the user meets
 * it, and tenant isolation on a record-level lookup.
 */

use App\Enums\MeasurementFrequency;
use App\Enums\Role;
use App\Livewire\Tenant\Projects\ProjectEdit;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->sector = Sector::factory()->create(['name' => 'Transport', 'is_active' => true]);
    $this->otherSector = Sector::factory()->create(['name' => 'Water Resources', 'is_active' => true]);

    $this->project = Project::factory()->for($this->works)->ongoing()->create([
        'reference' => 'PRJ-EDIT-01',
        'title' => 'Township Road Rehabilitation',
        'sector_id' => $this->sector->id,
    ]);
});

/* -------------------------------------------------------------------------- */
/* The route exists and renders */
/* -------------------------------------------------------------------------- */

it('serves the edit screen to an authorized member', function () {
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/edit'))
        ->assertOk()
        ->assertSee('Edit project details')
        ->assertSee($this->project->title);
});

it('populates the form from the stored record', function () {
    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->assertSet('reference', 'PRJ-EDIT-01')
        ->assertSet('title', 'Township Road Rehabilitation')
        ->assertSet('sector_id', (string) $this->sector->id);
});

/* -------------------------------------------------------------------------- */
/* Saving */
/* -------------------------------------------------------------------------- */

it('saves an edit and returns to the project', function () {
    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->set('title', 'Township Road Rehabilitation, Phase II')
        ->set('sector_id', (string) $this->otherSector->id)
        ->set('budget_code', 'CAP-2026-114')
        ->set('reporting_frequency', MeasurementFrequency::Monthly->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(url('/projects/'.$this->project->ulid));

    $fresh = $this->project->fresh();

    expect($fresh->title)->toBe('Township Road Rehabilitation, Phase II')
        ->and($fresh->sector_id)->toBe($this->otherSector->id)
        ->and($fresh->budget_code)->toBe('CAP-2026-114')
        ->and($fresh->reporting_frequency)->toBe(MeasurementFrequency::Monthly->value);
});

it('saves an untouched form without complaining that the reference is taken', function () {
    // The uniqueness rule has to exclude the record being edited, or every
    // no-op save collides with itself.
    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->call('save')
        ->assertHasNoErrors();
});

it('rejects a reference already used by another project in the workspace', function () {
    Project::factory()->for($this->works)->create(['reference' => 'PRJ-TAKEN']);

    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->set('reference', 'PRJ-TAKEN')
        ->call('save')
        ->assertHasErrors('reference');

    expect($this->project->fresh()->reference)->toBe('PRJ-EDIT-01');
});

it('requires a title and a sector', function () {
    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->set('title', '')
        ->set('sector_id', '')
        ->call('save')
        ->assertHasErrors(['title', 'sector_id']);
});

it('refuses a completion date that precedes the start date', function () {
    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->set('start_date', '2026-06-01')
        ->set('expected_end_date', '2026-05-01')
        ->call('save')
        ->assertHasErrors('expected_end_date');
});

it('refuses a manager who is not an active member of this workspace', function () {
    $outsider = User::factory()->create();

    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $this->project])
        ->set('manager_id', (string) $outsider->id)
        ->call('save')
        ->assertHasErrors('manager_id');
});

/* -------------------------------------------------------------------------- */
/* The certification freeze, as the screen presents it */
/* -------------------------------------------------------------------------- */

it('locks the certificate-attested fields once the project is certified', function () {
    $certified = Project::factory()->for($this->works)->certified()->create(['reference' => 'PRJ-CERT-01']);

    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $certified])
        ->assertSet('failure', null)
        ->assertSee('This project’s figures are locked', false);
});

it('cannot write a frozen figure through the screen', function () {
    // The Action's own refusal is proven in ProjectRegistryActionsTest. What
    // matters here is that the screen never carries a locked field into a
    // write at all, so a tampered payload changes nothing.
    $certified = Project::factory()->for($this->works)->certified()->create([
        'reference' => 'PRJ-CERT-02',
        'title' => 'Certified Bridge Works',
    ]);

    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $certified])
        ->set('title', 'Quietly Rewritten Title')
        ->set('budget_code', 'TAMPERED')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $certified->fresh();

    expect($fresh->title)->toBe('Certified Bridge Works')
        ->and($fresh->budget_code)->not->toBe('TAMPERED');
});

it('still allows the accountable officer to change on a certified project', function () {
    $certified = Project::factory()->for($this->works)->certified()->create(['reference' => 'PRJ-CERT-03']);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    Livewire::actingAs($this->admin)->test(ProjectEdit::class, ['project' => $certified])
        ->set('manager_id', (string) $officer->id)
        ->call('save')
        ->assertHasNoErrors()
        // An Action refusal lands in $failure, not in the error bag, so a
        // no-errors assertion alone would pass on a rejected save.
        ->assertSet('failure', null);

    expect($certified->fresh()->manager_id)->toBe($officer->id);
});

/* -------------------------------------------------------------------------- */
/* Authorization matrix */
/* -------------------------------------------------------------------------- */

it('denies the edit screen to a role without projects.update', function (Role $role) {
    $user = memberOf(User::factory()->create(), $this->works, $role);

    $this->actingAs($user)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/edit'))
        ->assertForbidden();
})->with([Role::Consultant, Role::FieldMonitor]);

it('allows an M&E officer to edit', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($officer)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/edit'))
        ->assertOk();
});

it('redirects an unauthenticated visitor to the workspace login', function () {
    $this->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/edit'))
        ->assertRedirect(tenantUrl($this->works, '/login'));
});

/* -------------------------------------------------------------------------- */
/* Tenant isolation — record-level denial stays a silent 404 */
/* -------------------------------------------------------------------------- */

it('404s another workspace’s project instead of confirming it exists', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $theirs = app(CurrentTenant::class)->runAs(
        $health,
        fn (): Project => Project::factory()->ongoing()->create(['reference' => 'PRJ-HEALTH-01']),
    );

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$theirs->ulid.'/edit'))
        ->assertNotFound();
});

it('does not let one workspace edit another’s project through the same ulid', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $theirAdmin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

    // A member of Health, on Health's host, cannot reach a Works record.
    $this->actingAs($theirAdmin)
        ->get(tenantUrl($health, '/projects/'.$this->project->ulid.'/edit'))
        ->assertNotFound();

    expect($this->project->fresh()->title)->toBe('Township Road Rehabilitation');
});

/* -------------------------------------------------------------------------- */
/* The links that started this */
/* -------------------------------------------------------------------------- */

it('offers a working edit link from the project detail header', function () {
    $html = (string) $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid))
        ->assertOk()
        ->getContent();

    $editUrl = url('/projects/'.$this->project->ulid.'/edit');

    expect($html)->toContain($editUrl);

    // And the link it advertises actually resolves.
    $this->actingAs($this->admin)->get($editUrl)->assertOk();
});

it('hides the edit link from a role that cannot edit', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $consultant->id,
    ]);

    $html = (string) $this->actingAs($consultant)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(url('/projects/'.$this->project->ulid.'/edit'));
});
