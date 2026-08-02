<?php

/**
 * The authorization matrix for reporting (progress-reporting.md §4), one case
 * per role per ability — the test that proves the permission table in the
 * seeder is the permission table in the design.
 *
 * The structural claim it exists to pin down: a CONSULTANT holds
 * `reports.create` and `reports.submit` and NEITHER `reports.review` NOR
 * `reports.approve`. That is why "a consultant cannot approve their own
 * report" needs no runtime guard to be true — and why weakening this seeder
 * would break a security property rather than a preference.
 */

use App\Enums\Role;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->project = Project::factory()->ongoing()->create();
});

it('grants each workspace role exactly the reporting abilities the design gives it', function (Role $role, array $granted, array $denied) {
    $user = memberOf(User::factory()->create(), $this->works, $role);

    // The design's matrix reads "assigned" for the two project-level roles:
    // their `reports.view` is narrowed to the projects they are accountable
    // for, so the matrix is only meaningful with the assignment in place. The
    // unassigned case is proven separately below.
    if (in_array($role, [Role::Consultant, Role::FieldMonitor], true)) {
        ProjectAssignment::factory()->consultant()->create([
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'assigned_by_id' => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin)->id,
        ]);
    }

    $user = $user->fresh();

    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($user)
        ->create();

    foreach ($granted as $ability) {
        expect(Gate::forUser($user)->allows($ability, $report))
            ->toBeTrue("[{$role->value}] should be able to [{$ability}]");
    }

    foreach ($denied as $ability) {
        expect(Gate::forUser($user)->denies($ability, $report))
            ->toBeTrue("[{$role->value}] should NOT be able to [{$ability}]");
    }
})->with([
    'MDA admin signs off' => [
        Role::MdaAdmin,
        ['view', 'update', 'submit', 'review', 'approve', 'discard'],
        [],
    ],
    'M&E officer reviews but never approves' => [
        Role::MeOfficer,
        ['view', 'update', 'submit', 'review'],
        ['approve'],
    ],
    'consultant files and nothing more' => [
        Role::Consultant,
        ['view', 'update', 'submit', 'discard'],
        ['review', 'approve'],
    ],
    'field monitor reads only' => [
        Role::FieldMonitor,
        ['view'],
        ['update', 'submit', 'review', 'approve'],
    ],
]);

it('lets a consultant see only the returns of projects they are assigned to', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $mine = ProgressReport::factory()->forProject($this->project)->forPeriod($this->period)->by($consultant)->create();

    $someoneElses = ProgressReport::factory()
        ->forProject(Project::factory()->ongoing()->create())
        ->forPeriod($this->period)
        ->create();

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $consultant->id,
        'assigned_by_id' => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin)->id,
    ]);

    $consultant = $consultant->fresh();

    expect(Gate::forUser($consultant)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($consultant)->denies('view', $someoneElses))->toBeTrue();
});

it('refuses an MDA admin any authority over another ministry\'s return', function () {
    $worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $foreign = app(CurrentTenant::class)->runAs($this->health, fn () => ProgressReport::factory()
        ->forProject(Project::factory()->ongoing()->create())
        ->create());

    // Back in Works' workspace: the record belongs to Health, and a role held
    // in one MDA grants nothing in another.
    foreach (['view', 'update', 'submit', 'review', 'approve'] as $ability) {
        expect(Gate::forUser($worksAdmin->fresh())->denies($ability, $foreign))->toBeTrue();
    }
});

it('lets only the compliance roles waive an obligation', function (Role $role, bool $allowed) {
    $user = memberOf(User::factory()->create(), $this->works, $role)->fresh();
    $obligation = ReportObligation::factory()->forProject($this->project)->forPeriod($this->period)->create();

    expect(Gate::forUser($user)->allows('waive', $obligation))->toBe($allowed);
})->with([
    'MDA admin' => [Role::MdaAdmin, true],
    'M&E officer' => [Role::MeOfficer, false],
    'consultant' => [Role::Consultant, false],
    'field monitor' => [Role::FieldMonitor, false],
]);

it('gives every oversight role read access to returns and none of them a signature', function (Role $role) {
    $user = userWithRole($role);
    $report = ProgressReport::factory()->forProject($this->project)->forPeriod($this->period)->create();

    // Oversight roles hold their permissions in the GLOBAL team, so they are
    // read-only across every MDA rather than authoritative inside one.
    expect(Gate::forUser($user)->allows('view', $report))->toBeTrue()
        ->and(Gate::forUser($user)->denies('review', $report))->toBeTrue()
        ->and(Gate::forUser($user)->denies('approve', $report))->toBeTrue()
        ->and(Gate::forUser($user)->denies('submit', $report))->toBeTrue();
})->with([
    'state admin' => [Role::StateAdmin],
    'executive viewer' => [Role::ExecutiveViewer],
    'data quality reviewer' => [Role::DataQualityReviewer],
]);
