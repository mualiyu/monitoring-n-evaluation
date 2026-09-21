<?php

/**
 * The authorization matrix for evaluations and the follow-up register: one
 * role at a time, and always BOTH ways — what the role may do, and what it
 * may not. A matrix of refusals proves only that nothing works.
 *
 * Two rules here are structural rather than incidental, and each gets said
 * out loud:
 *
 *  - `evaluations.manage` and `evaluations.approve` are DIFFERENT permissions.
 *    An M&E officer writes the report and files it; a director signs for it.
 *    That split is the structural half of the separation of duties, and the
 *    identity half (the lead cannot approve their own work) is proven in
 *    EvaluationLifecycleTest against the chokepoint.
 *  - `update` is a STATE rule on top of the permission. Findings freeze when
 *    the report goes up for review, so holding `evaluations.manage` lets you
 *    write a draft, not rewrite an approved finding.
 */

use App\Actions\Oversight\ListEvaluationsAcrossTenants;
use App\Actions\Oversight\ListRecommendationsAcrossTenants;
use App\Enums\Role;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->director = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);
    $this->evaluation = Evaluation::factory()->forProject($this->project)->planned()->create();
    $this->recommendation = Recommendation::factory()->from($this->evaluation)->open()->create();

    $this->current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->executive = userWithRole(Role::ExecutiveViewer);
    $this->reviewer = userWithRole(Role::DataQualityReviewer);

    $this->current->forget();
});

/* -------------------------------------------------------------------------- */
/* The workspace roles */
/* -------------------------------------------------------------------------- */

it('lets an M&E officer write and file an evaluation, and never sign for it', function () {
    actingOnTenant($this->works);

    // The structural half of the separation: `evaluations.manage` and
    // `evaluations.approve` are different permissions, and the officer holds
    // only the first.
    expect(Gate::forUser($this->officer)->allows('viewAny', Evaluation::class))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('view', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('create', Evaluation::class))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('update', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('submit', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('approve', $this->evaluation))->toBeFalse()
        ->and(Gate::forUser($this->officer)->allows('publish', $this->evaluation))->toBeFalse()
        // Abandoning a commission is the one move that can make an
        // inconvenient finding go away, so it sits with the approvers.
        ->and(Gate::forUser($this->officer)->allows('cancel', $this->evaluation))->toBeFalse();
});

it('lets the entity director approve, publish and cancel', function () {
    actingOnTenant($this->works);

    expect(Gate::forUser($this->director)->allows('approve', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->director)->allows('publish', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->director)->allows('cancel', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->director)->allows('update', $this->evaluation))->toBeTrue();
});

it('keeps project-level roles out of the evaluation module entirely', function (string $actor) {
    actingOnTenant($this->works);

    // An evaluation of a contractor's own delivery is not the contractor's to
    // read while it is being written, and a field monitor's job is the
    // inspection register, not the evaluation one.
    expect(Gate::forUser($this->{$actor})->allows('viewAny', Evaluation::class))->toBeFalse()
        ->and(Gate::forUser($this->{$actor})->allows('view', $this->evaluation))->toBeFalse()
        ->and(Gate::forUser($this->{$actor})->allows('create', Evaluation::class))->toBeFalse()
        ->and(Gate::forUser($this->{$actor})->allows('viewAny', Recommendation::class))->toBeFalse()
        ->and(Gate::forUser($this->{$actor})->allows('transition', $this->recommendation))->toBeFalse();
})->with([
    'consultant' => ['consultant'],
    'field monitor' => ['monitor'],
]);

it('freezes an evaluation against editing from the moment it goes up for review', function (string $state, bool $editable) {
    actingOnTenant($this->works);

    /** @var Evaluation $evaluation */
    $evaluation = Evaluation::factory()->forProject($this->project)->{$state}()->create();

    // The director holds `evaluations.manage` throughout — what changes is the
    // record's state, which is the point. An approved evaluation's findings
    // are what an approver signed for, and a module that let them be edited
    // afterwards would make the signature worthless.
    expect(Gate::forUser($this->director)->allows('update', $evaluation))->toBe($editable);
})->with([
    'planned' => ['planned', true],
    'in progress' => ['inProgress', true],
    'draft report' => ['draftReport', true],
    'under review' => ['underReview', false],
    'approved' => ['approved', false],
    'published' => ['published', false],
    'cancelled' => ['cancelled', false],
]);

it('lets a recommendation be re-worded only while it is still open', function (string $state, bool $editable) {
    actingOnTenant($this->works);

    /** @var Recommendation $recommendation */
    $recommendation = Recommendation::factory()->from($this->evaluation)->{$state}()->create();

    // Once somebody has accepted it, the text is what they accepted; editing
    // it afterwards would quietly change the thing the evidence is evidence
    // FOR. Moving it through the loop stays open either way — that records
    // what was DONE about it.
    expect(Gate::forUser($this->officer)->allows('update', $recommendation))->toBe($editable)
        ->and(Gate::forUser($this->officer)->allows('transition', $recommendation))->toBeTrue();
})->with([
    'open' => ['open', true],
    'accepted' => ['accepted', true],
    'being implemented' => ['inProgress', true],
    'implemented' => ['implemented', false],
    'declined' => ['rejected', false],
    'superseded' => ['superseded', false],
]);

/* -------------------------------------------------------------------------- */
/* Visibility narrows on the project, not on the role name */
/* -------------------------------------------------------------------------- */

it('narrows an evaluation to the projects its reader can already see', function () {
    actingOnTenant($this->works);

    $unassigned = Project::factory()->ongoing()->create(['title' => 'Storm Drainage Upgrade']);

    $assignedEvaluation = Evaluation::factory()->forProject($this->project)->create();
    $foreignEvaluation = Evaluation::factory()->forProject($unassigned)->create();
    $programmeEvaluation = Evaluation::factory()->programme('Rural Water Supply Programme')->create();

    // A project-level role granted `evaluations.view` directly — the only way
    // the narrowing can be exercised, since the seeded matrix never gives a
    // consultant the permission in the first place. Without this case the
    // scope would be untested code.
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->director->id,
    ]);

    setPermissionsTeamId($this->works->id);
    $this->consultant->givePermissionTo('evaluations.view');
    $this->consultant->forgetCachedPermissions();
    $reader = $this->consultant->refresh();

    expect(Gate::forUser($reader)->allows('view', $assignedEvaluation))->toBeTrue()
        // A user who cannot see a road cannot see the evaluation OF that road.
        ->and(Gate::forUser($reader)->allows('view', $foreignEvaluation))->toBeFalse()
        // A programme evaluation has no project to narrow on, so it is visible
        // to anyone the permission already admitted.
        ->and(Gate::forUser($reader)->allows('view', $programmeEvaluation))->toBeTrue();

    // The list screens run this same scope, so the policy and the query can
    // never disagree about a single row.
    expect(Evaluation::query()->visibleTo($reader)->pluck('id')->all())
        ->toContain($assignedEvaluation->id)
        ->toContain($programmeEvaluation->id)
        ->not->toContain($foreignEvaluation->id);
});

