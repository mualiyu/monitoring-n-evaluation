<?php

/**
 * Government records: what may be deleted, and what leaves a trace.
 *
 * Covers migration review §5 (one delete story — restrict all the way up) and
 * §10 (funding splits are hard-deleted, with the activity log standing in for
 * soft deletes), plus the architecture rule that every create/update/delete on
 * a domain model is auditable.
 */

use App\Actions\Projects\BlacklistContractor;
use App\Actions\Projects\SetProjectFundingSources;
use App\Actions\Projects\UnassignProjectMember;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\FundingSource;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->stateAdmin = userWithRole(Role::StateAdmin);
});

/*
|--------------------------------------------------------------------------
| The delete story (migration review §5)
|--------------------------------------------------------------------------
*/

it('refuses to force-delete a project out from under its children', function () {
    $project = Project::factory()->ongoing()->create();
    Contract::factory()->forProject($project)->create(['created_by_id' => $this->admin->id]);

    // Restrict all the way up: the purge fails at the project, once and
    // loudly, instead of cascading into indicators and dying on the readings
    // foreign key half-way down.
    expect(fn () => $project->forceDelete())->toThrow(QueryException::class);

    expect(Project::query()->withTrashed()->whereKey($project->id)->exists())->toBeTrue();
});

it('refuses to force-delete a project that only has monitoring children', function () {
    $project = Project::factory()->ongoing()->create();
    Indicator::factory()->forProject($project)->create();

    expect(fn () => $project->forceDelete())->toThrow(QueryException::class);
});

it('soft-deletes instead, which is what archiving means here', function () {
    $project = Project::factory()->draft()->create();

    $project->delete();

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
        ->and(Project::query()->withTrashed()->whereKey($project->id)->exists())->toBeTrue();
});

