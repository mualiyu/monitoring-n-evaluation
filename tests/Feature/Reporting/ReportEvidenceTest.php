<?php

/**
 * Evidence on a progress return (progress-reporting.md §5).
 *
 * The vault itself is the shared document panel and is proven elsewhere. What
 * is specific to reporting — and what this covers — is the FREEZE: evidence is
 * writable exactly while the return is with its author (draft or returned) and
 * read-only from the moment it is filed. Evidence that can change after review
 * is not evidence.
 *
 * Assertions run against the rendered screens, not just the model, because the
 * freeze is enforced on both the wizard and the review screen and a guard on
 * one of them is a guard on neither.
 */

use App\Enums\Role;
use App\Livewire\Shared\DocumentPanel;
use App\Livewire\Tenant\Reporting\ReportForm;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\MediaLibrary\HasMedia;

beforeEach(function () {
    Storage::fake('documents');
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    // What ResolveTenant does on every real request to a workspace. The shared
    // vault panel signs its download links with route('tenant.documents.download')
    // and relies on that default for the subdomain parameter; Livewire::test
    // never crosses HTTP, so nothing would set it here.
    URL::defaults(['tenant' => $this->works->slug]);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->period = ReportingPeriod::factory()->monthly()->create();

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->consultant = User::query()->whereKey($this->consultant->id)->firstOrFail();
});

it('gives a progress return exactly one evidence collection', function () {
    $report = ProgressReport::factory()->forProject($this->project)->forPeriod($this->period)->create();

    expect($report->documentCollections())->toBe(['report_evidence'])
        ->and($report)->toBeInstanceOf(HasMedia::class);
});

it('offers the vault on the last step of the wizard, where evidence is still changeable', function () {
    $draft = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->draft()
        ->create();

    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class, ['report' => $draft])
        ->set('step', 4)
        ->assertOk()
        ->assertSee('Evidence for this return');
});

it('shows the evidence on the review screen over real HTTP', function () {
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/'.$report->ulid))
        ->assertOk()
        ->assertSee('Evidence filed with this return');
});

it('accepts a photograph while the return is still with its author', function () {
    $draft = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->draft()
        ->create();

    Livewire::actingAs($this->consultant)
        ->test(DocumentPanel::class, ['model' => $draft, 'collection' => 'report_evidence'])
        ->set('upload', UploadedFile::fake()->image('culvert.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $report = ProgressReport::query()->whereKey($draft->getKey())->firstOrFail();

    expect($report->getMedia('report_evidence'))->toHaveCount(1);
});

it('accepts a valuation document too — the collection is not photographs only', function () {
    $draft = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->draft()
        ->create();

    // A zero-byte fake sniffs as application/x-empty and is (correctly)
    // refused by the server-side mime check, so the fixture carries real bytes.
    $pdf = UploadedFile::fake()->createWithContent(
        'valuation.pdf',
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );

    Livewire::actingAs($this->consultant)
        ->test(DocumentPanel::class, ['model' => $draft, 'collection' => 'report_evidence'])
        ->set('upload', $pdf)
        ->call('save')
        ->assertHasNoErrors();

    expect(ProgressReport::query()->whereKey($draft->getKey())->firstOrFail()->getMedia('report_evidence'))
        ->toHaveCount(1);
});

it('freezes the vault the moment the return is filed', function () {
    $filed = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    // The screen renders it read-only…
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/reports/'.$filed->ulid))
        ->assertOk()
        ->assertSee('Evidence filed with this return')
        ->assertDontSee('Add a file');

    // …and the panel refuses the upload even when asked directly, because a
    // hidden button is a courtesy and not a control.
    Livewire::actingAs($this->consultant)
        ->test(DocumentPanel::class, [
            'model' => $filed,
            'collection' => 'report_evidence',
            'readonly' => true,
        ])
        ->set('upload', UploadedFile::fake()->image('late-evidence.jpg'))
        ->call('save')
        ->assertForbidden();

    expect(ProgressReport::query()->whereKey($filed->getKey())->firstOrFail()->getMedia('report_evidence'))
        ->toHaveCount(0);
});

it('unfreezes the vault when a reviewer sends the return back', function () {
    $returned = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->returned('The valuation does not match the claimed quantities.')
        ->create();

    // Back with its author, so the evidence is theirs to correct again.
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/reports/'.$returned->ulid))
        ->assertOk()
        ->assertSee('Add a file');
});

it('keeps the vault read-only for a reviewer, who owns the decision and not the evidence', function () {
    $filed = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/'.$filed->ulid))
        ->assertOk()
        ->assertDontSee('Add a file');
});

it('refuses a file type the evidence collection does not accept', function () {
    $draft = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->draft()
        ->create();

    Livewire::actingAs($this->consultant)
        ->test(DocumentPanel::class, ['model' => $draft, 'collection' => 'report_evidence'])
        ->set('upload', UploadedFile::fake()->createWithContent('payload.sh', "#!/bin/sh\nrm -rf /\n"))
        ->call('save')
        ->assertHasErrors('upload');

    expect(ProgressReport::query()->whereKey($draft->getKey())->firstOrFail()->getMedia('report_evidence'))
        ->toHaveCount(0);
});
