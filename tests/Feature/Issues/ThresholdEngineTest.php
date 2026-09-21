<?php

/**
 * The threshold engine: the nightly sweep that raises an exception report when
 * a project trips a configured tolerance, and escalates issues nobody cleared.
 *
 * THE PROPERTY THAT MATTERS MOST IS IDEMPOTENCE. A project 34 points behind
 * schedule is still 34 points behind tomorrow, so the naive engine files the
 * same deviation every night until somebody mutes the entire feature. Every
 * test here that runs the sweep twice is testing that, and the duplicate gate
 * lives at the WRITE (RaiseExceptionReport) rather than in the sweep, so every
 * door is covered rather than just this one.
 *
 * Time is controlled with Carbon::setTestNow() throughout — no sleeping, no
 * real clocks.
 */

use App\Actions\Issues\EscalateStaleIssues;
use App\Actions\Issues\EvaluateProjectThresholds;
use App\Actions\Issues\RaiseExceptionReport;
use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Jobs\Issues\NotifyExceptionReportRaised;
use App\Jobs\Issues\NotifyIssueEscalated;
use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    seedPermissions();

    CarbonImmutable::setTestNow('2026-09-20 08:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A project 72% through its programme with 38% of the work done — 34 points of
 * slippage against a 15-point tolerance.
 */
function slippingProject(array $overrides = []): Project
{
    return Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
        'start_date' => '2026-01-01',
        'expected_end_date' => '2026-12-01',
        'physical_progress' => '10.00',
        // The money is PINNED, and that is the point of this helper.
        //
        // ->ongoing() derives expenditure from a random physical percent
        // (10–80), so overriding physical_progress alone leaves money and work
        // wildly out of step — and the engine then correctly raises an
        // EXPENDITURE-VARIANCE report as well as the schedule one. The test
        // would pass or fail on a faker roll.
        //
        // Spend held at exactly 10% of budget matches the 10% of work done, so
        // variance is zero and the only tolerance in play is the schedule.
        'contract_value_total' => '100000000.00',
        'budget_allocation' => '100000000.00',
        'expenditure_to_date' => '10000000.00',
        ...$overrides,
    ]);
}

/* -------------------------------------------------------------------------- */
/* Raising from a tripped tolerance */
/* -------------------------------------------------------------------------- */

it('raises a schedule-slippage report when a project falls behind the clock', function () {
    slippingProject();

    $totals = app(EvaluateProjectThresholds::class)();

    expect($totals['raised'])->toBe(1);

    $report = ExceptionReport::query()->firstOrFail();

    expect($report->trigger)->toBe(ExceptionTrigger::ScheduleSlippage)
        ->and($report->status)->toBe(ExceptionStatus::Open)
        // Raised by the engine: no human actor, honestly recorded as such.
        ->and($report->raised_by_id)->toBeNull()
        ->and($report->isAutomatic())->toBeTrue()
        ->and($report->tenant_id)->toBe($this->works->id)
        // THE RECORD EXPLAINS ITSELF: the measurement and the tolerance it
        // tripped are stored, not re-derived from a project whose figures
        // will have moved by the time anyone reads this.
        ->and((float) $report->threshold_value)->toBe(15.0)
        ->and((float) $report->measured_value)->toBeGreaterThan(15.0)
        ->and((float) $report->physical_progress)->toBe(10.0)
        ->and($report->schedule_elapsed)->not->toBeNull();

    Bus::assertDispatched(NotifyExceptionReportRaised::class);
});

it('raises an expenditure-variance report when the money outruns the work', function () {
    // Dead on schedule, but 65% of the contract value drawn against 25% done.
    $project = Project::factory()->ongoing()->create([
        'start_date' => '2026-01-01',
        'expected_end_date' => '2027-01-01',
        'physical_progress' => '25.00',
    ]);

    $project->forceFill([
        'contract_value_total' => '100000000.00',
        'expenditure_to_date' => '65000000.00',
    ])->save();

    app(EvaluateProjectThresholds::class)();

    $report = ExceptionReport::query()
        ->where('trigger', ExceptionTrigger::ExpenditureVariance)
        ->firstOrFail();

    expect((float) $report->measured_value)->toBe(40.0)
        ->and((float) $report->threshold_value)->toBe(20.0)
        ->and((float) $report->financial_progress)->toBe(65.0);
});

