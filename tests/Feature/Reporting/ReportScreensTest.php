<?php

/**
 * The tenant reporting screens (progress-reporting.md §6).
 *
 * These cover what the Action tests cannot: that the SCREEN shows the right
 * rows to the right person, hands the Actions well-formed input, and refuses
 * the mutation when the chain says no. Domain rules themselves are proven in
 * the Action tests — repeating them here would be theatre.
 *
 * Every screen is asserted BOTH ways: an authorized 200 that actually renders
 * the record, and the denial. A suite of denials proves only that nothing
 * works.
 */

use App\Actions\Reporting\SubmitProgressReport;
use App\Enums\ProgressReportStatus;
use App\Enums\ReportEntryMode;
use App\Enums\ReportObligationStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Reporting\RecentReportsCard;
use App\Livewire\Tenant\Reporting\ReportForm;
use App\Livewire\Tenant\Reporting\ReportIndex;
use App\Livewire\Tenant\Reporting\ReportReview;
use App\Livewire\Tenant\Reporting\UpcomingDeadlinesCard;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
        'physical_progress' => '20.00',
        'expenditure_to_date' => '10000000.00',
    ]);

    // The consultant is assigned to the project, which is what makes them a
    // consultant ON it: `visibleTo()` narrows project-level roles to their
    // active assignments, so an unassigned consultant is correctly offered
    // nothing. The unassigned case is asserted explicitly further down.
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->consultant = $this->consultant->fresh();
});

/* -------------------------------------------------------------------------- */
/* Reporting desk */
/* -------------------------------------------------------------------------- */

it('renders the reporting desk with what this workspace owes', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    Livewire::actingAs($this->officer)
        ->test(ReportIndex::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee($this->period->label);
});

it('shows a countdown on an obligation and marks a passed deadline overdue', function () {
    ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->dueIn(-3)
        ->create();

    Livewire::actingAs($this->officer)
        ->test(ReportIndex::class)
        ->assertOk()
        ->assertSee('3 days late')
        ->assertSee('Overdue');
});

it('switches to the filed returns and clears a status filter that means nothing there', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->submitted($this->consultant)
        ->create(['created_by_id' => $this->consultant->id]);

    Livewire::actingAs($this->officer)
        ->test(ReportIndex::class)
        ->set('status', ReportObligationStatus::Pending->value)
        ->set('view', 'reports')
        // `pending` is an obligation word; carried across, it would filter
        // every return away and read as "nothing filed".
        ->assertSet('status', '')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Submitted');
});

it('filters the desk by window and by project, and persists the filters in the URL', function () {
    $otherProject = Project::factory()->ongoing()->create(['title' => 'Storm Drainage Upgrade']);
    $otherPeriod = ReportingPeriod::factory()->monthly(2025, 6)->create();

    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();
    ReportObligation::factory()->forProject($otherProject)->forPeriod($otherPeriod)->create();

    // Asserted against the ROWS, not the raw HTML: the project filter is a
    // dropdown of every project this user may see, so both titles are on the
    // page whatever is filtered — as they must be for the filter to work at
    // all. assertDontSee here would be testing the option list.
    $component = Livewire::withQueryParams(['period' => (string) $this->period->id])
        ->actingAs($this->officer)
        ->test(ReportIndex::class)
        ->assertSet('periodId', (string) $this->period->id);

    expect($component->instance()->obligations->pluck('project.title')->all())
        ->toBe(['Township Road Rehabilitation']);

    $component->set('periodId', '')->set('projectId', (string) $otherProject->id);

    expect($component->instance()->obligations->pluck('project.title')->all())
        ->toBe(['Storm Drainage Upgrade']);
});

it('narrows the desk to their own projects for a consultant', function () {
    $unassigned = Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);

    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();
    ReportObligation::factory()->forProject($unassigned)->forPeriod($this->period)->create();

    Livewire::actingAs($this->consultant->fresh())
        ->test(ReportIndex::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Unassigned Bridge Works');
});

it('shows one workspace nothing of another workspace’s reporting', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    app(CurrentTenant::class)->runAs($health, function () {
        $project = Project::factory()->ongoing()->create(['title' => 'Maternity Wing Expansion']);
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();
    });

    actingOnTenant($this->works);
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    Livewire::actingAs($this->officer)
        ->test(ReportIndex::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Maternity Wing Expansion');
});

