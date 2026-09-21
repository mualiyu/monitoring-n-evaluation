<?php

/**
 * The two scheduled sweeps behind the monitoring lifecycle: the proposer that
 * puts a routine visit in the diary when a project has gone too long without
 * one, and the sweep that chases a visit whose Field Trip Report never
 * arrived.
 *
 * IDEMPOTENCY IS THE PROPERTY UNDER TEST. Both run daily from the scheduler,
 * both can be re-run after a failure, and both are gated structurally — a
 * deterministic `schedule_key` behind a unique index, and a stamp written
 * under a row lock in the same transaction as the dispatch. So every case runs
 * the sweep TWICE and asserts the second run is completely silent: an
 * inspector who gets the same message every morning learns to filter this
 * sender, and then misses the one that mattered.
 *
 * Every statutory number is read from config in the test the way the Action
 * reads it from the SettingsRepository — `inspections.routine_interval_months`
 * and `inspections.report_due_days` are policy, not constants, and a test that
 * hard-codes them passes against a platform that has stopped honouring them.
 *
 * The clock is fixed with Carbon::setTestNow(): this is all deadline
 * arithmetic, and a suite that reads the wall clock fails at midnight.
 */

use App\Actions\Inspections\FlagOverdueInspectionReports;
use App\Actions\Inspections\ProposeRoutineInspections;
use App\Actions\Inspections\StartInspection;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Jobs\Inspections\NotifyInspectionReportOverdue;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Inspections\InspectionReportOverdue;
use App\Notifications\Inspections\InspectionScheduled;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 05:30:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $this->propose = app(ProposeRoutineInspections::class);
    $this->flag = app(FlagOverdueInspectionReports::class);
    $this->settings = app(SettingsRepository::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A project under execution with somebody on it who can actually inspect. */
function inspectableProject(User $inspector, User $assigner, ProjectStatus $status = ProjectStatus::InProgress): Project
{
    $project = Project::factory()->state(['status' => $status])->create();

    ProjectAssignment::factory()->fieldMonitor()->create([
        'project_id' => $project->id,
        'user_id' => $inspector->id,
        'assigned_by_id' => $assigner->id,
    ]);

    return $project;
}

/* -------------------------------------------------------------------------- */
/* The routine proposer */
/* -------------------------------------------------------------------------- */

it('proposes a routine visit for a project that has never been inspected, and only once', function () {
    $project = inspectableProject($this->monitor, $this->admin);

    expect(($this->propose)())->toBe(1);

    $inspection = SiteInspection::query()->sole();

    expect($inspection->type)->toBe(InspectionType::Routine)
        ->and($inspection->status)->toBe(InspectionStatus::Scheduled)
        ->and($inspection->lead_inspector_id)->toBe($this->monitor->id)
        ->and($inspection->isProposed())->toBeTrue()
        // Keyed by the DUE MONTH, not the run date: a sweep that fails on the
        // 1st and is re-run on the 3rd proposes the same visit, not a second.
        ->and($inspection->schedule_key)->toBe('routine:'.Carbon::now()->format('Y-m'))
        // Proposed for today — the interval has already elapsed, so the visit
        // is owed now. Dating it forward would understate how overdue it is.
        ->and($inspection->scheduled_date->toDateString())->toBe(Carbon::now()->toDateString());

    Notification::assertSentToTimes($this->monitor, InspectionScheduled::class, 1);

    // The second run, the same morning: nothing new, nothing said.
    expect(($this->propose)())->toBe(0)
        ->and(SiteInspection::query()->count())->toBe(1);

    Notification::assertSentToTimes($this->monitor, InspectionScheduled::class, 1);
});

it('is silent when run twice in the same minute, as an overlapping worker would', function () {
    inspectableProject($this->monitor, $this->admin);

    $first = ($this->propose)();
    $second = ($this->propose)();
    $third = ($this->propose)();

    expect([$first, $second, $third])->toBe([1, 0, 0])
        ->and(SiteInspection::query()->count())->toBe(1);
});

it('proposes for a mobilized project too — there is a contractor on the ground', function () {
    inspectableProject($this->monitor, $this->admin, ProjectStatus::Mobilized);

    expect(($this->propose)())->toBe(1);
});

it('leaves alone a project that is not under execution', function (ProjectStatus $status) {
    inspectableProject($this->monitor, $this->admin, $status);

    expect(($this->propose)())->toBe(0)
        ->and(SiteInspection::query()->count())->toBe(0);
})->with([
    'draft' => [ProjectStatus::Draft],
    'awarded but not mobilized' => [ProjectStatus::Awarded],
    // Completed awaits its FINAL inspection, which is event-triggered.
    'completed' => [ProjectStatus::Completed],
    'suspended' => [ProjectStatus::Suspended],
    'cancelled' => [ProjectStatus::Cancelled],
]);

it('proposes nothing while a visit is already open in the diary', function () {
    $project = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()->forProject($project)->ledBy($this->monitor)->scheduled()->create();

    expect(($this->propose)())->toBe(0)
        ->and(SiteInspection::query()->count())->toBe(1);
});

it('proposes nothing while a visit inside the interval has already happened', function () {
    $interval = $this->settings->int('inspections', 'routine_interval_months', 1);

    $project = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create(['conducted_at' => Carbon::now()->subMonths($interval)->addDay()]);

    expect(($this->propose)())->toBe(0);

    // A day past the interval, and the project is owed a visit again.
    Carbon::setTestNow(CarbonImmutable::now()->addDays(2));

    expect(($this->propose)())->toBe(1);
});

it('honours a longer monitoring interval set for the instance', function () {
    config()->set('platform.inspections.routine_interval_months', 6);

    expect($this->settings->int('inspections', 'routine_interval_months', 1))->toBe(6);

    $project = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create(['conducted_at' => Carbon::now()->subMonths(3)]);

    // Three months ago is inside a six-month interval.
    expect(($this->propose)())->toBe(0);

    Carbon::setTestNow(CarbonImmutable::now()->addMonths(4));

    expect(($this->propose)())->toBe(1);
});

it('lets a state that plans its own field work turn routine proposals off entirely', function () {
    config()->set('platform.inspections.routine_interval_months', 0);

    inspectableProject($this->monitor, $this->admin);

    expect(($this->propose)())->toBe(0)
        ->and(SiteInspection::query()->count())->toBe(0);
});

it('proposes nothing for a project with nobody who could conduct a visit', function () {
    // A proposal addressed to nobody in particular is an overdue report a
    // fortnight later.
    $project = Project::factory()->ongoing()->create();

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $project->id,
        'user_id' => memberOf(User::factory()->create(), $this->works, Role::Consultant)->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    expect(($this->propose)())->toBe(0);
});

it('assigns the proposal to the project manager when they can inspect', function () {
    Project::factory()->ongoing()->managedBy($this->officer)->create();

    expect(($this->propose)())->toBe(1)
        ->and(SiteInspection::query()->sole()->lead_inspector_id)->toBe($this->officer->id);
});

it('attaches the state’s instrument for the project’s sector, preferring the specific one', function () {
    $project = inspectableProject($this->monitor, $this->admin);

    $general = InspectionChecklistTemplate::factory()->create(['name' => 'General site monitoring checklist']);

    $sectorSpecific = InspectionChecklistTemplate::factory()
        ->forType(InspectionType::Routine)
        ->forSector($project->sector_id)
        ->create(['name' => 'Road works monitoring checklist']);

    expect($general->id)->toBeLessThan($sectorSpecific->id);

    expect(($this->propose)())->toBe(1)
        ->and(SiteInspection::query()->sole()->inspection_checklist_template_id)
        ->toBe($sectorSpecific->id);
});

it('proposes a fresh visit in the next window without disturbing the last one', function () {
    inspectableProject($this->monitor, $this->admin);

    expect(($this->propose)())->toBe(1);

    $first = SiteInspection::query()->sole();

    // The proposal is cancelled, a month passes, and the project is owed
    // another — a new key, so a new row rather than a silent no-op.
    $first->forceFill([
        'status' => InspectionStatus::Cancelled,
        'cancelled_at' => Carbon::now(),
        'cancellation_reason' => 'Access road impassable after three days of rain.',
    ])->save();

    Carbon::setTestNow(CarbonImmutable::now()->addMonth());

    expect(($this->propose)())->toBe(1)
        ->and(SiteInspection::query()->count())->toBe(2)
        ->and(SiteInspection::query()->orderByDesc('id')->first()->schedule_key)
        ->toBe('routine:'.Carbon::now()->format('Y-m'));
});

it('carries tenancy into every workspace it sweeps', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    inspectableProject($this->monitor, $this->admin);

    app(CurrentTenant::class)->runAs($health, function () use ($health): void {
        $healthAdmin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);
        $healthMonitor = memberOf(User::factory()->create(), $health, Role::FieldMonitor);

        inspectableProject($healthMonitor, $healthAdmin);
    });

    expect(($this->propose)())->toBe(2);

    // Each proposal landed in its own workspace, with its own tenant stamp.
    app(CurrentTenant::class)->runAs($this->works, function (): void {
        expect(SiteInspection::query()->count())->toBe(1)
            ->and(SiteInspection::query()->sole()->tenant_id)->toBe($this->works->id);
    });

    app(CurrentTenant::class)->runAs($health, function () use ($health): void {
        expect(SiteInspection::query()->count())->toBe(1)
            ->and(SiteInspection::query()->sole()->tenant_id)->toBe($health->id);
    });
});

