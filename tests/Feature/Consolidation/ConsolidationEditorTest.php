<?php

/**
 * The consolidation itself, on screen
 * (App\Livewire\Oversight\Consolidation\ConsolidationEditor).
 *
 * The screen is a thin shell over the Actions — which is exactly what these
 * tests check: that the route reaches it, that every mutating method
 * authorizes on its own account (route middleware does not gate a Livewire
 * update POST), and that a domain refusal arrives on the screen as the
 * Action's own words rather than as a 500.
 */

use App\Actions\Consolidation\ApproveConsolidation;
use App\Actions\Consolidation\CompileConsolidatedFigures;
use App\Actions\Consolidation\OpenConsolidation;
use App\Actions\Consolidation\RecordConsolidationSection;
use App\Actions\Consolidation\SubmitConsolidationForReview;
use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use App\Enums\ExportFormat;
use App\Enums\ReportDataset;
use App\Enums\Role;
use App\Livewire\Oversight\Consolidation\ConsolidationEditor;
use App\Models\ConsolidatedReport;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();
    Storage::fake('documents');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->annual = ReportingPeriod::factory()->annual(2026)->create();

    $current = app(CurrentTenant::class);

    $current->runAs($this->works, function () {
        $project = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'contract_value_total' => '300000000.00',
            'expenditure_to_date' => '120000000.00',
            'physical_progress' => '40.00',
        ]);

        ReportObligation::factory()->forProject($project)->forPeriod($this->annual)->fulfilled()->create();
        ProgressReport::factory()->forProject($project)->forPeriod($this->annual)->approved()->create();
    });

    $current->runAs($this->health, function () {
        $project = Project::factory()->ongoing()->create([
            'title' => 'Cottage Hospital Rewiring',
            'contract_value_total' => '200000000.00',
        ]);

        ReportObligation::factory()->forProject($project)->forPeriod($this->annual)->missed()->create();
    });

    $current->forget();

    $this->compiler = userWithRole(Role::StateAdmin);
    $this->director = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
    $this->reviewer = userWithRole(Role::DataQualityReviewer);

    foreach ([$this->compiler, $this->director, $this->execViewer, $this->reviewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window
    }

    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $current->forget();
    Cache::flush();

    $this->report = app(OpenConsolidation::class)(
        $this->compiler,
        $this->annual,
        ConsolidatedReportType::AnnualApr,
        'Annual Performance Report — 2026',
    );
});

function editor(User $actor, ConsolidatedReport $report): Testable
{
    return Livewire::actingAs($actor)->test(ConsolidationEditor::class, ['consolidatedReport' => $report]);
}

/* -------------------------------------------------------------------------- */
/* The route reaches the screen */
/* -------------------------------------------------------------------------- */

it('renders one consolidation over real HTTP, bound by ULID', function () {
    $this->actingAs($this->compiler)
        ->get(oversightUrl('/consolidation/'.$this->report->ulid))
        ->assertOk()
        ->assertSeeLivewire(ConsolidationEditor::class)
        ->assertSee('Annual Performance Report — 2026')
        ->assertSee($this->report->reference)
        // The skeleton the TYPE fixes, rendered as the chapter list.
        ->assertSee('Performance against the indicator list')
        ->assertSee('Nothing has been rolled up yet');
});

it('never exposes an auto-increment id as the handle', function () {
    $this->actingAs($this->compiler)
        ->get(oversightUrl('/consolidation/'.$this->report->id))
        ->assertNotFound();
});

it('lets a read-only oversight role read the consolidation', function () {
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/consolidation/'.$this->report->ulid))
        ->assertOk()
        ->assertSee('Annual Performance Report — 2026')
        // …and tells them, in words, that no step of the chain is theirs.
        ->assertSee('no step of its chain is yours');
});