it('denies the desk to a workspace user with no reporting permissions at all', function () {
    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    setPermissionsTeamId($this->works->id);
    $stranger->roles()->detach();
    $stranger->forgetCachedPermissions();

    Livewire::actingAs($stranger)
        ->test(ReportIndex::class)
        ->assertForbidden();
});

it('serves /reports over the real subdomain route', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation');
});

/* -------------------------------------------------------------------------- */
/* The wizard */
/* -------------------------------------------------------------------------- */

it('files a return through all four wizard steps', function () {
    $obligation = ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        // Step 1 — window & project
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        // Step 2 — the figures
        ->set('narrative_work_done', 'Sub-base laid across 1.2 km and three culverts cast to specification.')
        ->set('physical_progress_claimed', '38.50')
        ->set('period_expenditure', '4500000')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 3)
        // Step 3 — the story
        ->set('narrative_challenges', 'Two weeks lost to unseasonal rainfall.')
        ->set('narrative_mitigation', 'Night shifts approved to recover the schedule.')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 4)
        // Step 4 — file it
        ->call('submit')
        ->assertHasNoErrors();

    $report = ProgressReport::query()->where('project_id', $this->project->id)->firstOrFail();

    expect($report->status)->toBe(ProgressReportStatus::Submitted)
        ->and($report->physical_progress_claimed)->toBe('38.50')
        ->and($report->period_expenditure->toDecimalString())->toBe('4500000.00')
        ->and($report->submitted_by_id)->toBe($this->consultant->id)
        ->and($report->entry_mode)->toBe(ReportEntryMode::SelfService)
        // The Action fulfilled the obligation and wrote the ledger row — not
        // the component.
        ->and($obligation->fresh()->status)->toBe(ReportObligationStatus::Fulfilled)
        ->and($report->events()->count())->toBe(1);
});

it('refuses to advance past the figures step without a usable narrative', function () {
    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        ->set('narrative_work_done', 'ongoing')
        ->set('physical_progress_claimed', '38.50')
        ->set('period_expenditure', '4500000')
        ->call('next')
        ->assertHasErrors('narrative_work_done')
        ->assertSet('step', 2);
});

it('demands a reason before a claim takes the project record backwards', function () {
    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        ->set('narrative_work_done', 'Defective culvert removed after inspection and the section re-measured.')
        // The project stands at 20%.
        ->set('physical_progress_claimed', '12.00')
        ->set('period_expenditure', '0')
        ->call('next')
        ->assertHasErrors('progress_decrease_reason')
        ->assertSet('step', 2)
        ->set('progress_decrease_reason', 'Defective culvert removed; section re-measured after inspection.')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 3);
});

it('refuses a percentage no percentage can take', function () {
    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        ->set('narrative_work_done', 'Sub-base laid across 1.2 km and three culverts cast to specification.')
        ->set('physical_progress_claimed', '140')
        ->set('period_expenditure', '1000')
        ->call('next')
        ->assertHasErrors('physical_progress_claimed');
});

it('autosaves the draft as the author types, without them asking', function () {
    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        // A plain `set` is what a keystroke looks like to Livewire — no
        // explicit save call anywhere in this test.
        ->set('narrative_work_done', 'Earthworks completed on the northern section.')
        ->assertNotSet('savedAt', null);

    $report = ProgressReport::query()->where('project_id', $this->project->id)->firstOrFail();

    expect($report->narrative_work_done)->toBe('Earthworks completed on the northern section.')
        ->and($report->autosaved_at)->not->toBeNull()
        // Still a draft: autosave stores, it does not file.
        ->and($report->status)->toBe(ProgressReportStatus::Draft);
});

it('returns the same draft rather than starting a second return for one window', function () {
    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start');

    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        ->assertSet('step', 2);

    expect(ProgressReport::query()->where('project_id', $this->project->id)->count())->toBe(1);
});

it('offers a consultant only the projects they are assigned to', function () {
    Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);

    $component = Livewire::actingAs($this->consultant->fresh())->test(ReportForm::class)->assertOk();

    expect($component->instance()->projects->pluck('title')->all())
        ->toBe(['Township Road Rehabilitation']);

    // And the option list is the constraint: naming an unoffered project in
    // the payload is refused rather than accepted.
    $component
        ->set('projectUlid', Project::query()->where('title', 'Unassigned Bridge Works')->value('ulid'))
        ->set('periodId', (string) $this->period->id)
        ->call('start')
        ->assertHasErrors('projectUlid')
        ->assertSet('step', 1);
});

