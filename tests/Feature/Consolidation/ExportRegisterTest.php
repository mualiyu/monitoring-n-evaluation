<?php

/**
 * The generated-artifact register
 * (App\Livewire\Oversight\Exports\ExportRegister).
 *
 * ⚠ ON TENANCY. ReportExport carries no tenant_id, deliberately: the register
 * records oversight artifacts (which cross every MDA and belong to none)
 * beside workspace artifacts (which do not), and a scope key would hide the
 * state's own files from the state. `generated_for_tenant_id` is PROVENANCE,
 * and the cross-workspace gate that a scope would otherwise give for free is
 * enforced in ReportExportPolicy instead — which makes that policy the only
 * thing standing between one workspace's artifact and another's. It is
 * therefore tested here directly, with a workspace bound, as well as through
 * the screen.
 */

use App\Enums\ExportFormat;
use App\Enums\ReportDataset;
use App\Enums\Role;
use App\Livewire\Oversight\Exports\ExportRegister;
use App\Models\ConsolidatedReport;
use App\Models\ReportExport;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Exporting\ReportExporter;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();
    Storage::fake('documents');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    app(CurrentTenant::class)->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);

    foreach ([$this->stateAdmin, $this->execViewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window
    }

    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    app(CurrentTenant::class)->forget();
});

/** A register row whose file genuinely exists on the private disk. */
function storedExport(array $state = []): ReportExport
{
    /** @var ReportExport $export */
    $export = ReportExport::factory()->ready()->create($state);

    Storage::disk('documents')->put((string) $export->path, "Entity,Project\nMinistry of Works,Township Road\n");

    return $export;
}

/* -------------------------------------------------------------------------- */
/* The route reaches the screen */
/* -------------------------------------------------------------------------- */

it('renders the register over real HTTP with the question each artifact answered', function () {
    storedExport([
        'title' => 'Works in progress',
        'generated_by_id' => $this->stateAdmin->id,
    ]);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/exports'))
        ->assertOk()
        ->assertSeeLivewire(ExportRegister::class)
        ->assertSee('Works in progress')
        ->assertSee($this->stateAdmin->name)
        // The filters are printed on the row, not hidden behind a click.
        ->assertSee('Status')
        ->assertSee('Ready');
});

it('shows a designed empty state before anything has been exported', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/exports'))
        ->assertOk()
        ->assertSee('Nothing has been exported yet');
});

it('lets a read-only oversight role read the register', function () {
    storedExport(['title' => 'Compliance league table']);

    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/exports'))
        ->assertOk()
        ->assertSee('Compliance league table');
});

