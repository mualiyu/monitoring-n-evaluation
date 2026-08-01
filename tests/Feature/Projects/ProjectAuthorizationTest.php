<?php

/**
 * The authorization matrix (projects-module.md §3 and §7), asserted against
 * the policies themselves rather than through any one Action.
 *
 * The rule the policies encode is "permission AND tenant match", with one
 * deliberate second branch: oversight roles hold their permissions in the
 * GLOBAL team and act across MDAs, because the oversight surface binds no
 * tenant at all. These cases are what stop that branch widening into "any
 * admin, anywhere".
 */

use App\Enums\Role;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);
    $this->project = Project::factory()->ongoing()->create();

    $this->foreignProject = app(CurrentTenant::class)->runAs(
        $this->health,
        fn () => Project::factory()->ongoing()->create(),
    );

    actingOnTenant($this->works);
});

it('gives an MDA admin authority over their own workspace and none over the next one', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin)->fresh();

    expect($admin->can('update', $this->project))->toBeTrue()
        ->and($admin->can('award', $this->project))->toBeTrue()
        ->and($admin->can('certify', $this->project))->toBeTrue()
        ->and($admin->can('update', $this->foreignProject))->toBeFalse()
        ->and($admin->can('view', $this->foreignProject))->toBeFalse();
});

it('stops an M&E officer at certification, publishing and deletion', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer)->fresh();

    expect($officer->can('update', $this->project))->toBeTrue()
        ->and($officer->can('updateProgress', $this->project))->toBeTrue()
        ->and($officer->can('assign', $this->project))->toBeTrue()
        ->and($officer->can('certify', $this->project))->toBeFalse()
        ->and($officer->can('publish', $this->project))->toBeFalse()
        ->and($officer->can('delete', $this->project))->toBeFalse();
});

it('narrows a consultant to their assignments and refuses them every write', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant)->fresh();

    expect($consultant->can('view', $this->project))->toBeFalse()   // unassigned
        ->and($consultant->can('update', $this->project))->toBeFalse()
        ->and($consultant->can('updateStatus', $this->project))->toBeFalse()
        ->and($consultant->can('updateProgress', $this->project))->toBeFalse()
        ->and($consultant->can('assign', $this->project))->toBeFalse();

    ProjectAssignment::factory()
        ->forProject($this->project)
        ->forUser($consultant)
        ->consultant()
        ->create();

    expect($consultant->fresh()->can('view', $this->project))->toBeTrue();
});

it('gives a field monitor the same assignment-narrowed read and no writes', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor)->fresh();

    expect($monitor->can('view', $this->project))->toBeFalse()
        ->and($monitor->can('update', $this->project))->toBeFalse()
        ->and($monitor->can('view', Contractor::factory()->create()))->toBeFalse();
});

it('lets state oversight act across MDAs on exactly the permissions it was granted', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);

    // The oversight surface binds no tenant — that is what "across MDAs"
    // means, and the context these permissions are exercised in.
    actingWithoutTenant();

    // Granted state-wide by the matrix: view, suspend, close, cancel, publish.
    expect($stateAdmin->can('view', $this->project))->toBeTrue()
        ->and($stateAdmin->can('view', $this->foreignProject))->toBeTrue()
        ->and($stateAdmin->can('suspend', $this->project))->toBeTrue()
        ->and($stateAdmin->can('close', $this->project))->toBeTrue()
        ->and($stateAdmin->can('cancel', $this->project))->toBeTrue()
        // NOT granted: a state administrator does not run an MDA's registry.
        ->and($stateAdmin->can('create', Project::class))->toBeFalse()
        ->and($stateAdmin->can('update', $this->project))->toBeFalse()
        ->and($stateAdmin->can('certify', $this->project))->toBeFalse()
        ->and($stateAdmin->can('updateProgress', $this->project))->toBeFalse();
});

it('still refuses oversight a foreign project while a different workspace is bound', function () {
    // Binding works and asking about a health project is a context error, not
    // an authority question — the answer is no even for state oversight, so a
    // tenant-surface request can never be talked into a cross-MDA read.
    $stateAdmin = userWithRole(Role::StateAdmin);

    actingOnTenant($this->works);

    expect($stateAdmin->can('view', $this->foreignProject))->toBeFalse();
});

it('keeps an executive viewer entirely read-only', function () {
    $viewer = userWithRole(Role::ExecutiveViewer);

    expect($viewer->can('view', $this->project))->toBeTrue()
        ->and($viewer->can('viewAny', Project::class))->toBeTrue()
        ->and($viewer->can('update', $this->project))->toBeFalse()
        ->and($viewer->can('suspend', $this->project))->toBeFalse()
        ->and($viewer->can('publish', $this->project))->toBeFalse()
        ->and($viewer->can('create', Contractor::class))->toBeFalse();
});

it('scopes contract authority to the workspace that holds the contract', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin)->fresh();

    $contract = Contract::factory()->forProject($this->project)->create(['created_by_id' => $admin->id]);

    $foreignContract = app(CurrentTenant::class)->runAs($this->health, fn () => Contract::factory()
        ->forProject($this->foreignProject)
        ->create(['created_by_id' => $admin->id]));

    actingOnTenant($this->works);

    expect($admin->can('view', $contract))->toBeTrue()
        ->and($admin->can('update', $contract))->toBeTrue()
        ->and($admin->can('view', $foreignContract))->toBeFalse();
});

it('splits contractor authority: any workspace may add, only the state may manage', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin)->fresh();
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer)->fresh();
    $stateAdmin = userWithRole(Role::StateAdmin);
    $contractor = Contractor::factory()->create();

    expect($admin->can('create', Contractor::class))->toBeTrue()
        ->and($officer->can('create', Contractor::class))->toBeTrue()
        ->and($admin->can('update', $contractor))->toBeFalse()
        ->and($admin->can('blacklist', $contractor))->toBeFalse()
        ->and($stateAdmin->can('update', $contractor))->toBeTrue()
        ->and($stateAdmin->can('blacklist', $contractor))->toBeTrue();
});

it('holds the line for a user with no role at all', function () {
    $nobody = User::factory()->create();

    expect($nobody->can('view', $this->project))->toBeFalse()
        ->and($nobody->can('viewAny', Project::class))->toBeFalse()
        ->and($nobody->can('create', Project::class))->toBeFalse();
});