it('raises a reporting-overdue report once a statutory return is late past the tolerance', function () {
    $project = Project::factory()->ongoing()->create([
        'start_date' => '2026-01-01',
        'expected_end_date' => '2027-06-01',
        // On programme, so only the compliance tolerance can trip.
        'physical_progress' => '45.00',
    ]);

    $period = ReportingPeriod::factory()->monthly()->create();

    ReportObligation::factory()
        ->forProject($project)
        ->forPeriod($period)
        ->create(['due_at' => CarbonImmutable::now()->subDays(21)]);

    app(EvaluateProjectThresholds::class)();

    $report = ExceptionReport::query()
        ->where('trigger', ExceptionTrigger::ReportingOverdue)
        ->firstOrFail();

    expect((float) $report->threshold_value)->toBe(14.0)
        ->and((float) $report->measured_value)->toBe(21.0);
});

it('leaves a project inside every tolerance alone', function () {
    Project::factory()->ongoing()->create([
        'start_date' => '2026-01-01',
        'expected_end_date' => '2026-12-01',
        // ~72% elapsed, 70% done — 2 points of slippage on a 15-point limit.
        'physical_progress' => '70.00',
    ]);

    $totals = app(EvaluateProjectThresholds::class)();

    expect($totals['evaluated'])->toBe(1)
        ->and($totals['raised'])->toBe(0)
        ->and(ExceptionReport::query()->count())->toBe(0);
});

it('never raises slippage against a project with no schedule to be behind', function () {
    // Null is "not measurable", never "0% elapsed" — otherwise every dateless
    // project that has reported any progress trips the alert.
    Project::factory()->ongoing()->create([
        'start_date' => null,
        'expected_end_date' => null,
        'physical_progress' => '10.00',
    ]);

    app(EvaluateProjectThresholds::class)();

    expect(ExceptionReport::query()->where('trigger', ExceptionTrigger::ScheduleSlippage)->count())->toBe(0);
});

it('measures against the revised end date, so an approved extension is honoured', function () {
    // Two projects, identical except for the extension, so the test proves the
    // revised date is HONOURED rather than merely that nothing was raised.
    //
    // Both started 2026-01-01 and are 10% built on 2026-09-20 (263 days in).
    //  - control: original end 2026-10-01 → 96% elapsed, 86 points behind → raised.
    //  - extended: revised end 2030-01-01 → 18% elapsed, 8 points behind → quiet,
    //    because 8 is inside the 15-point tolerance.
    //
    // Money is pinned on both: ->ongoing() derives spend from a random
    // physical percent, and an unpinned figure raises an expenditure-variance
    // report that has nothing to do with what is under test.
    $pinnedMoney = [
        'contract_value_total' => '100000000.00',
        'budget_allocation' => '100000000.00',
        'expenditure_to_date' => '10000000.00',
        'physical_progress' => '10.00',
    ];

    $control = Project::factory()->ongoing()->create([
        'reference' => 'WKS/2026/CONTROL',
        'start_date' => '2026-01-01',
        'expected_end_date' => '2026-10-01',
        ...$pinnedMoney,
    ]);

    $extended = Project::factory()->ongoing()->create([
        'reference' => 'WKS/2026/EXTENDED',
        'start_date' => '2026-01-01',
        'expected_end_date' => '2026-10-01',
        'revised_end_date' => '2030-01-01',
        ...$pinnedMoney,
    ]);

    app(EvaluateProjectThresholds::class)();

    $slippage = fn (Project $project): int => ExceptionReport::query()
        ->where('trigger', ExceptionTrigger::ScheduleSlippage)
        ->where('project_id', $project->id)
        ->count();

    // The extension is approved reality; flagging it would be a false alarm
    // the user learns to ignore — and the control proves the engine would
    // otherwise have fired.
    expect($slippage($extended))->toBe(0)
        ->and($slippage($control))->toBe(1);
});

