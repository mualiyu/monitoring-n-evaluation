<?php

/**
 * Lateness and the compliance consequences of it (progress-reporting.md §1.3,
 * §3, §9.5–9.6).
 *
 * Three claims, each of which a state will eventually be argued with about:
 *   - a return filed after its deadline is FLAGGED, not refused,
 *   - the deadline a return is judged against is snapshotted at creation, so a
 *     later calendar edit cannot retroactively make a filed report late,
 *   - on-time is judged at FIRST submission, so a report returned for rework
 *     and resubmitted after the deadline stays as on-time as it was filed.
 */

use App\Actions\Reporting\CloseReportingPeriods;
use App\Actions\Reporting\ReturnProgressReport;
use App\Actions\Reporting\SubmitProgressReport;
use App\Enums\ReportObligationStatus;
use App\Enums\Role;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow('2026-04-10 10:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create(['physical_progress' => '20.00']);

    // March 2026: the window closed on 31 March and the return was due on
    // 7 April — three days ago on the frozen clock.
    $this->period = ReportingPeriod::factory()->monthly(2026, 3)->create();

    $this->submit = app(SubmitProgressReport::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('accepts a late return and flags it, rather than refusing it', function () {
    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    $report = ProgressReport::factory()
        ->forObligation($obligation)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->consultant);

    expect($report->fresh()->submitted_late)->toBeTrue()
        ->and($obligation->fresh()->status)->toBe(ReportObligationStatus::Fulfilled)
        ->and($obligation->fresh()->submitted_late)->toBeTrue();
});

it('flags nothing when the return is filed inside the window', function () {
    Carbon::setTestNow('2026-04-05 10:00:00');   // two days before the deadline

    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    $report = ProgressReport::factory()
        ->forObligation($obligation)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->consultant);

    expect($report->fresh()->submitted_late)->toBeFalse()
        ->and($obligation->fresh()->submitted_late)->toBeFalse();
});

it('judges a filed return against the deadline it was given, not one edited later', function () {
    Carbon::setTestNow('2026-04-05 10:00:00');

    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    $report = ProgressReport::factory()
        ->forObligation($obligation)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->consultant);

    // The secretariat later moves the March deadline earlier — retroactively
    // making a filed return late would be rewriting history.
    $this->period->forceFill(['due_at' => Carbon::parse('2026-04-01 23:59:59')])->save();

    expect($report->fresh()->submitted_late)->toBeFalse()
        ->and($report->fresh()->due_at->toDateString())->toBe('2026-04-07');
});

it('does not restart the clock when a return is sent back and filed again', function () {
    Carbon::setTestNow('2026-04-05 10:00:00');

    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    $report = ProgressReport::factory()
        ->forObligation($obligation)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->consultant);
    expect($report->fresh()->submitted_late)->toBeFalse();

    app(ReturnProgressReport::class)($report->fresh(), $this->officer, 'Attach the valuation certificate.');

    // Resubmitted a week after the deadline: the MDA reported on time and a
    // reviewer's turnaround must not change that (§9.6).
    Carbon::setTestNow('2026-04-14 10:00:00');
    ($this->submit)($report->fresh(), $this->consultant);

    expect($report->fresh()->submitted_late)->toBeFalse()
        ->and($obligation->fresh()->submitted_late)->toBeFalse()
        ->and($obligation->fresh()->status)->toBe(ReportObligationStatus::Fulfilled);
});

it('keeps the fulfilment stamp of the first filing across a rework cycle', function () {
    Carbon::setTestNow('2026-04-05 10:00:00');

    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    $report = ProgressReport::factory()
        ->forObligation($obligation)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->consultant);
    $firstFulfilment = $obligation->fresh()->fulfilled_at;

    app(ReturnProgressReport::class)($report->fresh(), $this->officer, 'Correct the expenditure figure.');

    Carbon::setTestNow('2026-04-20 10:00:00');
    ($this->submit)($report->fresh(), $this->consultant);

    expect($obligation->fresh()->fulfilled_at->toDateTimeString())->toBe($firstFulfilment->toDateTimeString());
});

it('marks whatever is still pending as missed once a window hard-closes', function () {
    $closed = ReportingPeriod::factory()->monthly(2026, 2)->create([
        'closes_at' => Carbon::parse('2026-03-31 23:59:59'),
    ]);

    $missed = ReportObligation::factory()->forProject($this->project)->forPeriod($closed)->create();
    $filed = ReportObligation::factory()->forProject($this->project)->forPeriod($closed)->fulfilled()->create();
    $waived = ReportObligation::factory()->forProject($this->project)->forPeriod($closed)->waived()->create();
    $stillOpen = ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    $count = app(CloseReportingPeriods::class)();

    expect($count)->toBe(1)
        ->and($missed->fresh()->status)->toBe(ReportObligationStatus::Missed)
        // A filed return and a signed waiver are not retroactively misses.
        ->and($filed->fresh()->status)->toBe(ReportObligationStatus::Fulfilled)
        ->and($waived->fresh()->status)->toBe(ReportObligationStatus::Waived)
        // The March window has no hard close, so it is untouched.
        ->and($stillOpen->fresh()->status)->toBe(ReportObligationStatus::Pending);
});

it('closes nothing while the instance accepts late returns', function () {
    ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    expect(app(CloseReportingPeriods::class)())->toBe(0);
});