it('refuses the register to a workspace user, however senior', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/exports'))
        ->assertForbidden();

    Livewire::actingAs(User::query()->whereKey($this->mdaAdmin->id)->firstOrFail())
        ->test(ExportRegister::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Filters and the summary row */
/* -------------------------------------------------------------------------- */

it('narrows the register by dataset, format, outcome and requester', function () {
    storedExport(['title' => 'Portfolio CSV', 'generated_by_id' => $this->stateAdmin->id]);
    ReportExport::factory()
        ->dataset(ReportDataset::Compliance)
        ->format(ExportFormat::Pdf)
        ->failed()
        ->create(['title' => 'Compliance PDF', 'generated_by_id' => $this->execViewer->id]);

    $screen = Livewire::actingAs($this->stateAdmin)->test(ExportRegister::class);

    expect($screen->instance()->exports->total())->toBe(2);

    $screen->set('dataset', ReportDataset::Compliance->value);
    expect($screen->instance()->exports->pluck('title')->all())->toBe(['Compliance PDF']);

    $screen->call('clearFilters')->set('format', ExportFormat::Csv->value);
    expect($screen->instance()->exports->pluck('title')->all())->toBe(['Portfolio CSV']);

    $screen->call('clearFilters')->set('status', ReportExport::STATUS_FAILED);
    expect($screen->instance()->exports->pluck('title')->all())->toBe(['Compliance PDF']);

    $screen->call('clearFilters')->set('mineOnly', true);
    expect($screen->instance()->exports->pluck('title')->all())->toBe(['Portfolio CSV']);

    $screen->call('clearFilters')->set('search', 'Compliance');
    expect($screen->instance()->exports->pluck('title')->all())->toBe(['Compliance PDF']);
});

it('counts the register by outcome', function () {
    storedExport(['generated_by_id' => $this->stateAdmin->id]);
    ReportExport::factory()->pending()->create();
    ReportExport::factory()->failed()->create();

    $stats = Livewire::actingAs($this->stateAdmin)->test(ExportRegister::class)->instance()->stats;

    expect($stats['total'])->toBe(3)
        ->and($stats['ready'])->toBe(1)
        ->and($stats['pending'])->toBe(1)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['mine'])->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* Re-download */
/* -------------------------------------------------------------------------- */

it('re-downloads through the signed route rather than streaming the file itself', function () {
    $export = storedExport(['generated_by_id' => $this->stateAdmin->id]);

    Livewire::actingAs($this->stateAdmin)
        ->test(ExportRegister::class)
        ->call('download', $export->ulid)
        ->assertRedirect();

    $response = $this->actingAs($this->stateAdmin)
        ->get(app(ReportExporter::class)->downloadUrl($export));

    $response->assertOk()->assertDownload();

    expect($response->streamedContent())->toContain('Ministry of Works');
});

it('says an artifact has been pruned rather than pretending it is forbidden', function () {
    // Retention removes the FILE; the row recording who took it is never
    // deleted, so the screen has to have words for that state.
    $export = ReportExport::factory()->ready()->create();

    expect($export->isDownloadable())->toBeFalse();

    Livewire::actingAs($this->stateAdmin)
        ->test(ExportRegister::class)
        ->call('download', $export->ulid)
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null
            && str_contains($failure, 'no longer held'))
        ->assertNoRedirect();
});

it('names a failed generation on the register instead of offering a download', function () {
    $export = ReportExport::factory()
        ->failed('The export worker stopped before the file was written.')
        ->create(['title' => 'Indicator performance']);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/exports'))
        ->assertOk()
        ->assertSee('Failed')
        ->assertSee('The export worker stopped before the file was written.');

    Livewire::actingAs($this->stateAdmin)
        ->test(ExportRegister::class)
        ->call('download', $export->ulid)
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null
            && str_contains($failure, 'failed to generate'));
});

it('refuses an expired artifact even while its file is still on disk', function () {
    $export = ReportExport::factory()->expired()->create();
    Storage::disk('documents')->put((string) $export->path, 'still here');

    expect($export->hasExpired())->toBeTrue()
        ->and($export->isDownloadable())->toBeFalse();

    $this->actingAs($this->stateAdmin)
        ->get(app(ReportExporter::class)->downloadUrl($export))
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Provenance — the cross-workspace gate a global register has to get right */
/* -------------------------------------------------------------------------- */

it('refuses one workspace’s artifact to a request bound to another workspace', function () {
    $worksArtifact = storedExport([
        'generated_for_tenant_id' => $this->works->id,
        'surface' => 'tenant',
        'generated_by_id' => $this->stateAdmin->id,
    ]);

    $reader = User::query()->whereKey($this->stateAdmin->id)->firstOrFail();

    // Bound to the OTHER ministry: refused, however much oversight authority
    // the requester holds.
    actingOnTenant($this->health);
    expect($reader->can('download', $worksArtifact))->toBeFalse()
        ->and($reader->can('view', $worksArtifact))->toBeFalse();

    // Bound to its own workspace: allowed.
    actingOnTenant($this->works);
    expect($reader->can('download', $worksArtifact))->toBeTrue();

    // On the oversight surface no workspace is bound at all, and the state may
    // read every entity's artifacts — that is what the surface is for.
    actingWithoutTenant();
    expect($reader->can('download', $worksArtifact))->toBeTrue();
});

it('keeps two workspaces’ artifacts apart in the register the state reads', function () {
    storedExport([
        'title' => 'Works quarterly extract',
        'generated_for_tenant_id' => $this->works->id,
        'surface' => 'tenant',
    ]);
    storedExport([
        'title' => 'Health quarterly extract',
        'generated_for_tenant_id' => $this->health->id,
        'surface' => 'tenant',
    ]);

    actingWithoutTenant();

    // The state sees both, and each row names the entity it was generated for
    // — the provenance the register exists to record.
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/exports'))
        ->assertOk()
        ->assertSee('Works quarterly extract')
        ->assertSee('Health quarterly extract')
        ->assertSee('Generated for Ministry of Works')
        ->assertSee('Generated for Ministry of Health');

    $reader = User::query()->whereKey($this->stateAdmin->id)->firstOrFail();

    $works = ReportExport::query()->where('title', 'Works quarterly extract')->firstOrFail();
    $health = ReportExport::query()->where('title', 'Health quarterly extract')->firstOrFail();

    actingOnTenant($this->works);
    expect($reader->can('download', $works))->toBeTrue()
        ->and($reader->can('download', $health))->toBeFalse();

    actingOnTenant($this->health);
    expect($reader->can('download', $works))->toBeFalse()
        ->and($reader->can('download', $health))->toBeTrue();

    actingWithoutTenant();
});

it('lets the officer who generated an artifact retrieve it, but never through a surface that is not theirs', function () {
    // The policy lets a generator retrieve their own artifact: they already
    // held the authority that produced it, and their name is on the row. It
    // is the one bypass of oversight.reports.view, and it is still gated on
    // provenance and on the file being there.
    $export = storedExport(['generated_by_id' => $this->mdaAdmin->id]);

    $author = User::query()->whereKey($this->mdaAdmin->id)->firstOrFail();

    actingWithoutTenant();

    expect($author->can('download', $export))->toBeTrue()
        ->and($author->can('view', $export))->toBeTrue();

    // …and the SURFACE still refuses, because the oversight download route
    // sits behind the oversight role gate. Two independent controls, and the
    // outer one fires first — which is why a permissive policy branch is not
    // a hole in the state's surface.
    $this->actingAs($author)
        ->get(app(ReportExporter::class)->downloadUrl($export))
        ->assertForbidden();

    // A user who holds neither the row nor oversight authority is refused by
    // the policy itself, not merely by the surface.
    $stranger = memberOf(User::factory()->create(), $this->health, Role::MeOfficer);
    actingWithoutTenant();

    expect(User::query()->whereKey($stranger->id)->firstOrFail()->can('download', $export))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* Links back to what produced the artifact */
/* -------------------------------------------------------------------------- */

it('links a consolidation artifact back to the consolidation it states', function () {
    $period = ReportingPeriod::factory()->annual(2026)->create();
    $report = ConsolidatedReport::factory()->forPeriod($period)->approved()->create([
        'title' => 'Annual Performance Report — 2026',
    ]);

    storedExport([
        'title' => $report->reference.' Annual Performance Report — 2026',
        'dataset' => ReportDataset::Consolidation,
        'consolidated_report_id' => $report->id,
    ]);

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/exports'))
        ->assertOk()
        ->assertSee($report->reference)
        ->assertSee(route('oversight.consolidation.show', ['consolidatedReport' => $report]));
});
