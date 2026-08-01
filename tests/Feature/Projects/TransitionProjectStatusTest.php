<?php

/**
 * TransitionProjectStatus is the ONLY writer of Project::$status
 * (projects-module.md §2.1). ProjectStatusTransitionTest proves the enum's
 * table in isolation; this file proves the Action around it — authorization
 * per target status, the domain preconditions, the typed ledger row and the
 * event — because a correct transition table guarded by nothing is a project
 * that can be certified at 12% progress by whoever holds a login.
 */

use App\Actions\Projects\TransitionProjectStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Events\Projects\ProjectStatusChanged;
use App\Exceptions\Projects\InvalidStatusTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->transition = new TransitionProjectStatus;
});

it('refuses a transition the lifecycle table does not describe', function () {
    $project = Project::factory()->draft()->create();

    expect(fn () => ($this->transition)($project, ProjectStatus::Completed, $this->admin))
        ->toThrow(InvalidStatusTransition::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft);
});

it('refuses to move a cancelled project anywhere at all', function () {
    $project = Project::factory()->cancelled()->create();

    expect(fn () => ($this->transition)($project, ProjectStatus::InProgress, $this->admin))
        ->toThrow(InvalidStatusTransition::class, 'terminal state');
});

it('refuses to award a project that has no contract behind it', function () {
    $project = Project::factory()->draft()->create();

    expect(fn () => ($this->transition)($project, ProjectStatus::Awarded, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'at least one contract');
});

it('awards a project once a contract exists, and records the transition', function () {
    $project = Project::factory()->draft()->create();
    Contract::factory()->forProject($project)->create(['created_by_id' => $this->admin->id]);

    ($this->transition)($project, ProjectStatus::Awarded, $this->admin, 'Contract signed.');

    $project->refresh();
    $event = ProjectStatusEvent::query()->where('project_id', $project->id)->latest('id')->firstOrFail();

    expect($project->status)->toBe(ProjectStatus::Awarded)
        ->and($project->status_changed_at)->not->toBeNull()
        ->and($event->from_status)->toBe(ProjectStatus::Draft)
        ->and($event->to_status)->toBe(ProjectStatus::Awarded)
        ->and($event->actor_id)->toBe($this->admin->id)
        ->and($event->reason)->toBe('Contract signed.')
        ->and($event->occurred_at)->not->toBeNull();
});

it('refuses to complete a project that is not actually finished', function () {
    $project = Project::factory()->ongoing()->create(['physical_progress' => '99.00']);

    expect(fn () => ($this->transition)($project, ProjectStatus::Completed, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'completion means 100%');
});

it('completes a fully delivered project and starts the post-completion clock', function () {
    $project = Project::factory()->ongoing()->create(['physical_progress' => '100.00']);

    ($this->transition)($project, ProjectStatus::Completed, $this->admin);

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Completed)
        ->and($project->actual_end_date->toDateString())->toBe(now()->toDateString())
        // config default: 6 months of post-completion monitoring
        ->and($project->post_completion_review_due_at->toDateString())
        ->toBe(now()->addMonths(6)->toDateString());
});

it('reads the post-completion window from settings rather than a literal', function () {
    config()->set('platform.monitoring.post_completion_review_months', 12);

    $project = Project::factory()->ongoing()->create(['physical_progress' => '100.00']);

    ($this->transition)($project, ProjectStatus::Completed, $this->admin);

    expect($project->fresh()->post_completion_review_due_at->toDateString())
        ->toBe(now()->addMonths(12)->toDateString());
});

it('refuses to suspend or cancel without a stated reason', function (ProjectStatus $to) {
    $project = Project::factory()->ongoing()->create();

    expect(fn () => ($this->transition)($project, $to, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'requires a stated reason')
        ->and(fn () => ($this->transition)($project, $to, $this->admin, '   '))
        ->toThrow(ProjectRuleViolation::class);
})->with([
    'suspension' => [ProjectStatus::Suspended],
    'cancellation' => [ProjectStatus::Cancelled],
]);

it('suspends with a reason and keeps it on the record', function () {
    $project = Project::factory()->ongoing()->create();

    ($this->transition)($project, ProjectStatus::Suspended, $this->admin, 'Site abandoned by the contractor.');

    expect($project->fresh()->status)->toBe(ProjectStatus::Suspended)
        ->and(ProjectStatusEvent::query()->where('project_id', $project->id)->latest('id')->firstOrFail()->reason)
        ->toBe('Site abandoned by the contractor.');
});

it('refuses to close a certified project before its review window ends', function () {
    $project = Project::factory()->certified()->create([
        'post_completion_review_due_at' => now()->addMonths(3)->toDateString(),
    ]);

    expect(fn () => ($this->transition)($project, ProjectStatus::Closed, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'post-completion review window');
});

it('closes a certified project once the review window has passed', function () {
    $project = Project::factory()->certified()->create([
        'post_completion_review_due_at' => now()->subDay()->toDateString(),
    ]);

    ($this->transition)($project, ProjectStatus::Closed, $this->admin);

    expect($project->fresh()->status)->toBe(ProjectStatus::Closed);
});

it('lets a state administrator close early with a reason, and refuses the same override to an MDA admin', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $early = Project::factory()->certified()->create([
        'post_completion_review_due_at' => now()->addMonths(4)->toDateString(),
    ]);

    expect(fn () => ($this->transition)($early, ProjectStatus::Closed, $this->admin, 'Handed to a federal agency.', true))
        ->toThrow(ProjectRuleViolation::class, 'state-level administrator');

    ($this->transition)($early, ProjectStatus::Closed, $stateAdmin, 'Handed to a federal agency.', true);

    expect($early->fresh()->status)->toBe(ProjectStatus::Closed);
});

it('stamps the review clock at certification when completion never did', function () {
    // The imported/legacy shape: an end date, no due date.
    $project = Project::factory()->completed()->create([
        'actual_end_date' => now()->subMonth()->toDateString(),
        'post_completion_review_due_at' => null,
    ]);

    ($this->transition)($project, ProjectStatus::Certified, $this->admin);

    expect($project->fresh()->post_completion_review_due_at->toDateString())
        ->toBe(now()->subMonth()->addMonths(6)->toDateString());
});

it('holds the Phase 2 certification guard behind its setting', function () {
    $project = Project::factory()->completed()->create();

    ($this->transition)($project, ProjectStatus::Certified, $this->admin);
    expect($project->fresh()->status)->toBe(ProjectStatus::Certified);

    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    $other = Project::factory()->completed()->create();

    expect(fn () => ($this->transition)($other, ProjectStatus::Certified, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'final inspection');
});

/*
|--------------------------------------------------------------------------
| Authorization — per TARGET status (§2)
|--------------------------------------------------------------------------
*/

it('refuses certification to an M&E officer, who does not sign completion certificates', function () {
    $project = Project::factory()->completed()->create();

    expect(fn () => ($this->transition)($project, ProjectStatus::Certified, $this->officer))
        ->toThrow(AuthorizationException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::Completed);
});

it('refuses every transition to a consultant', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $project = Project::factory()->ongoing()->create();

    expect(fn () => ($this->transition)($project, ProjectStatus::Completed, $consultant->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('lets an M&E officer move a project through the ordinary lifecycle', function () {
    $project = Project::factory()->awarded()->create();

    ($this->transition)($project, ProjectStatus::Mobilized, $this->officer);
    ($this->transition)($project, ProjectStatus::InProgress, $this->officer);

    expect($project->fresh()->status)->toBe(ProjectStatus::InProgress);
});

it('keeps resumption from suspension with the authority that can suspend', function () {
    $project = Project::factory()->suspended()->create();

    // §2's suspended row: MdaAdmin / StateAdmin. An M&E officer holds
    // projects.status.update but not projects.suspend, so they cannot quietly
    // restart works a higher authority stopped.
    expect(fn () => ($this->transition)($project, ProjectStatus::InProgress, $this->officer))
        ->toThrow(AuthorizationException::class);

    ($this->transition)($project, ProjectStatus::InProgress, $this->admin);

    expect($project->fresh()->status)->toBe(ProjectStatus::InProgress);
});

it('refuses a project belonging to another workspace', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $foreign = app(CurrentTenant::class)->runAs(
        $health,
        fn () => Project::factory()->ongoing()->create(),
    );

    actingOnTenant($this->works);

    expect(fn () => ($this->transition)($foreign, ProjectStatus::Completed, $this->admin))
        ->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| The event
|--------------------------------------------------------------------------
*/

it('fires ProjectStatusChanged carrying the transition, not just the project', function () {
    Event::fake([ProjectStatusChanged::class]);

    $project = Project::factory()->ongoing()->create();

    ($this->transition)($project, ProjectStatus::Suspended, $this->admin, 'Funding paused.');

    Event::assertDispatched(
        ProjectStatusChanged::class,
        fn (ProjectStatusChanged $event) => $event->project->is($project)
            && $event->from === ProjectStatus::InProgress
            && $event->to === ProjectStatus::Suspended
            && $event->actorId === $this->admin->id
            && $event->reason === 'Funding paused.',
    );
});

it('fires nothing when the transition is refused', function () {
    Event::fake([ProjectStatusChanged::class]);

    $project = Project::factory()->draft()->create();

    expect(fn () => ($this->transition)($project, ProjectStatus::Awarded, $this->admin))
        ->toThrow(ProjectRuleViolation::class);

    Event::assertNotDispatched(ProjectStatusChanged::class);
});
