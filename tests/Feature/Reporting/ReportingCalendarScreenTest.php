<?php

/**
 * The M&E calendar (progress-reporting.md §6).
 *
 * The deadline engine tests already prove the dates are computed correctly.
 * What is unproven until here is that the SCREEN reads those dates rather than
 * inventing its own — the failure mode is a calendar that says "due in 3 days"
 * while the reminder ladder is sending the 2-day notice, which destroys trust
 * in both — and that one workspace's calendar never shows another's record.
 *
 * Every date assertion is anchored with Carbon::setTestNow(); no sleeping, no
 * real clocks.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Reporting\ReportingCalendar;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    // A fixed Monday mid-month: every window and countdown below is anchored
    // to it, so the assertions mean the same thing in any month of any year.
    Carbon::setTestNow(Carbon::parse('2026-03-16 09:00:00'));

    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->february = ReportingPeriod::factory()->monthly(2026, 2)->create();
    $this->march = ReportingPeriod::factory()->monthly(2026, 3)->create();
    $this->quarter = ReportingPeriod::factory()->quarterly(2026, 1)->create();

    $this->roads = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    $this->bridge = Project::factory()->ongoing()->create([
        'title' => 'Oke-Ado Bridge Repairs',
        'reference' => 'WKS/2026/002',
    ]);

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->roads->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->consultant = User::query()->whereKey($this->consultant->id)->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* The route reaches the screen */
/* -------------------------------------------------------------------------- */

it('dispatches /calendar to the reporting calendar', function () {
    // Dispatch, not registration: only the component a real request renders
    // proves the route is wired to the screen people will actually open.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/calendar'))
        ->assertOk()
        ->assertSeeLivewire(ReportingCalendar::class)
        ->assertSee('March 2026');
});

/* -------------------------------------------------------------------------- */
/* What the calendar says about each window */
/* -------------------------------------------------------------------------- */

it('counts met, due, overdue and waived obligations for each window', function () {
    // February closed with one filed and one never filed; March is live.
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->february)->fulfilled()->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->february)->missed()->create();

    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->dueIn(5)->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->march)->dueIn(-2)->create();

    $quarterly = ReportObligation::factory()->forProject($this->roads)->forPeriod($this->quarter)->waived()->create();

    $calendar = Livewire::actingAs($this->officer)->test(ReportingCalendar::class)->instance();

    expect($calendar->countsFor($this->february))
        ->toBe(['expected' => 2, 'met' => 1, 'due' => 0, 'overdue' => 0, 'waived' => 0, 'missed' => 1])
        ->and($calendar->countsFor($this->march))
        ->toBe(['expected' => 2, 'met' => 0, 'due' => 1, 'overdue' => 1, 'waived' => 0, 'missed' => 0])
        ->and($calendar->countsFor($quarterly->reportingPeriod))
        ->toBe(['expected' => 1, 'met' => 0, 'due' => 0, 'overdue' => 0, 'waived' => 1, 'missed' => 0]);
});

it('reads the “due soon” threshold from the configured reminder ladder, not a literal', function () {
    // An instance that starts chasing ten days out must see a ten-day window
    // on the screen — the ladder and the calendar are the same policy.
    config()->set('platform.reporting.reminder_days_before', [10, 2]);

    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->dueIn(8)->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->march)->dueIn(20)->create();

    $component = Livewire::actingAs($this->officer)->test(ReportingCalendar::class);

    expect($component->instance()->leadDays())->toBe(10)
        ->and($component->instance()->stats['due_soon'])->toBe(1);

    $component->assertSee('Due within 10 days');
});

it('counts the deadline in whole days on the instance clock, as the reminder ladder does', function () {
    $obligation = ReportObligation::factory()
        ->forProject($this->roads)
        ->forPeriod($this->march)
        ->dueIn(-3)
        ->create();

    // The screen prints exactly what ReportObligation::daysToDue() answers —
    // one definition, shared with the engine.
    expect($obligation->daysToDue())->toBe(-3);

    Livewire::actingAs($this->officer)
        ->test(ReportingCalendar::class)
        ->call('showPeriod', $this->march->id)
        ->assertSee('3 days late');
});

it('labels a window whose deadline has passed as past its deadline', function () {
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->february)->missed()->create();

    $component = Livewire::actingAs($this->officer)->test(ReportingCalendar::class);

    expect($component->instance()->windowState($this->february))->toBe('overdue')
        ->and($component->instance()->windowState($this->march))->toBe('open');
});

/* -------------------------------------------------------------------------- */
/* Switching windows, and the per-project drill-down */
/* -------------------------------------------------------------------------- */