it('resumes an existing draft from the edit route', function () {
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create([
            'created_by_id' => $this->consultant->id,
            'narrative_work_done' => 'Half-written account of the works.',
        ]);

    Livewire::actingAs($this->consultant)
        ->test(ReportForm::class, ['report' => $report])
        ->assertOk()
        ->assertSet('step', 2)
        ->assertSet('narrative_work_done', 'Half-written account of the works.');
});

it('denies the wizard to a role that cannot create returns', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    Livewire::actingAs($monitor)
        ->test(ReportForm::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Review screen */
/* -------------------------------------------------------------------------- */

/** A filed return waiting for the focal officer. */
function filedReport(): ProgressReport
{
    $report = ProgressReport::factory()
        ->forProject(test()->project)
        ->forPeriod(test()->period)
        ->create([
            'created_by_id' => test()->consultant->id,
            'physical_progress_claimed' => '38.50',
            'period_expenditure' => '4500000.00',
        ]);

    app(SubmitProgressReport::class)($report, test()->consultant);

    return $report->fresh();
}

it('renders a filed return with its figures and its narrative', function () {
    $report = filedReport();

    Livewire::actingAs($this->officer)
        ->test(ReportReview::class, ['report' => $report])
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee($this->period->label)
        // The progress component trims the trailing zero it has no use for.
        ->assertSee('38.5')
        ->assertSee('₦4,500,000.00')
        ->assertSee('Submitted');
});

it('reviews then approves a return, and moves the project figures once', function () {
    $report = filedReport();

    Livewire::actingAs($this->officer)
        ->test(ReportReview::class, ['report' => $report])
        ->assertOk()
        ->call('review')
        ->assertHasNoErrors();

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Reviewed)
        // Review moves nothing.
        ->and($this->project->fresh()->physical_progress)->toBe('20.00');

    Livewire::actingAs($this->admin)
        ->test(ReportReview::class, ['report' => $report->fresh()])
        ->call('approve')
        ->assertHasNoErrors();

    $project = $this->project->fresh();

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Approved)
        ->and($project->physical_progress)->toBe('38.50')
        ->and($project->expenditure_to_date->toDecimalString())->toBe('14500000.00');
});

it('sends a return back with a reason, and refuses to send one back without', function () {
    $report = filedReport();

    Livewire::actingAs($this->officer)
        ->test(ReportReview::class, ['report' => $report])
        ->call('startReturn')
        ->assertSet('returning', true)
        ->set('returnReason', '')
        ->call('confirmReturn')
        ->assertHasErrors('returnReason');

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Submitted);

    Livewire::actingAs($this->officer)
        ->test(ReportReview::class, ['report' => $report->fresh()])
        ->call('startReturn')
        ->set('returnReason', 'Reported expenditure does not reconcile with the attached valuation certificate.')
        ->call('confirmReturn')
        ->assertHasNoErrors();

    $fresh = $report->fresh();

    expect($fresh->status)->toBe(ProgressReportStatus::Returned)
        ->and($fresh->return_reason)->toContain('valuation certificate');
});

it('never offers the reviewer button to the officer who filed the return', function () {
    // The on-behalf path: the officer typed the contractor's numbers.
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->onBehalf()
        ->create([
            'created_by_id' => $this->officer->id,
            // Above the project's recorded 20%, so the submission is not
            // refused for an unexplained downward revision — this test is
            // about who may clear the return, not about the figures.
            'physical_progress_claimed' => '42.00',
        ]);

    app(SubmitProgressReport::class)($report, $this->officer);

    $component = Livewire::actingAs($this->officer)
        ->test(ReportReview::class, ['report' => $report->fresh()])
        ->assertOk();

    expect($component->instance()->canReview())->toBeFalse();

    $component
        ->assertSee('You filed this return, so you cannot also clear it')
        ->assertDontSee('Mark as reviewed');

    // …and calling it anyway is refused by the chokepoint, not by the button.
    $component->call('review');

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Submitted)
        ->and($component->instance()->failure)->toContain('cannot review it');
});