it('skips a deactivated workspace altogether', function () {
    inspectableProject($this->monitor, $this->admin);

    $this->works->forceFill(['is_active' => false])->save();

    expect(($this->propose)())->toBe(0);
});

it('runs from the scheduled console command, twice, without doubling anything', function () {
    inspectableProject($this->monitor, $this->admin);

    $this->artisan('inspections:propose-routine')
        ->expectsOutputToContain('1 routine inspection(s) proposed.')
        ->assertSuccessful();

    $this->artisan('inspections:propose-routine')
        ->expectsOutputToContain('0 routine inspection(s) proposed.')
        ->assertSuccessful();

    expect(SiteInspection::query()->count())->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* The outstanding-report sweep */
/* -------------------------------------------------------------------------- */

it('chases a visit whose report never arrived, exactly once ever', function () {
    $dueDays = $this->settings->int('inspections', 'report_due_days', 3);

    $project = inspectableProject($this->monitor, $this->admin);

    $inspection = SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);

    // Inside the window: nothing is owed yet.
    expect(($this->flag)())->toBe(0);

    Notification::assertNothingSent();

    // A day past the snapshotted deadline.
    Carbon::setTestNow(CarbonImmutable::now()->addDays($dueDays + 1));

    expect(($this->flag)())->toBe(1);

    Notification::assertSentToTimes($this->monitor, InspectionReportOverdue::class, 1);

    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('report_overdue_notified_at'))
        ->not->toBeNull();

    // The second run, and a run tomorrow, and the day after: silent. The
    // standing signal lives on the board, not in an inbox.
    expect(($this->flag)())->toBe(0);

    Carbon::setTestNow(CarbonImmutable::now()->addDay());

    expect(($this->flag)())->toBe(0);

    Notification::assertSentToTimes($this->monitor, InspectionReportOverdue::class, 1);
});

