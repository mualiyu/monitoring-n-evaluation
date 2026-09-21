<?php

/**
 * The ad-hoc report builder (App\Livewire\Oversight\Exports\ReportBuilder).
 *
 * THE property under test is the honesty rule: every figure the builder prints
 * comes from the SAME app/Actions/Oversight/ Action the matching dashboard
 * calls. A builder with its own query would eventually print a number the
 * board disagrees with, so the preview is asserted against the Action's own
 * output rather than against a hand-written expectation.
 *
 * The second property is that nothing leaves the platform unobserved: every
 * generated file writes a report_exports row carrying the filters and the
 * actor, lands on a PRIVATE disk, and is reachable only through the signed,
 * policy-checked download route.
 */

use App\Actions\Oversight\BuildComplianceLeagueTable;
use App\Actions\Oversight\ListProjectsAcrossTenants;
use App\Enums\ExportFormat;
use App\Enums\ProjectStatus;
use App\Enums\ReportDataset;
use App\Enums\Role;
use App\Livewire\Oversight\Exports\ReportBuilder;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Exporting\ReportExporter;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();
    Storage::fake('documents');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->period = ReportingPeriod::factory()->quarterly(2026, 1)->create();

    $current = app(CurrentTenant::class);

    $current->runAs($this->works, function () {
        $project = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'contract_value_total' => '300000000.00',
        ]);

        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->fulfilled()->create();
        ProgressReport::factory()->forProject($project)->forPeriod($this->period)->approved()->create();
    });

    $current->runAs($this->health, function () {
        $project = Project::factory()->ongoing()->create([
            'title' => 'Cottage Hospital Rewiring',
            'contract_value_total' => '200000000.00',
        ]);

        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->missed()->create();
    });

    $current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
    $this->reviewer = userWithRole(Role::DataQualityReviewer);

    foreach ([$this->stateAdmin, $this->execViewer, $this->reviewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window
    }

    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $current->forget();
    Cache::flush();
});

/* -------------------------------------------------------------------------- */
/* The route reaches the screen */
/* -------------------------------------------------------------------------- */

it('renders the builder over real HTTP with a preview of the default dataset', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/reports/builder'))
        ->assertOk()
        ->assertSeeLivewire(ReportBuilder::class)
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Cottage Hospital Rewiring')
        ->assertSee('Ministry of Works');
});

it('opens for every oversight role', function () {
    foreach ([$this->stateAdmin, $this->execViewer, $this->reviewer] as $user) {
        $this->actingAs($user)
            ->get(oversightUrl('/reports/builder'))
            ->assertOk();
    }
});

it('refuses the builder to a workspace user, however senior', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/reports/builder'))
        ->assertForbidden();

    Livewire::actingAs(User::query()->whereKey($this->mdaAdmin->id)->firstOrFail())
        ->test(ReportBuilder::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* THE honesty rule: one source, shared with the dashboards */
/* -------------------------------------------------------------------------- */

it('previews projects through the very Action the state portfolio board uses', function () {
    $board = (new ListProjectsAcrossTenants)($this->stateAdmin, [
        'tenant' => null, 'status' => null, 'search' => null, 'overdue' => false,
    ]);

    $preview = Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->instance()
        ->preview();

    expect(array_column($preview, 'title'))
        ->toBe($board->pluck('title')->all())
        ->and(array_column($preview, 'entity'))
        ->toBe($board->pluck('tenant.name')->all());
});

it('previews compliance through the very Action the league table uses', function () {
    $board = app(BuildComplianceLeagueTable::class)($this->stateAdmin, $this->period);

    $screen = Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('dataset', ReportDataset::Compliance->value)
        ->set('period', $this->period->code);

    $preview = $screen->instance()->preview();

    expect(array_column($preview, 'entity'))->toBe(array_column($board['tenants'], 'name'))
        ->and(array_column($preview, 'on_time_rate'))->toBe(array_column($board['tenants'], 'on_time_rate'));
});

it('narrows the preview with the filter bar', function () {
    $screen = Livewire::actingAs($this->stateAdmin)->test(ReportBuilder::class);

    expect($screen->instance()->rowCount())->toBe(2);

    $screen->set('tenant', 'health');
    expect(array_column($screen->instance()->preview(), 'entity'))->toBe(['Ministry of Health']);

    $screen->set('tenant', '')->set('search', 'Township');
    expect(array_column($screen->instance()->preview(), 'title'))->toBe(['Township Road Rehabilitation']);

    $screen->call('clearFilters');
    expect($screen->instance()->rowCount())->toBe(2);
});

it('resets the column selection when the dataset changes, because column keys are per-dataset contracts', function () {
    $screen = Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('columns', ['entity', 'reference', 'title'])
        ->set('dataset', ReportDataset::Compliance->value);

    expect($screen->get('columns'))->toBe(ReportDataset::Compliance->defaultColumns())
        ->and($screen->get('groupBy'))->toBe('');
});

it('drops a column key that does not belong to the dataset rather than printing an empty column', function () {
    $screen = Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('columns', ['entity', 'title', 'a_column_that_does_not_exist']);

    expect($screen->get('columns'))->toBe(['entity', 'title']);
});

/**
 * THE rendered-form test. Livewire::test() sets properties directly and never
 * renders the markup a browser posts, so a checkbox carrying the wrong value
 * would ship green. This reads a column checkbox out of the HTML the screen
 * produced and feeds exactly that value back — then proves the generated file
 * printed that column's heading.
 */
it('exports the column whose value its own rendered checkbox carries', function () {
    $html = Livewire::actingAs($this->stateAdmin)->test(ReportBuilder::class)->html();

    // The checkbox for the "Sector" column, as the browser would post it.
    expect($html)->toContain('id="column-sector"');

    $matched = preg_match('/<input[^>]*id="column-sector"[^>]*>/', $html, $matches) === 1;
    expect($matched)->toBeTrue();

    preg_match('/value="([^"]*)"/', $matches[0], $value);
    $rendered = $value[1] ?? '';

    expect($rendered)->toBe('sector');

    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('columns', ['entity', $rendered])
        ->set('title', 'Portfolio by sector')
        ->call('generate', ExportFormat::Csv->value)
        ->assertHasNoErrors();

    $export = ReportExport::query()->firstOrFail();
    $csv = Storage::disk('documents')->get((string) $export->path);

    expect($export->columns)->toBe(['entity', 'sector'])
        ->and($csv)->toContain('Sector')
        ->and($csv)->toContain('Ministry of Works');
});

/* -------------------------------------------------------------------------- */
/* Generating: the register, the private disk, the signed link */
/* -------------------------------------------------------------------------- */

it('records every export with its filters and its requester', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('tenant', 'works')
        ->set('status', ProjectStatus::InProgress->value)
        ->set('title', 'Works in progress')
        ->call('generate', ExportFormat::Csv->value)
        ->assertHasNoErrors()
        ->assertRedirect();

    $export = ReportExport::query()->firstOrFail();

    expect($export->dataset)->toBe(ReportDataset::Projects)
        ->and($export->format)->toBe(ExportFormat::Csv)
        ->and($export->title)->toBe('Works in progress')
        ->and($export->generated_by_id)->toBe($this->stateAdmin->id)
        ->and($export->surface)->toBe('oversight')
        ->and($export->isReady())->toBeTrue()
        ->and($export->row_count)->toBe(1)
        // "1 project" is not a figure until you know what was asked.
        ->and($export->filters['filters'])->toBe([
            'tenant' => 'works',
            'status' => ProjectStatus::InProgress->value,
        ])
        // Provenance: an oversight artifact belongs to no workspace.
        ->and($export->generated_for_tenant_id)->toBeNull();
});

it('never writes a generated file to a public disk or under a name a user typed', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('title', '../../etc/passwd')
        ->call('generate', ExportFormat::Csv->value)
        ->assertHasNoErrors();

    $export = ReportExport::query()->firstOrFail();

    expect($export->disk)->toBe('documents')
        ->and($export->path)->toStartWith('exports/')
        ->and($export->path)->toContain($export->ulid)
        ->and($export->path)->not->toContain('..')
        ->and($export->file_name)->not->toContain('/');

    Storage::disk('documents')->assertExists($export->path);
});

