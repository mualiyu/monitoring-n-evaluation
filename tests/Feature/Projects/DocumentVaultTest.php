<?php

/**
 * The document vault AS THE PROJECT SCREENS MOUNT IT.
 *
 * The vault's own guarantees — private disk, generated filenames, mime and
 * size rules, per-collection role rules, signed downloads, cross-tenant
 * refusal — are proven in tests/Feature/Documents/VaultSecurityTest.php and
 * are deliberately not repeated here. What these cases cover is the wiring:
 * that the project record's documents tab actually mounts both panels over
 * real HTTP, that a contract carries its own vault, and that the state surface
 * gets the files read-only.
 *
 * All of it over HTTP rather than through Livewire::test(), because a nested
 * component that fails to mount is exactly the class of defect a
 * property-setting test cannot see.
 */

use App\Actions\Documents\AttachDocument;
use App\Actions\Projects\AwardContract;
use App\Enums\Role;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A PDF with real bytes.
 *
 * `UploadedFile::fake()->create()` writes a ZERO-BYTE file, which sniffs as
 * `application/x-empty` and is correctly refused by the `mimetypes:` rule and
 * by medialibrary's own accepted-types check. Named for this file:
 * VaultSecurityTest already owns `fakePdf` at global scope and Pest loads
 * every test file into one process.
 */
function vaultPdf(string $name = 'award-letter.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

/** Put a file into a record's vault through the one Action allowed to do it. */
function attachVaultFile(
    Model&HasMedia $model,
    string $collection,
    User $actor,
    string $title,
    ?UploadedFile $file = null,
): Media {
    return app(AttachDocument::class)($model, $collection, $file ?? vaultPdf(), $actor, $title);
}

beforeEach(function () {
    Storage::fake('documents');
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->project = Project::factory()->for($this->works)->ongoing()->create([
        'reference' => 'PRJ-DOC-01',
        'title' => 'Township Road Rehabilitation',
    ]);
});

/* -------------------------------------------------------------------------- */
/* The project record's documents tab */
/* -------------------------------------------------------------------------- */

it('mounts both project vault panels on the documents tab', function () {
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'?tab=documents'))
        ->assertOk()
        ->assertSee('Project documents')
        ->assertSee('Project photographs')
        // The tab is real now, not the placeholder it shipped as.
        ->assertDontSee('Document upload is not available yet');
});

it('lists a file the project already holds, and offers the upload form to an uploader', function () {
    attachVaultFile($this->project, 'project_documents', $this->admin, 'Approval memo');

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'?tab=documents'))
        ->assertOk()
        ->assertSee('Approval memo')
        ->assertSee('Add a file');
});

it('keeps one workspace’s project files off another workspace’s screen', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    app(CurrentTenant::class)->runAs($health, function () use ($health) {
        $project = Project::factory()->for($health)->ongoing()->create();
        $actor = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

        attachVaultFile($project, 'project_documents', $actor, 'Health ministry memo', vaultPdf('their-memo.pdf'));
    });

    actingOnTenant($this->works);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'?tab=documents'))
        ->assertOk()
        ->assertDontSee('Health ministry memo');
});

it('reaches the documents tab for a field monitor assigned to the project', function () {
    // A field monitor holds `documents.upload`, so this asserts the panel is
    // reachable at all for the field roles — the collection's own role list is
    // what VaultSecurityTest pins.
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $this->project->id,
        'user_id' => $monitor->id,
    ]);

    $this->actingAs($monitor)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'?tab=documents'))
        ->assertOk()
        ->assertSee('Project photographs');
});

/* -------------------------------------------------------------------------- */
/* The contract's own vault */
/* -------------------------------------------------------------------------- */

it('mounts the contract vault on the contract record', function () {
    $contract = (new AwardContract)(
        $this->project,
        Contractor::factory()->create(['name' => 'Riverside Civil Works Ltd']),
        $this->admin,
        [
            'contract_number' => 'CTR-DOC-0001',
            'type' => 'works',
            'sum' => '450000000.00',
            'scope_of_works' => 'Construction of 7km of township roads including drainage.',
            'award_date' => now()->subMonth()->toDateString(),
        ],
    );

    attachVaultFile($contract, 'contract_documents', $this->admin, 'Bill of quantities', vaultPdf('boq.pdf'));

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$contract->ulid))
        ->assertOk()
        ->assertSee('Contract documents')
        ->assertSee('Bill of quantities');
});

/* -------------------------------------------------------------------------- */
/* The state surface reads, and only reads */
/* -------------------------------------------------------------------------- */

it('shows the project’s files on the oversight record without any way to change them', function () {
    attachVaultFile($this->project, 'project_documents', $this->admin, 'Approval memo');

    $viewer = userWithRole(Role::StateAdmin);
    // StateAdmin mandates 2FA enrolment; stamping the grace anchor keeps this
    // case about the documents panel rather than about the enrolment clock.
    $viewer->forceFill(['two_factor_required_at' => now()])->save();

    actingWithoutTenant();

    $html = (string) $this->actingAs($viewer)
        ->get(oversightUrl('/projects/'.$this->project->ulid))
        ->assertOk()
        ->assertSee('Project documents')
        ->assertSee('Approval memo')
        ->getContent();

    // Read-only means no upload control anywhere on the page: the state reads
    // an entity's evidence, it does not add to or remove from it.
    expect($html)->not->toContain('Add a file')
        ->and($html)->not->toContain('wire:model="upload"');
});

it('signs the oversight download link for the oversight host, not the tenant host', function () {
    attachVaultFile($this->project, 'project_documents', $this->admin, 'Approval memo');

    $viewer = userWithRole(Role::StateAdmin);
    // StateAdmin mandates 2FA enrolment; stamping the grace anchor keeps this
    // case about the documents panel rather than about the enrolment clock.
    $viewer->forceFill(['two_factor_required_at' => now()])->save();

    actingWithoutTenant();

    $html = (string) $this->actingAs($viewer)
        ->get(oversightUrl('/projects/'.$this->project->ulid))
        ->assertOk()
        ->getContent();

    // Both surfaces register `documents.download` under their own prefix, and
    // signing the wrong one hands the viewer a URL for a host they cannot
    // reach — with a signature that would not verify there either.
    expect($html)->toContain('oversight.'.config('platform.domain').'/documents/');
});