it('skips projects that are not under delivery', function () {
    $draft = Project::factory()->draft()->create([
        'start_date' => '2026-01-01',
        'expected_end_date' => '2026-12-01',
        'physical_progress' => '0.00',
    ]);

    expect($draft->status)->toBe(ProjectStatus::Draft);

    $totals = app(EvaluateProjectThresholds::class)();

    expect($totals['evaluated'])->toBe(0)
        ->and(ExceptionReport::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Idempotence — the property the whole feature stands on */
/* -------------------------------------------------------------------------- */

it('does not raise a duplicate for a condition already open', function () {
    slippingProject();

    app(EvaluateProjectThresholds::class)();
    app(EvaluateProjectThresholds::class)();
    app(EvaluateProjectThresholds::class)();

    // Three nights, one report. Without this the board fills with the same
    // deviation until somebody mutes the feature entirely.
    expect(ExceptionReport::query()->where('trigger', ExceptionTrigger::ScheduleSlippage)->count())->toBe(1);
});

it('treats an ACKNOWLEDGED deviation as still live, so tonight\'s sweep stays quiet', function () {
    $project = slippingProject();

    app(EvaluateProjectThresholds::class)();

    ExceptionReport::query()->firstOrFail()
        ->forceFill(['status' => ExceptionStatus::Acknowledged])->save();

    app(EvaluateProjectThresholds::class)();

    // An officer who has seen the deviation does not need telling again.
    expect(ExceptionReport::query()->count())->toBe(1);
});

it('raises the condition again once it has been resolved and comes back', function () {
    slippingProject();

    app(EvaluateProjectThresholds::class)();

    ExceptionReport::query()->firstOrFail()->forceFill([
        'status' => ExceptionStatus::Resolved,
        'resolved_at' => CarbonImmutable::now(),
        'resolution_note' => 'Recovery programme agreed.',
    ])->save();

    app(EvaluateProjectThresholds::class)();

    // A resolved deviation that recurs is a NEW fact, and the board must say
    // so — this is the other half of the duplicate gate being correct.
    expect(ExceptionReport::query()->count())->toBe(2);
});

it('lets a human file a second critical incident, because a second incident is a second fact', function () {
    $project = slippingProject();

    $raise = app(RaiseExceptionReport::class);

    $raise($project, ExceptionTrigger::CriticalIncident, ['narrative' => 'A pier cracked.'], $this->officer);
    $raise($project, ExceptionTrigger::CriticalIncident, ['narrative' => 'The abutment has now moved.'], $this->officer);

    expect(ExceptionReport::query()->where('trigger', ExceptionTrigger::CriticalIncident)->count())->toBe(2);
});

it('tells a person raising a duplicate automatic condition, rather than silently doing nothing', function () {
    $project = slippingProject();

    $raise = app(RaiseExceptionReport::class);

    $raise($project, ExceptionTrigger::ScheduleSlippage, ['narrative' => 'Behind programme.'], $this->officer);

    // A silent no-op on a screen reads as a broken button.
    expect(fn () => $raise($project, ExceptionTrigger::ScheduleSlippage, ['narrative' => 'Still behind.'], $this->officer))
        ->toThrow(IssueRuleViolation::class, 'already has a live');
});

it('refuses an exception report with no narrative', function () {
    $project = slippingProject();

    expect(fn () => app(RaiseExceptionReport::class)(
        $project,
        ExceptionTrigger::Manual,
        ['narrative' => '   '],
        $this->officer,
    ))->toThrow(IssueRuleViolation::class, 'needs a narrative');
});

/* -------------------------------------------------------------------------- */
/* Tenancy travels with the sweep */
/* -------------------------------------------------------------------------- */

it('sweeps every active MDA and stamps each report with its own workspace', function () {
    slippingProject();

    app(CurrentTenant::class)->runAs($this->health, function () {
        Project::factory()->ongoing()->create([
            'title' => 'Primary Health Centre Upgrade',
            'start_date' => '2026-01-01',
            'expected_end_date' => '2026-12-01',
            'physical_progress' => '5.00',
            // Money pinned to match the work done, for the same reason
            // slippingProject() pins it: ->ongoing() derives spend from a
            // random physical percent, so an unpinned figure raises a SECOND
            // report for expenditure variance and the count below becomes a
            // coin toss. One tolerance per project is what this test is about.
            'contract_value_total' => '80000000.00',
            'budget_allocation' => '80000000.00',
            'expenditure_to_date' => '4000000.00',
        ]);
    });

    actingWithoutTenant();

    $totals = app(EvaluateProjectThresholds::class)();

    expect($totals['evaluated'])->toBe(2)
        ->and($totals['raised'])->toBe(2);

    $current = app(CurrentTenant::class);

    $current->runAs($this->works, function () {
        expect(ExceptionReport::query()->count())->toBe(1)
            ->and(ExceptionReport::query()->firstOrFail()->tenant_id)->toBe($this->works->id);
    });

    $current->runAs($this->health, function () {
        expect(ExceptionReport::query()->count())->toBe(1)
            ->and(ExceptionReport::query()->firstOrFail()->tenant_id)->toBe($this->health->id);
    });
});

it('leaves a deactivated MDA out of the sweep entirely', function () {
    slippingProject();

    $this->works->forceFill(['is_active' => false])->save();

    actingWithoutTenant();

    $totals = app(EvaluateProjectThresholds::class)();

    expect($totals['evaluated'])->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* The issue escalation ladder */
/* -------------------------------------------------------------------------- */

it('escalates an issue left open past its severity allowance', function () {
    $project = slippingProject();

    // critical => 3 days in the seeded policy.
    $issue = Issue::factory()
        ->forProject($project)
        ->critical()
        ->raisedDaysAgo(4)
        ->create();

    actingWithoutTenant();

    expect(app(EscalateStaleIssues::class)())->toBe(1);

    $issue = Issue::query()->withoutGlobalScopes()->findOrFail($issue->id);

    expect($issue->status)->toBe(IssueStatus::Escalated)
        ->and($issue->escalated_at)->not->toBeNull();

    Bus::assertDispatched(NotifyIssueEscalated::class);
});

it('leaves an issue inside its allowance alone, and keys the clock on severity', function () {
    $project = slippingProject();

    // low => 60 days. Nine days open is nowhere near it, while a critical
    // issue of the same age would have escalated six days ago.
    Issue::factory()->forProject($project)->severity(IssueSeverity::Low)->raisedDaysAgo(9)->create();

    actingWithoutTenant();

    expect(app(EscalateStaleIssues::class)())->toBe(0);
});

it('escalates each issue once, ever', function () {
    $project = slippingProject();

    Issue::factory()->forProject($project)->critical()->raisedDaysAgo(30)->create();

    actingWithoutTenant();

    expect(app(EscalateStaleIssues::class)())->toBe(1)
        // A director told once is not told nightly. The gate is
        // `escalated_at`, advanced under the same row lock as the write.
        ->and(app(EscalateStaleIssues::class)())->toBe(0)
        ->and(app(EscalateStaleIssues::class)())->toBe(0);
});

it('never escalates an issue somebody has already resolved or closed', function () {
    $project = slippingProject();

    Issue::factory()->forProject($project)->critical()->raisedDaysAgo(40)->resolved($this->officer)->create();
    Issue::factory()->forProject($project)->critical()->raisedDaysAgo(40)->closed($this->admin)->create();

    actingWithoutTenant();

    expect(app(EscalateStaleIssues::class)())->toBe(0);
});

it('escalates across every MDA, with each ladder run inside its own workspace', function () {
    $project = slippingProject();

    Issue::factory()->forProject($project)->critical()->raisedDaysAgo(10)->create();

    app(CurrentTenant::class)->runAs($this->health, function () {
        $theirs = Project::factory()->ongoing()->create();

        Issue::factory()->forProject($theirs)->critical()->raisedDaysAgo(10)->create();
    });

    actingWithoutTenant();

    expect(app(EscalateStaleIssues::class)())->toBe(2);
});
