<?php

use App\Actions\Documents\AttachDocument;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Projects\AssignProjectMember;
use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DocumentCollections;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The document vault is where a government record leaves the platform, so the
 * tests here are about who may take one — not about the upload widget.
 */
beforeEach(function () {
    Storage::fake('documents');
    seedPermissions();

    $this->tenant = Tenant::factory()->create(['slug' => 'works']);
    $this->other = Tenant::factory()->create(['slug' => 'health']);

    actingOnTenant($this->tenant);
    $this->project = Project::factory()->for($this->tenant)->create();
});

/**
 * A fake upload with REAL bytes. `UploadedFile::fake()->create()` writes an
 * empty file whose declared mime is a fiction: medialibrary and our own
 * `mimetypes:` rule both sniff the file on disk, so a zero-byte "PDF" sniffs
 * as application/x-empty and is correctly refused. Tests that want to exercise
 * anything past the mime gate have to hand it a real one.
 */
function fakePdf(string $name = 'award-letter.pdf', int $padKilobytes = 0): UploadedFile
{
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent(
        $name,
        $pdf.str_repeat(' ', $padKilobytes * 1024),
    );
}

function attachTo(Project $project, User $actor, string $collection = 'project_documents'): Media
{
    return app(AttachDocument::class)($project, $collection, fakePdf(), $actor);
}

it('stores uploads on the private documents disk under a generated name', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);

    $media = attachTo($this->project, $officer);

    expect($media->disk)->toBe('documents')
        // The uploader's filename survives only as metadata: a name that
        // reaches the filesystem is a traversal and a sniffing problem.
        ->and($media->file_name)->not->toContain('award-letter')
        ->and($media->file_name)->toEndWith('.pdf')
        ->and($media->name)->toBe('award-letter')
        ->and($media->getCustomProperty('original_file_name'))->toBe('award-letter.pdf')
        ->and($media->getCustomProperty('uploaded_by_id'))->toBe($officer->id);

    Storage::disk('documents')->assertExists($media->id.'/'.$media->file_name);
});

it('refuses a mime type the collection does not allow', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);

    app(AttachDocument::class)(
        $this->project,
        // Photographs only — a PDF filed as a site photograph is paperwork
        // masquerading as proof of a visit.
        'project_photos',
        fakePdf('scan.pdf'),
        $officer,
    );
})->throws(ValidationException::class);

it('refuses a file over the collection ceiling', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);

    // The ceiling is lowered for the test rather than building a 20MB string
    // to exceed the real one: the code path under test is the `max:` rule
    // DocumentCollections derives from config, and proving it fires at 1KB
    // proves it fires at 20MB — without asking PHP to hold 20MB of padding.
    config(['documents.collections.project_documents.max_kb' => 1]);

    expect(app(DocumentCollections::class)->validationRules('project_documents'))
        ->toContain('max:1');

    app(AttachDocument::class)(
        $this->project,
        'project_documents',
        fakePdf('huge.pdf', 4),
        $officer,
    );
})->throws(ValidationException::class);

it('keeps a consultant out of the contract vault while letting them file report evidence', function () {
    $consultant = actingAsMember(Role::Consultant, $this->tenant);
    $collections = app(DocumentCollections::class);

    expect($collections->canUpload($consultant, 'contract_documents'))->toBeFalse()
        ->and($collections->canUpload($consultant, 'report_evidence'))->toBeTrue();
});

it('serves a document through a signed, authenticated, policy-checked route', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);
    $media = attachTo($this->project, $officer);

    $url = URL::temporarySignedRoute(
        'tenant.documents.download',
        now()->addMinutes(15),
        ['tenant' => $this->tenant->slug, 'media' => $media->uuid],
    );

    $this->get($url)
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertDownload();
});

it('rejects an unsigned link to a document', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);
    $media = attachTo($this->project, $officer);

    $this->get(tenantUrl($this->tenant, '/documents/'.$media->uuid.'/download'))
        ->assertForbidden();
});