it('never offers approval to the director who reviewed it', function () {
    $report = filedReport();

    Livewire::actingAs($this->admin)
        ->test(ReportReview::class, ['report' => $report])
        ->call('review')
        ->assertHasNoErrors();

    $component = Livewire::actingAs($this->admin)
        ->test(ReportReview::class, ['report' => $report->fresh()])
        ->assertOk();

    expect($component->instance()->canApprove())->toBeFalse();

    $component->assertSee('approval needs someone else');

    $component->call('approve');

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Reviewed)
        ->and($component->instance()->failure)->toContain('cannot also approve it');
});

it('offers a consultant no decision at all on their own return', function () {
    $report = filedReport();

    $component = Livewire::actingAs($this->consultant->fresh())
        ->test(ReportReview::class, ['report' => $report])
        ->assertOk();

    expect($component->instance()->canReview())->toBeFalse()
        ->and($component->instance()->canApprove())->toBeFalse()
        ->and($component->instance()->canReturn())->toBeFalse();

    $component->call('review')->assertForbidden();
});

it('shows the whole approval chain, in order, with who signed each step', function () {
    $report = filedReport();

    Livewire::actingAs($this->officer)->test(ReportReview::class, ['report' => $report])->call('review');
    Livewire::actingAs($this->admin)->test(ReportReview::class, ['report' => $report->fresh()])->call('approve');

    $component = Livewire::actingAs($this->admin)
        ->test(ReportReview::class, ['report' => $report->fresh()])
        ->assertOk();

    expect($component->instance()->chain->pluck('to_status')->map->value->all())
        ->toBe(['submitted', 'reviewed', 'approved']);

    $component
        ->assertSee($this->consultant->name)
        ->assertSee($this->officer->name)
        ->assertSee($this->admin->name);
});

it('404s when one workspace opens another workspace’s return by ulid', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $foreign = app(CurrentTenant::class)->runAs($health, fn (): ProgressReport => ProgressReport::factory()
        ->forProject(Project::factory()->ongoing()->create())
        ->create());

    actingOnTenant($this->works);

    // Through the route, because the route-model binder is what applies the scope.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/'.$foreign->ulid))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* Dashboard widgets */
/* -------------------------------------------------------------------------- */

it('renders the recent-reports widget with the latest returns', function () {
    filedReport();

    Livewire::actingAs($this->officer)
        ->test(RecentReportsCard::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Submitted');
});

it('renders an empty recent-reports widget without a blank panel', function () {
    Livewire::actingAs($this->officer)
        ->test(RecentReportsCard::class)
        ->assertOk()
        ->assertSee('No returns filed yet');
});

it('renders the deadlines widget with a countdown, overdue rows first', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->dueIn(-2)->create();

    $soon = Project::factory()->ongoing()->create(['title' => 'Storm Drainage Upgrade']);
    ReportObligation::factory()->forProject($soon)->forPeriod($this->period)->dueIn(5)->create();

    $component = Livewire::actingAs($this->officer)
        ->test(UpcomingDeadlinesCard::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Storm Drainage Upgrade')
        ->assertSee('Overdue')
        ->assertSee('5 days left');

    // A dashboard that quietly drops what you already failed to file is how an
    // MDA reaches the compliance board without warning.
    expect($component->instance()->obligations->first()->project->title)
        ->toBe('Township Road Rehabilitation');
});

it('leaves a far-off deadline off the dashboard widget', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->dueIn(90)->create();

    Livewire::actingAs($this->officer)
        ->test(UpcomingDeadlinesCard::class)
        ->assertOk()
        ->assertSee('Nothing due in the next 30 days');
});

it('shows a consultant only their own work on the dashboard widgets', function () {
    $unassigned = Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);
    ReportObligation::factory()->forProject($unassigned)->forPeriod($this->period)->dueIn(3)->create();

    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->dueIn(3)->create();

    Livewire::actingAs($this->consultant->fresh())
        ->test(UpcomingDeadlinesCard::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Unassigned Bridge Works');
});

it('serves the dashboard with both widgets mounted', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    // The widgets are LAZY, so the first paint carries their placeholders and
    // their component tags — not their content. Asserting the tags is what
    // actually proves the dashboard mounts them; asserting a card title would
    // pass just as well if the widget were rendered eagerly, which is the
    // thing this test exists to prevent.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/'))
        ->assertOk()
        ->assertSeeLivewire(RecentReportsCard::class)
        ->assertSeeLivewire(UpcomingDeadlinesCard::class);
});

it('links the workspace sidebar at the real reporting route', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/'))
        ->assertOk()
        ->assertSee(tenantUrl($this->works, '/reports'));
});
