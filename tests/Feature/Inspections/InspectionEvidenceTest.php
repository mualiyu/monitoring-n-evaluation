<?php

/**
 * The evidence vault AS THE INSPECTION SCREENS MOUNT IT.
 *
 * The vault's own guarantees — private disk, generated filenames, mime and
 * size rules, signed downloads, cross-tenant refusal — are proven in
 * tests/Feature/Documents/VaultSecurityTest.php and are deliberately not
 * repeated. What is specific to inspections, and what these cases cover, is:
 * that both panels actually MOUNT on the conduct and detail screens over real
 * HTTP (a nested component that fails to mount is precisely the defect a
 * property-setting test cannot see), that the photograph collection takes
 * photographs and nothing else, and that everything FREEZES the moment the
 * report is filed — evidence that can change after it has been read is not
 * evidence.
 */

use App\Actions\Documents\AttachDocument;
use App\Enums\Role;
use App\Livewire\Shared\DocumentPanel;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    $this->inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->inProgress()
        ->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('gives a site visit two evidence collections — photographs and attachments', function () {
    expect($this->inspection->documentCollections())->toBe(['inspection_photos', 'inspection_documents'])
        ->and($this->inspection)->toBeInstanceOf(HasMedia::class);
});

it('mounts both vault panels on the detail screen over real HTTP', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections/'.$this->inspection->ulid))
        ->assertOk()
        ->assertSee('Photographic evidence')
        ->assertSee('Attachments');
});

it('mounts the photograph panel on the conduct form, where the inspector is standing', function () {
    $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$this->inspection->ulid.'/conduct'))
        ->assertOk()
        ->assertSee('Photographic evidence')
        ->assertSee('Add a file');
});

it('accepts a photograph from the inspector while the visit is under way', function () {
    Livewire::actingAs($this->monitor)
        ->test(DocumentPanel::class, [
            'model' => $this->inspection,
            'collection' => 'inspection_photos',
        ])
        // ->image(), never ->create(): a zero-byte fake sniffs as
        // application/x-empty and is correctly refused by the mime check.
        ->set('upload', UploadedFile::fake()->image('site.jpg', 1024, 768))
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail()
        ->getMedia('inspection_photos'))->toHaveCount(1);
});

it('refuses a file whose bytes are not the photograph its name claims', function () {
    // `UploadedFile::fake()->create()` writes a ZERO-BYTE file and injects the
    // mime type from the extension, so the `mimetypes:` rule is satisfied by a
    // claim. What refuses it is the SNIFF — medialibrary reads the actual
    // bytes (application/x-empty) against the same accepted list the validator
    // used, and declines the collection. Two independent checks on the same
    // config is the property worth having: a file that lies about itself gets
    // past neither, and nothing lands in the vault.
    expect(fn () => app(AttachDocument::class)(
        $this->inspection,
        'inspection_photos',
        UploadedFile::fake()->create('site.jpg', 120),
        $this->monitor,
    ))->toThrow(FileUnacceptableForCollection::class);

    expect(SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail()
        ->getMedia('inspection_photos'))->toHaveCount(0);

    Storage::disk('documents')->assertDirectoryEmpty('/');
});

it('refuses a document in the photograph collection, which is images only', function () {
    Livewire::actingAs($this->monitor)
        ->test(DocumentPanel::class, [
            'model' => $this->inspection,
            'collection' => 'inspection_photos',
        ])
        ->set('upload', UploadedFile::fake()->createWithContent(
            'valuation.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        ))
        ->call('save')
        ->assertHasErrors('upload');
});

it('takes that same document in the attachments collection, which is not images only', function () {
    Livewire::actingAs($this->monitor)
        ->test(DocumentPanel::class, [
            'model' => $this->inspection,
            'collection' => 'inspection_documents',
        ])
        ->set('upload', UploadedFile::fake()->createWithContent(
            'measurement-sheet.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        ))
        ->call('save')
        ->assertHasNoErrors();

    expect(SiteInspection::query()->whereKey($this->inspection->getKey())->firstOrFail()
        ->getMedia('inspection_documents'))->toHaveCount(1);
});

it('freezes the vault the moment the report is filed', function () {
    $filed = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create();

    // The screen renders it read-only…
    $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$filed->ulid))
        ->assertOk()
        ->assertSee('Photographic evidence')
        ->assertDontSee('Add a file');

    // …and the panel refuses the upload when asked directly, because a hidden
    // button is a courtesy and not a control.
    Livewire::actingAs($this->monitor)
        ->test(DocumentPanel::class, [
            'model' => $filed,
            'collection' => 'inspection_photos',
            'readonly' => true,
        ])
        ->set('upload', UploadedFile::fake()->image('late-evidence.jpg'))
        ->call('save')
        ->assertForbidden();

    expect(SiteInspection::query()->whereKey($filed->getKey())->firstOrFail()
        ->getMedia('inspection_photos'))->toHaveCount(0);
});

it('keeps the existing photographs readable after the report is signed off', function () {
    $filed = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create();

    app(AttachDocument::class)(
        $filed,
        'inspection_photos',
        UploadedFile::fake()->image('northern-section.jpg', 800, 600),
        $this->monitor,
        'Northern section, looking east',
    );

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections/'.$filed->ulid))
        ->assertOk()
        ->assertSee('Northern section, looking east');
});