it('rejects a signed link whose window has passed', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);
    $media = attachTo($this->project, $officer);

    $url = URL::temporarySignedRoute(
        'tenant.documents.download',
        now()->addMinutes(15),
        ['tenant' => $this->tenant->slug, 'media' => $media->uuid],
    );

    $this->travel(16)->minutes();

    $this->get($url)->assertForbidden();
});

it('never serves one MDA a document belonging to another, even with a valid signature', function () {
    // Filed in tenant A…
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);
    $media = attachTo($this->project, $officer);

    // …and requested on tenant B's subdomain by tenant B's own officer, with a
    // signature that is genuinely valid for that host. The media table carries
    // no tenant_id, so only MediaPolicy — asking the OWNING record, which loads
    // under its own global scope — stands between the two MDAs here.
    actingOnTenant($this->other);
    $intruder = actingAsMember(Role::MdaAdmin, $this->other);

    $url = URL::temporarySignedRoute(
        'tenant.documents.download',
        now()->addMinutes(15),
        ['tenant' => $this->other->slug, 'media' => $media->uuid],
    );

    $this->actingAs($intruder)->get($url)->assertForbidden();
});

it('logs an append-only audit line when a document is removed', function () {
    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);
    $media = attachTo($this->project, $admin);

    app(DeleteDocument::class)($media, $admin);

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'documents',
        'description' => 'document_deleted',
        'causer_id' => $admin->id,
    ]);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The owner's own visibility rule — a security audit finding */
/* -------------------------------------------------------------------------- */

it('refuses a consultant a document of a project they are not assigned to', function () {
    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);
    $media = attachTo($this->project, $admin, 'project_documents');

    $consultant = memberOf(User::factory()->create(), $this->tenant, Role::Consultant);

    // Same MDA, holds documents.view, and the file's tenant matches — so
    // permission-plus-tenant-match says yes. Only the OWNER's own rule says
    // no: ProjectPolicy::view narrows a consultant to their assignments, and
    // without asking it, a contractor could read a rival's bill of quantities.
    expect($this->project->isVisibleTo($consultant))->toBeFalse()
        ->and($consultant->can('view', $media))->toBeFalse();
});

it('lets a consultant read a document of a project they ARE assigned to', function () {
    $admin = actingAsMember(Role::MdaAdmin, $this->tenant);
    $media = attachTo($this->project, $admin, 'project_documents');

    $consultant = memberOf(User::factory()->create(), $this->tenant, Role::Consultant);

    (new AssignProjectMember)(
        $this->project,
        $consultant,
        ProjectRole::Consultant,
        User::query()->whereKey($admin->getKey())->firstOrFail(),
    );

    expect(User::query()->whereKey($consultant->getKey())->firstOrFail()->can('view', $media))->toBeTrue();
});

it('serves a document to state oversight, where no tenant is bound at all', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);
    $media = attachTo($this->project, $officer);

    // The oversight surface binds no tenant, so loading the owner hits the
    // fail-closed TenantScope. Every oversight document download used to be a
    // permanent 403 because the policy swallowed that and answered "no owner".
    actingWithoutTenant();
    $stateAdmin = userWithRole(Role::StateAdmin);

    expect($stateAdmin->can('view', $media))->toBeTrue();
});

it('still refuses an oversight download to a role without documents.view in the global team', function () {
    $officer = actingAsMember(Role::MeOfficer, $this->tenant);
    $media = attachTo($this->project, $officer);

    actingWithoutTenant();

    // An MDA admin holds documents.view in their WORKSPACE team and nothing
    // globally. The oversight resolution path asks for the GLOBAL permission
    // before it bypasses tenancy — which is the whole reason that bypass is
    // allowed to exist.
    $mdaAdmin = User::query()->whereKey(
        memberOf(User::factory()->create(), $this->tenant, Role::MdaAdmin)->getKey()
    )->firstOrFail();

    actingWithoutTenant();

    expect($mdaAdmin->holdsGlobalPermission('documents.view'))->toBeFalse()
        ->and($mdaAdmin->can('view', $media))->toBeFalse();
});
