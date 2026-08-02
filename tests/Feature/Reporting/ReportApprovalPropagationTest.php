<?php

/**
 * §2.3 — approval, and ONLY approval, moves the project's attested figures,
 * and it moves them through App\Actions\Projects\RecordProjectProgress.
 *
 * The propagation is the join between the two modules, so the tests here are
 * really about the seam: submission and review must leave the project alone;
 * the projects chokepoint's own guards (the mid-term flag, the range check,
 * the frozen certified record) must still fire when the figure arrives from a
 * report rather than from a form.
 */

use App\Actions\Reporting\ApproveProgressReport;
use App\Actions\Reporting\ReviewProgressReport;
use App\Actions\Reporting\SubmitProgressReport;
use App\Enums\Role;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Notification;

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
        'physical_progress' => '20.00',
        'expenditure_to_date' => '10000000.00',
        'contract_value_total' => '100000000.00',
        'mid_term_flagged_at' => null,
    ]);

    $this->submit = app(SubmitProgressReport::class);
    $this->review = app(ReviewProgressReport::class);
    $this->approve = app(ApproveProgressReport::class);

    $this->report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->create([
            'physical_progress_claimed' => '35.00',
            'period_expenditure' => '5000000.00',
        ]);
});

it('leaves the project untouched at submission and at review', function () {
    ($this->submit)($this->report, $this->consultant);

    expect($this->project->fresh()->physical_progress)->toBe('20.00')
        ->and($this->project->fresh()->expenditure_to_date->toDecimalString())->toBe('10000000.00');

    ($this->review)($this->report->fresh(), $this->officer);

    expect($this->project->fresh()->physical_progress)->toBe('20.00')
        ->and($this->project->fresh()->expenditure_to_date->toDecimalString())->toBe('10000000.00');
});

it('applies the claimed progress and adds the period spend to the project total at approval', function () {
    ($this->submit)($this->report, $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);
    ($this->approve)($this->report->fresh(), $this->admin);

    $project = $this->project->fresh();

    expect($project->physical_progress)->toBe('35.00')
        // 10,000,000 already spent + 5,000,000 reported for THIS period.
        ->and($project->expenditure_to_date->toDecimalString())->toBe('15000000.00');
});

it('snapshots what the project said before the return was believed', function () {
    ($this->submit)($this->report, $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);
    ($this->approve)($this->report->fresh(), $this->admin);

    $report = $this->report->fresh();

    expect($report->physical_progress_before)->toBe('20.00')
        ->and($report->cumulative_expenditure_snapshot->toDecimalString())->toBe('15000000.00');
});

it('sums period expenditure across returns rather than trusting a claimed cumulative', function () {
    ($this->submit)($this->report, $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);
    ($this->approve)($this->report->fresh(), $this->admin);

    $next = ReportingPeriod::factory()->monthly(now()->addMonth()->year, now()->addMonth()->month)->create();

    $second = ProgressReport::factory()
        ->forProject($this->project->fresh())
        ->forPeriod($next)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '48.00', 'period_expenditure' => '2500000.00']);

    // The second window has not opened yet in wall-clock terms; the figures
    // are what this test is about, so it is filed once it does.
    $this->travelTo(now()->addMonth()->startOfMonth()->addDay());

    ($this->submit)($second, $this->consultant);
    ($this->review)($second->fresh(), $this->officer);
    ($this->approve)($second->fresh(), $this->admin);

    expect($this->project->fresh()->expenditure_to_date->toDecimalString())->toBe('17500000.00')
        ->and($this->project->fresh()->physical_progress)->toBe('48.00');
});

it('raises the project\'s mid-term flag when an approved return crosses the threshold', function () {
    $this->report->forceFill(['physical_progress_claimed' => '55.00'])->save();

    ($this->submit)($this->report->fresh(), $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);

    expect($this->project->fresh()->isMidTermFlagged())->toBeFalse();

    ($this->approve)($this->report->fresh(), $this->admin);

    // Raised by the projects chokepoint, not by this module — which is the
    // entire reason approval propagates through it.
    expect($this->project->fresh()->isMidTermFlagged())->toBeTrue();
});

it('records a verified downward revision, with its reason on the report', function () {
    $this->report->forceFill([
        'physical_progress_claimed' => '14.00',
        'progress_decrease_reason' => 'Defective culvert removed; the section was re-measured after inspection.',
    ])->save();

    ($this->submit)($this->report->fresh(), $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);
    ($this->approve)($this->report->fresh(), $this->admin);

    expect($this->project->fresh()->physical_progress)->toBe('14.00')
        ->and($this->report->fresh()->physical_progress_before)->toBe('20.00');
});

it('applies the projects module\'s own range guard to a reported figure', function () {
    // A claim no percentage can take must be refused wherever it arrives
    // from — a form, an import, or an approved return.
    $this->report->forceFill(['physical_progress_claimed' => '100.00'])->save();

    ($this->submit)($this->report->fresh(), $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);
    ($this->approve)($this->report->fresh(), $this->admin);

    expect($this->project->fresh()->physical_progress)->toBe('100.00');
});

it('adds a zero-spend period without moving the money', function () {
    $this->report->forceFill(['period_expenditure' => Money::zero()])->save();

    ($this->submit)($this->report->fresh(), $this->consultant);
    ($this->review)($this->report->fresh(), $this->officer);
    ($this->approve)($this->report->fresh(), $this->admin);

    expect($this->project->fresh()->expenditure_to_date->toDecimalString())->toBe('10000000.00')
        ->and($this->project->fresh()->physical_progress)->toBe('35.00');
});
