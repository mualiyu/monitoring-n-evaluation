<?php

/**
 * The listener/job/notification chain behind ProjectStatusChanged.
 *
 * The tenancy case is the one that matters: the job carries IDS and rebinds
 * the tenant through TenantAware, because a serialized Project would be
 * restored — and re-queried — before the job middleware ever runs, in a worker
 * with no tenant bound.
 */

use App\Actions\Projects\TransitionProjectStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Jobs\Projects\NotifyProjectStatusChange;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Projects\ProjectStatusUpdated;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->project = Project::factory()->ongoing()->create();
});

it('notifies the people accountable for the project, by database and mail', function () {
    Notification::fake();

    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    ProjectAssignment::factory()->forProject($this->project)->forUser($monitor)->fieldMonitor()->create();

    (new TransitionProjectStatus)($this->project, ProjectStatus::Suspended, $this->admin, 'Contractor off site.');

    Notification::assertSentTo($monitor, ProjectStatusUpdated::class, function (ProjectStatusUpdated $notification) use ($monitor) {
        $payload = $notification->toArray($monitor);

        return $notification->via($monitor) === ['database', 'mail']
            && $payload['to_status'] === ProjectStatus::Suspended->value
            && $payload['from_status'] === ProjectStatus::InProgress->value
            && $payload['reason'] === 'Contractor off site.'
            && $payload['project_ulid'] === $this->project->ulid;
    });
});

it('leaves out the actor, and anyone whose assignment has ended', function () {
    Notification::fake();

    $former = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    ProjectAssignment::factory()
        ->forProject($this->project)
        ->forUser($former)
        ->consultant()
        ->unassigned()
        ->create();

    ProjectAssignment::factory()
        ->forProject($this->project)
        ->forUser($this->admin)
        ->supervisor()
        ->create();

    (new TransitionProjectStatus)($this->project, ProjectStatus::Suspended, $this->admin, 'Contractor off site.');

    Notification::assertNothingSent();
});

it('also tells the project manager', function () {
    Notification::fake();

    $manager = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->project->update(['manager_id' => $manager->id]);

    (new TransitionProjectStatus)($this->project, ProjectStatus::Suspended, $this->admin, 'Funding paused.');

    Notification::assertSentTo($manager, ProjectStatusUpdated::class);
});

it('carries its tenant into the worker instead of a serialized model', function () {
    $job = new NotifyProjectStatusChange(
        $this->project->id,
        ProjectStatus::InProgress->value,
        ProjectStatus::Suspended->value,
        $this->admin->id,
        'Funding paused.',
    );

    expect($job->tenantId)->toBe($this->works->id);

    Notification::fake();

    // The worker starts with no tenant bound; SetTenantContext rebinds it from
    // the captured id, and the queries inside handle() are scoped again.
    actingWithoutTenant();
    dispatch($job);

    expect(app(CurrentTenant::class)->bound())->toBeFalse();
});

it('says nothing at all when a project has nobody accountable for it yet', function () {
    Notification::fake();

    (new TransitionProjectStatus)($this->project, ProjectStatus::Suspended, $this->admin, 'Funding paused.');

    Notification::assertNothingSent();
});
