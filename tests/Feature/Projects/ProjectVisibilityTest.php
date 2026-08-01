<?php

/**
 * Project::scopeVisibleTo() (projects-module.md §3, migration review §3) is the
 * single definition of "which projects may this user see", shared by
 * ProjectPolicy::view and every list screen so a policy and a query cannot
 * disagree.
 *
 * These cases exist because the scope's failure mode is silent: a no-op scope
 * returns the whole tenant portfolio and every naive test still passes. Each
 * assertion below therefore proves a *restriction*, not just a result.
 */

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->assigned = Project::factory()->ongoing()->create(['title' => 'Assigned road works']);
    $this->other = Project::factory()->ongoing()->create(['title' => 'Somebody else\'s bridge']);
});

it('shows a consultant only the projects they are actively assigned to', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    ProjectAssignment::factory()->forProject($this->assigned)->forUser($consultant)->consultant()->create();

    $visible = Project::query()->visibleTo($consultant)->pluck('id')->all();

    expect($visible)->toBe([$this->assigned->id])
        ->and($visible)->not->toContain($this->other->id);
});

it('shows a field monitor only the projects they are actively assigned to', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    ProjectAssignment::factory()->forProject($this->assigned)->forUser($monitor)->fieldMonitor()->create();

    expect(Project::query()->visibleTo($monitor)->pluck('id')->all())->toBe([$this->assigned->id]);
});

it('hides a project again once the consultant is unassigned from it', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    ProjectAssignment::factory()
        ->forProject($this->assigned)
        ->forUser($consultant)
        ->consultant()
        ->unassigned()
        ->create();

    expect(Project::query()->visibleTo($consultant)->count())->toBe(0);
});

it('shows a consultant with no assignments nothing at all', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    expect(Project::query()->visibleTo($consultant)->count())->toBe(0)
        ->and(Project::query()->count())->toBe(2); // the portfolio exists; it is just not theirs
});

it('shows an M&E officer the whole workspace portfolio, assigned or not', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    expect(Project::query()->visibleTo($officer)->count())->toBe(2);
});

it('shows an MDA admin the whole workspace portfolio, assigned or not', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    expect(Project::query()->visibleTo($admin)->count())->toBe(2);
});

it('shows an officer who also holds a consultant role the whole portfolio', function () {
    // A dual-hatted user is not demoted to project-level visibility: the
    // restriction applies only to users whose roles are project-level ONLY.
    $user = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    memberOf($user, $this->works, Role::Consultant);

    expect(Project::query()->visibleTo($user->fresh())->count())->toBe(2);
});

it('never lets an assignment in another workspace widen what a consultant sees', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    memberOf($consultant, $health, Role::Consultant);

    ProjectAssignment::factory()->forProject($this->assigned)->forUser($consultant)->consultant()->create();

    $healthProject = actingOnTenantReturn($health, function () use ($consultant) {
        $project = Project::factory()->ongoing()->create();
        ProjectAssignment::factory()->forProject($project)->forUser($consultant)->consultant()->create();

        return $project;
    });

    actingOnTenant($this->works);

    $visible = Project::query()->visibleTo($consultant->fresh())->pluck('id')->all();

    expect($visible)->toBe([$this->assigned->id])
        ->and($visible)->not->toContain($healthProject->id);
});

/** Run a callback inside another tenant's context and return its result. */
function actingOnTenantReturn(Tenant $tenant, Closure $callback): mixed
{
    return app(CurrentTenant::class)->runAs($tenant, $callback);
}