it('keeps the transition ledger append-only', function () {
    $project = Project::factory()->ongoing()->create();

    $event = ProjectStatusEvent::create([
        'project_id' => $project->id,
        'from_status' => ProjectStatus::Mobilized,
        'to_status' => ProjectStatus::InProgress,
        'actor_id' => $this->admin->id,
        'occurred_at' => now(),
    ]);

    expect(fn () => $event->update(['reason' => 'Rewritten later']))->toThrow(RuntimeException::class)
        ->and(fn () => $event->delete())->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| The audit trail
|--------------------------------------------------------------------------
*/

it('logs the chokepoint columns that are deliberately not fillable', function () {
    $project = Project::factory()->ongoing()->create(['physical_progress' => '10.00']);

    $project->forceFill(['physical_progress' => '55.00'])->save();

    $activity = Activity::query()
        ->where('subject_type', $project->getMorphClass())
        ->where('subject_id', $project->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($activity->attribute_changes['attributes']['physical_progress'])->toBe('55.00')
        ->and($activity->attribute_changes['old']['physical_progress'])->toBe('10.00');
});

it('logs money as a number, not as an empty object', function () {
    // A Money value object JSON-encodes to {} — the reason the money columns
    // are logged as RAW attributes. What the driver hands back is a decimal
    // string on MySQL and a numeric on SQLite; either is a readable figure,
    // which is the whole point.
    $project = Project::factory()->draft()->create();

    $project->forceFill(['contract_value_total' => '125000000.00'])->save();

    $activity = Activity::query()
        ->where('subject_type', $project->getMorphClass())
        ->where('subject_id', $project->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect(Money::fromDecimalString((string) $activity->attribute_changes['attributes']['contract_value_total'])->toDecimalString())
        ->toBe('125000000.00');
});

it('logs a blacklisting on the vendor registry, with the reason', function () {
    $contractor = Contractor::factory()->create();

    (new BlacklistContractor)($contractor, $this->stateAdmin, 'Abandoned two sites.');

    $activity = Activity::query()
        ->where('subject_type', $contractor->getMorphClass())
        ->where('subject_id', $contractor->id)
        ->latest('id')
        ->firstOrFail();

    expect($activity->attribute_changes['attributes']['is_blacklisted'])->toBeTrue()
        ->and($activity->attribute_changes['attributes']['blacklist_reason'])->toBe('Abandoned two sites.');
});

/*
|--------------------------------------------------------------------------
| "Everything auditable" means every tenant-owned model, not the headline ones
|--------------------------------------------------------------------------
| The four models below were the module's audit blind spots: who was put on a
| project, where a site sits, what an indicator was asked to reach and what it
| actually read. Each is a record an auditor asks "who changed this, and from
| what" about, and none of them logged anything.
*/

it('logs a create on every tenant-owned model of the slice', function (string $model, string $logName, Closure $create) {
    $subject = $create();

    $activity = Activity::query()
        ->where('subject_type', (new $model)->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('event', 'created')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe($logName);
})->with([
    'assignments' => [
        ProjectAssignment::class, 'project_assignments',
        fn () => ProjectAssignment::factory()->create(),
    ],
    'sites' => [
        ProjectLocation::class, 'project_locations',
        fn () => ProjectLocation::factory()->primary()->create(),
    ],
    'indicator targets' => [
        IndicatorTarget::class, 'indicator_targets',
        fn () => IndicatorTarget::factory()->create(),
    ],
    'indicator readings' => [
        IndicatorReading::class, 'indicator_readings',
        fn () => IndicatorReading::factory()->create(),
    ],
]);

it('records the before and after when a site quietly moves', function () {
    $location = ProjectLocation::factory()->primary()->create([
        'latitude' => '9.0570000',
        'longitude' => '7.4950000',
    ]);

    $location->update(['latitude' => '9.9990000']);

    $activity = Activity::query()
        ->where('subject_type', $location->getMorphClass())
        ->where('subject_id', $location->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    // A site that shifts 100km is how an inspection ends up "verifying" a
    // different facility. The pair is the only way to notice after the fact.
    expect((float) $activity->attribute_changes['attributes']['latitude'])->toBe(9.999)
        ->and((float) $activity->attribute_changes['old']['latitude'])->toBe(9.057);
});

it('records a lowered indicator target, the classic M&E fabrication', function () {
    $target = IndicatorTarget::factory()->create(['target_value' => '1000.0000']);

    $target->update(['target_value' => '100.0000']);

    $activity = Activity::query()
        ->where('subject_type', $target->getMorphClass())
        ->where('subject_id', $target->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect((float) $activity->attribute_changes['old']['target_value'])->toBe(1000.0)
        ->and((float) $activity->attribute_changes['attributes']['target_value'])->toBe(100.0);
});

it('names who took a member off a project, which no column on the row records', function () {
    $project = Project::factory()->ongoing()->create();
    $member = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $assignment = ProjectAssignment::factory()->consultant()->forProject($project)->create([
        'user_id' => $member->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    // A different officer from the one who assigned them — otherwise the test
    // would pass on assigned_by_id alone and prove nothing.
    $remover = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    (new UnassignProjectMember)($assignment, $remover);

    $activity = Activity::query()
        ->where('subject_type', $assignment->getMorphClass())
        ->where('subject_id', $assignment->id)
        ->where('description', 'unassigned')
        ->latest('id')
        ->firstOrFail();

    expect($activity->causer_id)->toBe($remover->id)
        ->and($activity->log_name)->toBe('project_assignments')
        ->and($assignment->fresh()->assigned_by_id)->toBe($this->admin->id);
});

it('states the actor even with nobody authenticated, as a queue worker has', function () {
    $assignment = ProjectAssignment::factory()->create();
    $remover = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    // auth() is empty here — exactly a queued job or a console command. The
    // model's own log would infer no causer at all; the Action states it.
    expect(auth()->user())->toBeNull();

    (new UnassignProjectMember)($assignment, $remover);

    $activity = Activity::query()
        ->where('subject_type', $assignment->getMorphClass())
        ->where('subject_id', $assignment->id)
        ->where('description', 'unassigned')
        ->firstOrFail();

    expect($activity->causer_id)->toBe($remover->id);
});

it('leaves a trace when a funding split is removed, which is why it needs no soft delete', function () {
    $project = Project::factory()->draft()->create();
    $donor = FundingSource::factory()->create();

    (new SetProjectFundingSources)($project, $this->admin, [
        ['funding_source_id' => $donor->id, 'percentage' => '60.00', 'amount' => '60000000.00'],
    ]);

    (new SetProjectFundingSources)($project, $this->admin, []);

    $deletion = Activity::query()
        ->where('subject_type', (new ProjectFundingSource)->getMorphClass())
        ->where('event', 'deleted')
        ->latest('id')
        ->firstOrFail();

    expect($deletion->attribute_changes['old']['percentage'])->toBe('60.00')
        ->and(Money::fromDecimalString((string) $deletion->attribute_changes['old']['amount'])->toDecimalString())
        ->toBe('60000000.00');
});