it('refuses to view an evaluation belonging to another entity, whatever the permission says', function () {
    $foreign = $this->current->runAs($this->health, fn (): Evaluation => Evaluation::factory()->create());

    actingOnTenant($this->works);

    // Permission AND tenant match: the global scope makes a foreign model hard
    // to load, never impossible — relations, oversight bypasses and route
    // bindings all hand policies foreign models.
    expect(Gate::forUser($this->director)->allows('view', $foreign))->toBeFalse()
        ->and(Gate::forUser($this->director)->allows('update', $foreign))->toBeFalse()
        ->and(Gate::forUser($this->director)->allows('approve', $foreign))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The oversight roles */
/* -------------------------------------------------------------------------- */

it('lets the secretariat commission and approve, because it commissions evaluations of entities', function () {
    actingOnTenant($this->works);

    expect(Gate::forUser($this->stateAdmin)->allows('viewAny', Evaluation::class))->toBeTrue()
        ->and(Gate::forUser($this->stateAdmin)->allows('create', Evaluation::class))->toBeTrue()
        ->and(Gate::forUser($this->stateAdmin)->allows('approve', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->stateAdmin)->allows('transition', $this->recommendation))->toBeTrue();
});

it('lets an executive viewer read both registers and change neither', function () {
    actingOnTenant($this->works);

    // Read-only oversight, asserted as read-only rather than merely as
    // denied: a viewer who could see nothing would pass a denial-only test
    // for entirely the wrong reason.
    expect(Gate::forUser($this->executive)->allows('viewAny', Evaluation::class))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('view', $this->evaluation))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('viewAny', Recommendation::class))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('view', $this->recommendation))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('create', Evaluation::class))->toBeFalse()
        ->and(Gate::forUser($this->executive)->allows('update', $this->evaluation))->toBeFalse()
        ->and(Gate::forUser($this->executive)->allows('approve', $this->evaluation))->toBeFalse()
        ->and(Gate::forUser($this->executive)->allows('publish', $this->evaluation))->toBeFalse()
        ->and(Gate::forUser($this->executive)->allows('cancel', $this->evaluation))->toBeFalse()
        // Nor may the governor's office close somebody else's follow-up item.
        ->and(Gate::forUser($this->executive)->allows('transition', $this->recommendation))->toBeFalse()
        ->and(Gate::forUser($this->executive)->allows('update', $this->recommendation))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The cross-MDA boards are a named privilege, not a convenience */
/* -------------------------------------------------------------------------- */

it('opens the cross-entity boards to every oversight role that holds the read permission', function (string $actor) {
    $this->current->runAs($this->works, fn () => Evaluation::factory()->create());
    $this->current->runAs($this->health, fn () => Evaluation::factory()->create());
    $this->current->forget();

    expect((new ListEvaluationsAcrossTenants)($this->{$actor})->total())->toBe(3)
        ->and((new ListRecommendationsAcrossTenants)($this->{$actor})->total())->toBe(1);
})->with([
    'state admin' => ['stateAdmin'],
    'executive viewer' => ['executive'],
    'data quality reviewer' => ['reviewer'],
]);

it('refuses the cross-entity boards to a workspace role, however strong', function (string $actor) {
    $this->current->forget();

    // `evaluations.view` held inside a workspace is exactly the shape that
    // would sneak past a check made AFTER the tenancy bypass. The Action asks
    // in the GLOBAL team, before bypassing anything.
    expect(fn () => (new ListEvaluationsAcrossTenants)($this->{$actor}))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => (new ListRecommendationsAcrossTenants)($this->{$actor}))
        ->toThrow(AuthorizationException::class);
})->with([
    'entity director' => ['director'],
    'M&E officer' => ['officer'],
]);

it('shows the state board every entity’s rows and the workspace list only its own', function () {
    $this->current->runAs($this->health, fn () => Evaluation::factory()->create());
    $this->current->forget();

    expect((new ListEvaluationsAcrossTenants)($this->stateAdmin)->total())->toBe(2)
        ->and($this->current->runAs($this->works, fn (): int => Evaluation::query()->count()))->toBe(1);
});
