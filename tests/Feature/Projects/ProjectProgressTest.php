<?php

/**
 * RecordProjectProgress — the only writer of `physical_progress` and
 * `expenditure_to_date` (projects-module.md §2.2, §2.4).
 *
 * The mid-term cases are the regression guard for the finding that removed
 * `mid_term` from the status enum: crossing the threshold raises an EVENT
 * flag, once, and leaves the project in_progress.
 */

use App\Actions\Projects\RecordProjectProgress;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->record = new RecordProjectProgress;
});

it('records attested physical progress and expenditure together', function () {
    $project = Project::factory()->ongoing()->create([
        'physical_progress' => '20.00',
        'expenditure_to_date' => '0.00',
        'mid_term_flagged_at' => null,
    ]);

    ($this->record)($project, $this->officer, '35.50', Money::fromDecimalString('120000000.00'));

    $project->refresh();

    expect($project->physical_progress)->toBe('35.50')
        ->and($project->expenditure_to_date->toDecimalString())->toBe('120000000.00')
        ->and($project->status)->toBe(ProjectStatus::InProgress);
});

it('raises the mid-term flag the first time the threshold is crossed, and never again', function () {
    $project = Project::factory()->ongoing()->create([
        'physical_progress' => '20.00',
        'mid_term_flagged_at' => null,
    ]);

    ($this->record)($project, $this->officer, '49.99');
    expect($project->fresh()->isMidTermFlagged())->toBeFalse();

    ($this->record)($project, $this->officer, '50.00');
    $flaggedAt = $project->fresh()->mid_term_flagged_at;

    expect($flaggedAt)->not->toBeNull()
        // still in progress: mid-term evaluation is an event, not a state
        ->and($project->fresh()->status)->toBe(ProjectStatus::InProgress);

    ($this->record)($project->fresh(), $this->officer, '75.00');

    expect($project->fresh()->mid_term_flagged_at->toDateTimeString())->toBe($flaggedAt->toDateTimeString());
});

it('reads the mid-term trigger from settings rather than a literal', function () {
    config()->set('platform.monitoring.mid_term_trigger_percent', 30);

    $project = Project::factory()->ongoing()->create([
        'physical_progress' => '10.00',
        'mid_term_flagged_at' => null,
    ]);

    ($this->record)($project, $this->officer, '31.00');

    expect($project->fresh()->isMidTermFlagged())->toBeTrue();
});

it('refuses a percentage outside the range a percentage can take', function (string $value) {
    $project = Project::factory()->ongoing()->create();

    expect(fn () => ($this->record)($project, $this->officer, $value))
        ->toThrow(ProjectRuleViolation::class, 'between 0 and 100');
})->with(['101.00', '-1.00', 'nearly done']);

it('refuses progress against a project that is not under execution', function (string $state) {
    $project = Project::factory()->{$state}()->create();

    expect(fn () => ($this->record)($project, $this->officer, '10.00'))
        ->toThrow(ProjectRuleViolation::class, 'cannot be recorded');
})->with(['draft', 'cancelled', 'certified', 'closed']);

it('accepts a verified downward revision, because a wrong number must be correctable', function () {
    $project = Project::factory()->ongoing()->create(['physical_progress' => '60.00']);

    ($this->record)($project, $this->officer, '45.00');

    expect($project->fresh()->physical_progress)->toBe('45.00');
});

it('refuses a consultant the right to declare their own progress', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $project = Project::factory()->ongoing()->create();

    expect(fn () => ($this->record)($project, $consultant->fresh(), '80.00'))
        ->toThrow(AuthorizationException::class);
});

it('never lets financial_progress be written directly — it is derived', function () {
    $project = Project::factory()->ongoing()->create([
        'contract_value_total' => '200000000.00',
        'expenditure_to_date' => '50000000.00',
    ]);

    expect($project->financial_progress)->toBe(25.0)
        ->and(fn () => $project->financial_progress = 90.0)->toThrow(LogicException::class);
});
