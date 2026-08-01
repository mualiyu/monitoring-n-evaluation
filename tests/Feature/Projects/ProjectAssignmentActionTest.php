<?php

/**
 * AssignProjectMember / UnassignProjectMember (projects-module.md §1.8, §3).
 *
 * The assignment row is what a consultant's entire visibility hangs off, so
 * the two gates on the target — active membership AND a project-level role in
 * THIS workspace — are the difference between "assigned to a project" and
 * "admitted to a ministry".
 */

use App\Actions\Projects\AssignProjectMember;
use App\Actions\Projects\UnassignProjectMember;
use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->project = Project::factory()->ongoing()->create();
    $this->assign = new AssignProjectMember;
});

it('assigns a consultant who belongs to this workspace', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $assignment = ($this->assign)($this->project, $consultant, ProjectRole::Consultant, $this->admin);

    expect($assignment->tenant_id)->toBe($this->works->id)
        ->and($assignment->assigned_by_id)->toBe($this->admin->id)
        ->and($assignment->isActive())->toBeTrue()
        // and the assignment is what makes the project visible to them
        ->and(Project::query()->visibleTo($consultant->fresh())->pluck('id')->all())
        ->toBe([$this->project->id]);
});

it('refuses to assign someone who is not a member of this workspace', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $outsider = memberOf(User::factory()->create(), $health, Role::Consultant);

    app(CurrentTenant::class)->set($this->works);

    expect(fn () => ($this->assign)($this->project, $outsider->fresh(), ProjectRole::Consultant, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'active membership of this workspace');
});

it('refuses to assign a member whose role is not a delivery role', function () {
    // An MDA admin is staff, not the accountable party for a site.
    $otherAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    expect(fn () => ($this->assign)($this->project, $otherAdmin->fresh(), ProjectRole::Supervisor, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'consultants, field monitors and M&E officers');
});

it('assigns an M&E officer as focal officer or supervisor', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $assignment = ($this->assign)($this->project, $officer->fresh(), ProjectRole::FocalOfficer, $this->admin);

    expect($assignment->role)->toBe(ProjectRole::FocalOfficer);
});

it('refuses a second active assignment for the same role', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    ($this->assign)($this->project, $monitor->fresh(), ProjectRole::FieldMonitor, $this->admin);

    expect(fn () => ($this->assign)($this->project, $monitor->fresh(), ProjectRole::FieldMonitor, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'already holds this role');
});

it('reactivates the original row on re-assignment rather than writing a second one', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $first = ($this->assign)($this->project, $consultant->fresh(), ProjectRole::Consultant, $this->admin);
    (new UnassignProjectMember)($first, $this->admin);

    expect($first->fresh()->isActive())->toBeFalse()
        ->and(Project::query()->visibleTo($consultant->fresh())->count())->toBe(0);

    $second = ($this->assign)($this->project, $consultant->fresh(), ProjectRole::Consultant, $this->admin);

    expect($second->id)->toBe($first->id)
        ->and($second->isActive())->toBeTrue()
        ->and(ProjectAssignment::query()->where('project_id', $this->project->id)->count())->toBe(1);
});

it('keeps the row when someone is unassigned, because who was accountable in March is an audit question', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    $assignment = ($this->assign)($this->project, $monitor->fresh(), ProjectRole::FieldMonitor, $this->admin);

    (new UnassignProjectMember)($assignment, $this->admin);

    expect(ProjectAssignment::query()->where('project_id', $this->project->id)->count())->toBe(1)
        ->and($assignment->fresh()->unassigned_at)->not->toBeNull();
});

it('refuses assignment to a consultant, who cannot add themselves to a project', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    expect(fn () => ($this->assign)($this->project, $consultant->fresh(), ProjectRole::Consultant, $consultant->fresh()))
        ->toThrow(AuthorizationException::class);
});
