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
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\FundingSource;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\ProjectFundingSource;
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