it('hands the file over only through the signed, policy-checked route', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->call('generate', ExportFormat::Csv->value)
        ->assertHasNoErrors();

    $export = ReportExport::query()->firstOrFail();

    // Unsigned: refused, even for the officer who generated it.
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/exports/'.$export->ulid.'/download'))
        ->assertForbidden();

    // Signed: served, as an attachment that cannot render on our own origin.
    $response = $this->actingAs($this->stateAdmin)
        ->get(app(ReportExporter::class)->downloadUrl($export));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload();

    expect($response->streamedContent())->toContain('Ministry of Works');
});

it('refuses a signed link to a user with no oversight authority at all', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->call('generate', ExportFormat::Csv->value);

    $export = ReportExport::query()->firstOrFail();

    $this->actingAs($this->mdaAdmin)
        ->get(app(ReportExporter::class)->downloadUrl($export))
        ->assertForbidden();
});

it('writes a spreadsheet and a PDF through the same register', function () {
    // Asserted inside the loop, against the artifact each call produced,
    // rather than over a collection gathered at the end: the interesting claim
    // is "this generate() call left THIS file on the private disk", and
    // checking it a generation later only widens the gap between the write and
    // the assertion.
    foreach ([ExportFormat::Xlsx, ExportFormat::Pdf] as $index => $format) {
        Livewire::actingAs($this->stateAdmin)
            ->test(ReportBuilder::class)
            ->set('title', 'Portfolio as '.$format->value)
            ->call('generate', $format->value)
            ->assertHasNoErrors();

        $export = ReportExport::query()->orderByDesc('id')->firstOrFail();

        expect(ReportExport::query()->count())->toBe($index + 1)
            ->and($export->format)->toBe($format)
            ->and($export->mime_type)->toBe($format->mimeType())
            ->and($export->isDownloadable())->toBeTrue();

        Storage::disk('documents')->assertExists($export->path);
    }
});

it('refuses to generate a spreadsheet with no columns at all', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('columns', [])
        ->call('generate', ExportFormat::Csv->value)
        ->assertHasErrors(['columns']);

    expect(ReportExport::query()->count())->toBe(0);
});

it('refuses a format this platform does not write', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->call('generate', 'docx')
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null);

    expect(ReportExport::query()->count())->toBe(0);
});

it('exports an empty answer rather than pretending nothing was asked', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ReportBuilder::class)
        ->set('search', 'a project nobody has ever built')
        ->call('generate', ExportFormat::Csv->value)
        ->assertHasNoErrors();

    $export = ReportExport::query()->firstOrFail();

    expect($export->row_count)->toBe(0)
        ->and($export->isReady())->toBeTrue()
        ->and($export->filters['filters']['search'])->toBe('a project nobody has ever built');
});