it('opens the most recent window by default and moves between windows on demand', function () {
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->february)->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->march)->create();

    $component = Livewire::actingAs($this->officer)->test(ReportingCalendar::class);

    // Newest first: March, with February as the earlier window.
    expect($component->instance()->period->id)->toBe($this->march->id)
        ->and($component->instance()->previousPeriod->id)->toBe($this->february->id);

    $component->call('showPeriod', $this->february->id);

    expect($component->instance()->period->id)->toBe($this->february->id)
        ->and($component->instance()->obligations->pluck('project_id')->all())->toBe([$this->roads->id]);
});

it('lists what each project owes for the window on screen', function () {
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->march)->create();

    Livewire::actingAs($this->officer)
        ->test(ReportingCalendar::class)
        ->call('showPeriod', $this->march->id)
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Oke-Ado Bridge Repairs');
});

it('links a filed obligation to its return rather than to a new one', function () {
    $report = ProgressReport::factory()
        ->forProject($this->roads)
        ->forPeriod($this->march)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $obligation = ReportObligation::factory()
        ->forProject($this->roads)
        ->forPeriod($this->march)
        ->fulfilled()
        ->create(['progress_report_id' => $report->id]);

    expect($obligation->progress_report_id)->toBe($report->id);

    Livewire::actingAs($this->officer)
        ->test(ReportingCalendar::class)
        ->call('showPeriod', $this->march->id)
        ->assertSee(tenantUrl($this->works, '/reports/'.$report->ulid), escape: false);
});

it('narrows the window detail to one project without hiding the calendar', function () {
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->march)->create();

    $component = Livewire::actingAs($this->officer)
        ->test(ReportingCalendar::class)
        ->set('projectUlid', $this->bridge->ulid);

    expect($component->instance()->obligations->total())->toBe(1)
        // The calendar row still reports what the whole entity owes.
        ->and($component->instance()->countsFor($this->march)['expected'])->toBe(2);
});

it('filters the calendar to one reporting rhythm and forgets a window from another', function () {
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->create();
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->quarter)->create();

    $component = Livewire::actingAs($this->officer)
        ->test(ReportingCalendar::class)
        ->call('showPeriod', $this->quarter->id)
        ->set('cadence', 'monthly');

    expect($component->instance()->periods->map(fn ($window) => $window->cadence->value)->unique()->values()->all())->toBe(['monthly'])
        // The chosen quarterly window belongs to the old filter — keeping it
        // would show an empty drill-down under a populated calendar.
        ->and($component->get('periodId'))->toBe('')
        ->and($component->instance()->period->id)->toBe($this->march->id);
});

/* -------------------------------------------------------------------------- */
/* Who sees what */
/* -------------------------------------------------------------------------- */

it('shows a consultant only the projects they are assigned to', function () {
    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->create();
    ReportObligation::factory()->forProject($this->bridge)->forPeriod($this->march)->create();

    $component = Livewire::actingAs($this->consultant)->test(ReportingCalendar::class);

    expect($component->instance()->countsFor($this->march)['expected'])->toBe(1)
        ->and($component->instance()->obligations->pluck('project_id')->all())->toBe([$this->roads->id]);

    $component->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Oke-Ado Bridge Repairs');
});

it('renders an honest empty calendar before any window has opened', function () {
    ReportingPeriod::query()->delete();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/calendar'))
        ->assertOk()
        ->assertSee('No reporting window has opened yet');
});

/* -------------------------------------------------------------------------- */
/* Tenancy isolation */
/* -------------------------------------------------------------------------- */

it('never shows one entity’s obligations on another entity’s calendar', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $current = app(CurrentTenant::class);

    $current->runAs($health, function () use ($health) {
        $this->healthOfficer = memberOf(User::factory()->create(), $health, Role::MeOfficer);

        $clinic = Project::factory()->ongoing()->create(['title' => 'Cottage Hospital Rewiring']);

        ReportObligation::factory()->forProject($clinic)->forPeriod($this->march)->create();
        ReportObligation::factory()->forProject($clinic)->forPeriod($this->march)->missed()->create();
    });

    $current->set($this->works);

    ReportObligation::factory()->forProject($this->roads)->forPeriod($this->march)->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/calendar'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Cottage Hospital Rewiring');

    expect(Livewire::actingAs($this->officer)->test(ReportingCalendar::class)->instance()->countsFor($this->march)['expected'])
        ->toBe(1);

    $current->set($health);

    $this->actingAs($this->healthOfficer)
        ->get(tenantUrl($health, '/calendar'))
        ->assertOk()
        ->assertSee('Cottage Hospital Rewiring')
        ->assertDontSee('Township Road Rehabilitation');
});

it('refuses the calendar to a signed-in user with no membership of this workspace', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);

    $this->actingAs($stateAdmin)
        ->get(tenantUrl($this->works, '/calendar'))
        ->assertForbidden();
});
