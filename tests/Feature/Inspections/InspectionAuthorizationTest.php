<?php

/**
 * The authorization matrix for site inspections — one case per role per
 * surface, stating what it may do AND what it may not.
 *
 * The structural half of separation of duties lives in the permission matrix:
 * a FieldMonitor holds `inspections.conduct` and NEVER `inspections.review`,
 * so "an inspector cannot sign off an inspection" needs no runtime check to be
 * true for them. The runtime half exists for the M&E officer, who holds BOTH
 * and routinely conducts visits in a small MDA — that case is proved in
 * InspectionLifecycleTest, where the chokepoint enforces it.
 *
 * Abilities are asked through the Gate rather than by calling the policy
 * directly: the Gate is what the screens and the Actions use, and a policy
 * that is never registered would pass a direct call and fail in production.
 */

use App\Actions\Inspections\ScheduleInspection;
use App\Actions\Oversight\ListInspectionsAcrossTenants;
use App\Enums\InspectionType;
use App\Enums\Role;
use App\Livewire\Oversight\Inspections\InspectionBoard;
use App\Livewire\Tenant\Inspections\InspectionSchedule;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    $this->scheduled = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    $this->filed = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The abilities a user holds over a given visit, as the Gate answers them. */
function inspectionAbilities(User $user, SiteInspection $inspection): array
{
    $user = User::query()->whereKey($user->getKey())->firstOrFail();

    return [
        'viewAny' => Gate::forUser($user)->allows('viewAny', SiteInspection::class),
        'view' => Gate::forUser($user)->allows('view', $inspection),
        'create' => Gate::forUser($user)->allows('create', SiteInspection::class),
        'update' => Gate::forUser($user)->allows('update', $inspection),
        'conduct' => Gate::forUser($user)->allows('conduct', $inspection),
        'review' => Gate::forUser($user)->allows('review', $inspection),
        'cancel' => Gate::forUser($user)->allows('cancel', $inspection),
    ];
}

/* -------------------------------------------------------------------------- */
/* Workspace roles */
/* -------------------------------------------------------------------------- */

it('gives an entity administrator the whole desk', function () {
    expect(inspectionAbilities($this->admin, $this->scheduled))->toBe([
        'viewAny' => true,
        'view' => true,
        'create' => true,
        'update' => true,
        // MDA staff may conduct any visit in the workspace — a director
        // covering for an absent monitor is normal, and the chokepoint's
        // separation guard is what stops them then signing it off.
        'conduct' => true,
        'review' => true,
        'cancel' => true,
    ]);
});

it('gives an M&E officer the same desk — they schedule, conduct and sign off', function () {
    expect(inspectionAbilities($this->officer, $this->scheduled))->toBe([
        'viewAny' => true,
        'view' => true,
        'create' => true,
        'update' => true,
        'conduct' => true,
        'review' => true,
        'cancel' => true,
    ]);
});

it('lets a field monitor conduct the visit they lead but never review one', function () {
    expect(inspectionAbilities($this->monitor, $this->scheduled))->toBe([
        'viewAny' => true,
        'view' => true,
        // Planning the diary is an M&E act, not an inspector's.
        'create' => false,
        'update' => false,
        'conduct' => true,
        // The structural half of separation of duties: the permission is not
        // held, in any workspace, ever.
        'review' => false,
        'cancel' => false,
    ]);
});

it('refuses a field monitor the conduct form of a visit somebody else leads', function () {
    $otherLead = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $theirs = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($otherLead)
        ->scheduled()
        ->create();

    expect(inspectionAbilities($this->monitor, $theirs)['conduct'])->toBeFalse();
});

it('gives a consultant a read-only view of the visits on their own projects', function () {
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    expect(inspectionAbilities($this->consultant, $this->scheduled))->toBe([
        'viewAny' => true,
        'view' => true,
        'create' => false,
        'update' => false,
        // A contractor never files the state's assurance over their own work.
        'conduct' => false,
        'review' => false,
        'cancel' => false,
    ]);
});

it('hides a visit on a project a consultant is not assigned to', function () {
    $elsewhere = Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);

    $theirs = SiteInspection::factory()
        ->forProject($elsewhere)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    expect(inspectionAbilities($this->consultant, $theirs)['view'])->toBeFalse();
});