it('refuses the consolidation to a workspace user, however senior', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/consolidation/'.$this->report->ulid))
        ->assertForbidden();

    Livewire::actingAs(User::query()->whereKey($this->mdaAdmin->id)->firstOrFail())
        ->test(ConsolidationEditor::class, ['consolidatedReport' => $this->report])
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Compiling and writing */
/* -------------------------------------------------------------------------- */

it('compiles the roll-up from the screen and shows every entity in the annex', function () {
    editor($this->compiler, $this->report)
        ->call('compile')
        ->assertHasNoErrors()
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health');

    $report = ConsolidatedReport::query()->whereKey($this->report->getKey())->firstOrFail();

    expect($report->status)->toBe(ConsolidationStatus::Compiling)
        ->and($report->entity_count)->toBe(1)   // only Works filed anything
        ->and($report->denominator)->toBe(2)
        ->and($report->entries()->count())->toBe(2);
});

it('writes one chapter and then the whole narrative', function () {
    $screen = editor($this->compiler, $this->report)->call('compile');

    $screen->set('sections.executive_summary', 'The year in one paragraph, with the entity that filed nothing named.')
        ->call('saveSection', 'executive_summary')
        ->assertHasNoErrors();

    expect(ConsolidatedReport::query()->whereKey($this->report->getKey())->firstOrFail()
        ->sections()->where('key', 'executive_summary')->value('body'))
        ->toContain('filed nothing');

    $screen->set('sections.recommendations', 'Sanction the entity that filed nothing; publish the league table.')
        ->call('saveNarrative')
        ->assertHasNoErrors();

    expect(ConsolidatedReport::query()->whereKey($this->report->getKey())->firstOrFail()
        ->sections()->where('key', 'recommendations')->value('body'))
        ->toContain('Sanction');
});

it('shows the Action’s own words when the narrative is sent up without a summary', function () {
    editor($this->compiler, $this->report)
        ->call('compile')
        ->call('submitForReview')
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null
            && str_contains($failure, 'Executive summary'));

    expect(ConsolidatedReport::query()->whereKey($this->report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::Compiling);
});

/* -------------------------------------------------------------------------- */
/* The chain, from the screen */
/* -------------------------------------------------------------------------- */

it('sends the roll-up up the chain and renders the ledger as a timeline', function () {
    $screen = editor($this->compiler, $this->report)
        ->call('compile')
        ->set('sections.executive_summary', 'The year in one paragraph.')
        ->call('saveSection', 'executive_summary')
        ->call('submitForReview')
        ->assertHasNoErrors();

    expect(ConsolidatedReport::query()->whereKey($this->report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::InReview);

    $screen->assertSee('Draft → Compiling')
        ->assertSee('Compiling → In review')
        ->assertSee($this->compiler->name);
});

it('refuses to offer the approve button to the officer who compiled the figures', function () {
    $report = readyForReview($this->report, $this->compiler, $this->director);

    $screen = editor($this->compiler, $report);

    expect($screen->instance()->canApprove())->toBeFalse();

    $screen->assertDontSee('Approve and freeze')
        ->assertSee('You compiled these figures');
});

it('refuses the approval itself when the compiler forces it anyway', function () {
    // Hiding a button is a courtesy, not a control: the separation guard lives
    // in the chokepoint, so a hand-made Livewire request hits it too.
    $report = readyForReview($this->report, $this->compiler, $this->director);

    editor($this->compiler, $report)
        ->call('approve')
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null
            && str_contains($failure, 'cannot also sign them off'));

    expect(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::InReview);
});

it('approves, freezes and then publishes for a second pair of eyes', function () {
    $report = readyForReview($this->report, $this->compiler, $this->director);

    $approver = userWithRole(Role::StateAdmin);
    app(CurrentTenant::class)->forget();

    editor($approver, $report)
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('These figures are frozen');

    $signed = ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();

    expect($signed->status)->toBe(ConsolidationStatus::Approved)
        ->and($signed->snapshot)->toBeArray();

    editor($approver, $signed)
        ->call('publish')
        ->assertHasNoErrors();

    expect(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::Published);
});

it('returns a consolidation for rework, with a reason the secretariat can act on', function () {
    $report = readyForReview($this->report, $this->compiler, $this->director);

    $screen = editor($this->director, $report)->call('startReturn');

    // A return with no explanation is refused at the form, before the Action.
    $screen->set('returnReason', 'no')->call('confirmReturn')->assertHasErrors(['returnReason']);

    $screen->set('returnReason', 'The compliance chapter does not explain the entity that filed nothing.')
        ->call('confirmReturn')
        ->assertHasNoErrors();

    $returned = ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();

    expect($returned->status)->toBe(ConsolidationStatus::Compiling)
        ->and($returned->return_reason)->toContain('filed nothing');
});

/* -------------------------------------------------------------------------- */
/* Authorization matrix, per oversight role */
/* -------------------------------------------------------------------------- */

it('refuses compiling and approving to every oversight role without secretariat authority', function () {
    foreach ([$this->execViewer, $this->reviewer] as $user) {
        editor($user, $this->report)->call('compile')->assertForbidden();
        editor($user, $this->report)->call('saveNarrative')->assertForbidden();
    }

    $report = readyForReview($this->report, $this->compiler, $this->director);

    foreach ([$this->execViewer, $this->reviewer] as $user) {
        editor($user, $report)->call('approve')->assertForbidden();
        editor($user, $report)->call('startReturn')->assertForbidden();
    }

    expect(ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail()->status)
        ->toBe(ConsolidationStatus::InReview);
});

it('closes the narrative form once the report has left the desk', function () {
    $report = readyForReview($this->report, $this->compiler, $this->director);

    $screen = editor($this->director, $report);

    expect($screen->instance()->canEditNarrative())->toBeFalse();

    $screen->call('saveSection', 'executive_summary')->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Artifacts */
/* -------------------------------------------------------------------------- */

it('generates a PDF of the signed report, registers it, and hands it over through a signed link', function () {
    $report = readyForReview($this->report, $this->compiler, $this->director);
    $approver = userWithRole(Role::StateAdmin);
    app(CurrentTenant::class)->forget();

    app(ApproveConsolidation::class)(
        ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail(),
        $approver,
    );

    $signed = ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();

    editor($this->director, $signed)
        ->call('export', ExportFormat::Pdf->value)
        ->assertHasNoErrors()
        ->assertRedirect();

    $export = ReportExport::query()->firstOrFail();

    expect($export->dataset)->toBe(ReportDataset::Consolidation)
        ->and($export->format)->toBe(ExportFormat::Pdf)
        ->and($export->consolidated_report_id)->toBe($signed->id)
        ->and($export->generated_by_id)->toBe($this->director->id)
        ->and($export->isReady())->toBeTrue()
        // Never on a public disk, and never under a user-typed name.
        ->and($export->disk)->toBe('documents')
        ->and($export->path)->toStartWith('exports/')
        ->and($export->path)->toContain($export->ulid);

    Storage::disk('documents')->assertExists($export->path);

    // The filters recorded against the artifact say which report it answered.
    expect($export->filters['filters']['consolidation'])->toBe($signed->reference)
        ->and($export->filters['filters']['period'])->toBe($this->annual->code);
});

it('writes the per-entity annex as a spreadsheet through the same register', function () {
    $screen = editor($this->compiler, $this->report)->call('compile');

    $screen->call('export', ExportFormat::Csv->value)->assertHasNoErrors();

    $export = ReportExport::query()->firstOrFail();

    expect($export->format)->toBe(ExportFormat::Csv)
        ->and($export->row_count)->toBe(2)   // both entities, including the silent one
        ->and($export->isDownloadable())->toBeTrue();

    $csv = Storage::disk('documents')->get((string) $export->path);

    expect($csv)->toContain('Ministry of Works')
        ->and($csv)->toContain('Ministry of Health');
});

it('refuses an export format this platform does not write', function () {
    editor($this->compiler, $this->report)
        ->call('export', 'docx')
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null);

    expect(ReportExport::query()->count())->toBe(0);
});

/**
 * Open → compile → summary → sent up by $submitter, leaving the report in
 * review with $compiler on the compile and $submitter on the submission.
 */
function readyForReview(ConsolidatedReport $report, User $compiler, User $submitter): ConsolidatedReport
{
    app(CompileConsolidatedFigures::class)($report, $compiler);

    app(RecordConsolidationSection::class)(
        $report,
        'executive_summary',
        'The year in one paragraph, with the entity that filed nothing named.',
        $compiler,
    );

    app(SubmitConsolidationForReview::class)($report, $submitter);

    return ConsolidatedReport::query()->whereKey($report->getKey())->firstOrFail();
}