it('says nothing about a visit whose report has been filed', function () {
    $project = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create(['report_due_at' => Carbon::now()->subDays(5)]);

    expect(($this->flag)())->toBe(0);

    Notification::assertNothingSent();
});

it('says nothing about a visit that is still only in the diary', function () {
    // A scheduled visit has no report_due_at at all: the clock starts when the
    // inspector opens the form, not when somebody planned the trip.
    $project = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now()->addDays(2))
        ->create();

    expect(($this->flag)())->toBe(0);
});

it('honours a longer reporting window set for the instance', function () {
    config()->set('platform.inspections.report_due_days', 10);

    $project = inspectableProject($this->monitor, $this->admin);

    $inspection = SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);

    Carbon::setTestNow(CarbonImmutable::now()->addDays(5));

    expect(($this->flag)())->toBe(0);

    Carbon::setTestNow(CarbonImmutable::now()->addDays(6));

    expect(($this->flag)())->toBe(1);
});

it('does not retroactively rescue a report the state has since given longer for', function () {
    $dueDays = $this->settings->int('inspections', 'report_due_days', 3);

    $project = inspectableProject($this->monitor, $this->admin);

    $inspection = SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->scheduled(CarbonImmutable::now())
        ->create();

    app(StartInspection::class)($inspection, $this->monitor);

    // report_due_at is a stored snapshot taken at the visit.
    config()->set('platform.inspections.report_due_days', 30);

    Carbon::setTestNow(CarbonImmutable::now()->addDays($dueDays + 1));

    expect(($this->flag)())->toBe(1);
});

it('carries tenancy into every workspace it chases', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $worksProject = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()
        ->forProject($worksProject)
        ->ledBy($this->monitor)
        ->reportOverdue()
        ->create();

    $healthMonitor = app(CurrentTenant::class)->runAs($health, function () use ($health): User {
        $healthAdmin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);
        $inspector = memberOf(User::factory()->create(), $health, Role::FieldMonitor);

        $project = inspectableProject($inspector, $healthAdmin);

        SiteInspection::factory()->forProject($project)->ledBy($inspector)->reportOverdue()->create();

        return $inspector;
    });

    expect(($this->flag)())->toBe(2);

    Notification::assertSentToTimes($this->monitor, InspectionReportOverdue::class, 1);
    Notification::assertSentToTimes($healthMonitor, InspectionReportOverdue::class, 1);
});

it('says nothing about a report filed between the sweep and the worker', function () {
    $project = inspectableProject($this->monitor, $this->admin);

    $inspection = SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->reportOverdue()
        ->create();

    // The job re-reads the row: by the time it runs, the inspector has filed.
    $inspection->forceFill([
        'status' => InspectionStatus::Submitted,
        'submitted_by_id' => $this->monitor->id,
        'submitted_at' => Carbon::now(),
    ])->save();

    (new NotifyInspectionReportOverdue($inspection->id))->handle();

    Notification::assertNothingSent();
});

it('runs from the scheduled console command, twice, without chasing twice', function () {
    $project = inspectableProject($this->monitor, $this->admin);

    SiteInspection::factory()
        ->forProject($project)
        ->ledBy($this->monitor)
        ->reportOverdue()
        ->create();

    $this->artisan('inspections:flag-overdue')
        ->expectsOutputToContain('1 overdue inspection report(s) flagged.')
        ->assertSuccessful();

    $this->artisan('inspections:flag-overdue')
        ->expectsOutputToContain('0 overdue inspection report(s) flagged.')
        ->assertSuccessful();

    Notification::assertSentToTimes($this->monitor, InspectionReportOverdue::class, 1);
});