it('refuses to schedule or cancel a visit that has already been filed', function () {
    // Not a permission question: `update` and `cancel` are gated on the visit
    // still being open, so a filed report is not editable by its own author or
    // by the director above them.
    expect(inspectionAbilities($this->admin, $this->filed))->toMatchArray([
        'update' => false,
        'cancel' => false,
        'review' => true,
    ]);
});

/* -------------------------------------------------------------------------- */
/* Through the Actions, not just the Gate */
/* -------------------------------------------------------------------------- */

it('refuses a field monitor who tries to schedule a visit through the Action', function () {
    // The screen is not the control: a Livewire endpoint takes any payload, so
    // the Action authorizes for itself.
    expect(fn () => app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now()->addDay(),
        leadInspector: User::query()->whereKey($this->monitor->id)->firstOrFail(),
        actor: User::query()->whereKey($this->monitor->id)->firstOrFail(),
    ))->toThrow(AuthorizationException::class);

    expect(SiteInspection::query()->count())->toBe(2);
});

it('refuses to schedule against a project the actor may not see', function () {
    // `inspections.schedule` says an officer may put visits in the diary; it
    // does not say which projects are theirs.
    $elsewhere = Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);

    ProjectAssignment::factory()->fieldMonitor()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->monitor->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    // A monitor-only account that somehow held the scheduling permission still
    // could not reach a project outside their assignments — asserted through
    // the consultant, who is refused at the project gate either way.
    expect(fn () => app(ScheduleInspection::class)(
        project: $elsewhere,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now()->addDay(),
        leadInspector: User::query()->whereKey($this->monitor->id)->firstOrFail(),
        actor: User::query()->whereKey($this->consultant->id)->firstOrFail(),
    ))->toThrow(AuthorizationException::class);
});

it('refuses the schedule form component to a consultant even when they reach it directly', function () {
    Livewire::actingAs(User::query()->whereKey($this->consultant->id)->firstOrFail())
        ->test(InspectionSchedule::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Across workspaces */
/* -------------------------------------------------------------------------- */

it('grants nothing in one ministry for a role held in another', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $healthAdmin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

    // Bound to Works, asked about a Works inspection, by a Health director.
    actingOnTenant($this->works);

    expect(inspectionAbilities($healthAdmin, $this->scheduled))->toBe([
        'viewAny' => false,
        'view' => false,
        'create' => false,
        'update' => false,
        'conduct' => false,
        'review' => false,
        'cancel' => false,
    ]);
});

/* -------------------------------------------------------------------------- */
/* The oversight surface */
/* -------------------------------------------------------------------------- */

it('lets a read-only oversight role read the state board', function () {
    $execViewer = userWithRole(Role::ExecutiveViewer);
    $execViewer->forceFill(['two_factor_required_at' => now()])->save();

    app(CurrentTenant::class)->forget();

    $this->actingAs($execViewer)
        ->get(oversightUrl('/inspections'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation');
});

it('gives the state board no way to write', function () {
    // The board is read-only by construction: there is no oversight Action in
    // this module that writes an inspection, and the component exposes no
    // mutating method to call.
    $stateAdmin = userWithRole(Role::StateAdmin);
    app(CurrentTenant::class)->forget();

    $component = Livewire::actingAs($stateAdmin)->test(InspectionBoard::class)->assertOk();

    $mutators = collect(get_class_methods($component->instance()))
        ->filter(fn (string $method): bool => (bool) preg_match(
            '/^(review|cancel|conduct|submit|schedule|save|store|update|delete|destroy)/',
            $method,
        ))
        // Livewire's own updatedX hooks are filter plumbing, not writes.
        ->reject(fn (string $method): bool => str_starts_with($method, 'updated'))
        ->values();

    expect($mutators->all())->toBe([]);
});

it('refuses the cross-MDA read to a user without the global permission', function () {
    // An MDA officer holds `inspections.view` in their own workspace's team
    // and nothing in the global one, so the Action refuses them even if they
    // reach it with a tenant bound.
    $action = app(ListInspectionsAcrossTenants::class);

    expect(fn () => $action(User::query()->whereKey($this->officer->id)->firstOrFail()))
        ->toThrow(AuthorizationException::class);
});
