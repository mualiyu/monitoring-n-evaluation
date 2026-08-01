<?php

/**
 * Factories are the source of truth for valid data (rules/testing.md), so every
 * state a later slice will build on has to actually persist — a factory state
 * that only compiles is a test fixture that fails the first time someone uses
 * it. Each tenant-owned state is built inside runAs() and must come back with
 * the bound tenant stamped on it; built outside a workspace it must throw,
 * which is the intended teaching moment (projects-module.md §7).
 */

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\FirmType;
use App\Enums\FundingSourceType;
use App\Enums\IndicatorReadingStatus;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ReadingSourceType;
use App\Enums\TargetType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\FundingSource;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Ward;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;

/** Every tenant-owned factory state in the slice, by name. */
dataset('tenant-owned factory states', [
    'project: default' => [fn () => Project::factory()->create()],
    'project: draft' => [fn () => Project::factory()->draft()->create()],
    'project: awarded' => [fn () => Project::factory()->awarded()->create()],
    'project: mobilized' => [fn () => Project::factory()->mobilized()->create()],
    'project: ongoing' => [fn () => Project::factory()->ongoing()->create()],
    'project: behind schedule' => [fn () => Project::factory()->behindSchedule()->create()],
    'project: completed' => [fn () => Project::factory()->completed()->create()],
    'project: certified' => [fn () => Project::factory()->certified()->create()],
    'project: closed' => [fn () => Project::factory()->closed()->create()],
    'project: suspended' => [fn () => Project::factory()->suspended()->create()],
    'project: cancelled' => [fn () => Project::factory()->cancelled()->create()],
    'project: multi-site' => [fn () => Project::factory()->multiSite()->create()],
    'project: co-funded' => [fn () => Project::factory()->coFunded()->create()],
    'project: programme type' => [fn () => Project::factory()->ofType(ProjectType::Programme)->create()],
    'project location: default' => [fn () => ProjectLocation::factory()->create()],
    'project location: primary' => [fn () => ProjectLocation::factory()->primary()->create()],
    'project location: without coordinates' => [fn () => ProjectLocation::factory()->withoutCoordinates()->create()],
    'project funding source: default' => [fn () => ProjectFundingSource::factory()->create()],
    'project funding source: share' => [fn () => ProjectFundingSource::factory()->share(70, true)->create()],
    'project funding source: of amount' => [fn () => ProjectFundingSource::factory()->ofAmount('1250000.00')->create()],
    'contract: default' => [fn () => Contract::factory()->create()],
    'contract: variation' => [fn () => Contract::factory()->variation()->create()],
    'contract: active' => [fn () => Contract::factory()->active()->create()],
    'contract: completed' => [fn () => Contract::factory()->completed()->create()],
    'contract: terminated' => [fn () => Contract::factory()->terminated()->create()],
    'contract: consultancy type' => [fn () => Contract::factory()->ofType(ContractType::Consultancy)->create()],
    'assignment: default' => [fn () => ProjectAssignment::factory()->create()],
    'assignment: consultant' => [fn () => ProjectAssignment::factory()->consultant()->create()],
    'assignment: field monitor' => [fn () => ProjectAssignment::factory()->fieldMonitor()->create()],
    'assignment: supervisor' => [fn () => ProjectAssignment::factory()->supervisor()->create()],
    'assignment: unassigned' => [fn () => ProjectAssignment::factory()->unassigned()->create()],
    'status event: default' => [fn () => ProjectStatusEvent::factory()->create()],
    'status event: transition' => [fn () => ProjectStatusEvent::factory()->transition(ProjectStatus::Draft, ProjectStatus::Awarded)->create()],
    'status event: with reason' => [fn () => ProjectStatusEvent::factory()->withReason('Funds not released.')->create()],
    'indicator: default' => [fn () => Indicator::factory()->create()],
    'indicator: active' => [fn () => Indicator::factory()->active()->create()],
    'indicator: without baseline' => [fn () => Indicator::factory()->withoutBaseline()->create()],
    'indicator: percentage' => [fn () => Indicator::factory()->percentage()->create()],
    'indicator: time bound' => [fn () => Indicator::factory()->timeBound()->create()],
    'indicator: one off' => [fn () => Indicator::factory()->oneOff()->create()],
    'indicator: pdo tier' => [fn () => Indicator::factory()->pdo()->create()],
    'indicator: programme level' => [fn () => Indicator::factory()->programmeLevel()->create()],
    'indicator target: default' => [fn () => IndicatorTarget::factory()->create()],
    'indicator target: quarter' => [fn () => IndicatorTarget::factory()->quarter(2)->create()],
    'indicator target: of value' => [fn () => IndicatorTarget::factory()->ofValue('120.0000')->create()],
    'indicator reading: default' => [fn () => IndicatorReading::factory()->create()],
    'indicator reading: submitted' => [fn () => IndicatorReading::factory()->submitted()->create()],
    'indicator reading: validated' => [fn () => IndicatorReading::factory()->validated()->create()],
    'indicator reading: secondary source' => [fn () => IndicatorReading::factory()->secondarySource()->create()],
]);

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

it('persists every factory state inside a workspace, stamped with that workspace', function (Closure $build) {
    /** @var Model $record */
    $record = $this->current->runAs($this->works, $build);

    expect($record->exists)->toBeTrue()
        ->and($record->tenant_id)->toBe($this->works->id);

    $this->current->runAs($this->works, function () use ($record) {
        expect($record::query()->whereKey($record->getKey())->exists())->toBeTrue();
    });
})->with('tenant-owned factory states');

it('refuses to build a tenant-owned factory state outside a workspace', function (Closure $build) {
    actingWithoutTenant();

    expect($build)->toThrow(TenantNotResolvedException::class);
})->with([
    'project' => [fn () => Project::factory()->create()],
    'project location' => [fn () => ProjectLocation::factory()->create()],
    'project funding source' => [fn () => ProjectFundingSource::factory()->create()],
    'contract' => [fn () => Contract::factory()->create()],
    'assignment' => [fn () => ProjectAssignment::factory()->create()],
    'status event' => [fn () => ProjectStatusEvent::factory()->create()],
    'indicator' => [fn () => Indicator::factory()->create()],
    'indicator target' => [fn () => IndicatorTarget::factory()->create()],
    'indicator reading' => [fn () => IndicatorReading::factory()->create()],
]);

it('builds global reference data with no workspace bound at all', function (Closure $build) {
    actingWithoutTenant();

    /** @var Model $record */
    $record = $build();

    expect($record->exists)->toBeTrue();
})->with([
    'sector' => [fn () => Sector::factory()->create()],
    'sub-sector' => [fn () => Sector::factory()->subSectorOf(Sector::factory()->create())->create()],
    'funding source' => [fn () => FundingSource::factory()->create()],
    'donor funding source' => [fn () => FundingSource::factory()->ofType(FundingSourceType::Donor)->create()],
    'lga' => [fn () => Lga::factory()->create()],
    'ward' => [fn () => Ward::factory()->create()],
    'contractor' => [fn () => Contractor::factory()->create()],
    'blacklisted contractor' => [fn () => Contractor::factory()->blacklisted()->create()],
    'consultant firm' => [fn () => Contractor::factory()->consultantFirm()->create()],
    'contractor without an RC number' => [fn () => Contractor::factory()->withoutRcNumber()->create()],
]);

it('gives each project state the lifecycle position its name promises', function () {
    actingOnTenant($this->works);

    expect(Project::factory()->draft()->create()->status)->toBe(ProjectStatus::Draft)
        ->and(Project::factory()->awarded()->create()->status)->toBe(ProjectStatus::Awarded)
        ->and(Project::factory()->mobilized()->create()->status)->toBe(ProjectStatus::Mobilized)
        ->and(Project::factory()->ongoing()->create()->status)->toBe(ProjectStatus::InProgress)
        ->and(Project::factory()->completed()->create()->status)->toBe(ProjectStatus::Completed)
        ->and(Project::factory()->certified()->create()->status)->toBe(ProjectStatus::Certified)
        ->and(Project::factory()->closed()->create()->status)->toBe(ProjectStatus::Closed)
        ->and(Project::factory()->suspended()->create()->status)->toBe(ProjectStatus::Suspended)
        ->and(Project::factory()->cancelled()->create()->status)->toBe(ProjectStatus::Cancelled);
});

it('makes a behind-schedule project overdue and an ongoing one not', function () {
    actingOnTenant($this->works);

    expect(Project::factory()->behindSchedule()->create()->isOverdue())->toBeTrue()
        ->and(Project::factory()->ongoing()->create()->isOverdue())->toBeFalse()
        ->and(Project::factory()->completed()->create()->isOverdue())->toBeFalse();
});

it('gives a completed project an end date and a post-completion review deadline', function () {
    actingOnTenant($this->works);

    $completed = Project::factory()->completed()->create();

    expect($completed->actual_end_date)->not->toBeNull()
        ->and($completed->post_completion_review_due_at)->not->toBeNull()
        ->and($completed->physical_progress)->toBe('100.00');
});

it('puts a closed project past its review deadline, as the close guard will require', function () {
    actingOnTenant($this->works);

    $closed = Project::factory()->closed()->create();

    expect($closed->post_completion_review_due_at->isPast())->toBeTrue()
        ->and($closed->isFrozen())->toBeTrue();
});

it('raises the mid-term flag on a project past the trigger, without changing its status', function () {
    actingOnTenant($this->works);

    $project = Project::factory()->ongoing()->create([
        'physical_progress' => '60.00',
        'mid_term_flagged_at' => now()->subMonth(),
    ]);

    expect($project->isMidTermFlagged())->toBeTrue()
        ->and($project->status)->toBe(ProjectStatus::InProgress);
});

it('builds a multi-site project as several locations with exactly one primary', function () {
    actingOnTenant($this->works);

    $project = Project::factory()->multiSite(3)->create();

    expect($project->locations()->count())->toBe(3)
        ->and($project->locations()->where('is_primary', true)->count())->toBe(1)
        ->and($project->primaryLocation)->not->toBeNull();
});

it('builds a co-funded project as a donor and counterpart split that totals 100 per cent', function () {
    actingOnTenant($this->works);

    $project = Project::factory()->coFunded(70)->create();

    $allocations = $project->fundingAllocations()->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations->sum(fn ($allocation) => (float) $allocation->percentage))->toBe(100.0)
        ->and($allocations->where('is_primary', true))->toHaveCount(1);
});

it('builds a contract variation as a new row against the original, with a reason', function () {
    actingOnTenant($this->works);

    $original = Contract::factory()->create();
    $variation = Contract::factory()->variation($original)->create();

    expect($variation->isVariation())->toBeTrue()
        ->and($variation->varies_contract_id)->toBe($original->id)
        ->and($variation->variation_reason)->not->toBeNull()
        ->and($variation->contractor_id)->toBe($original->contractor_id)
        ->and($original->fresh()->sum->equals($original->sum))->toBeTrue();
});

it('builds indicator states that match the activation contract', function () {
    actingOnTenant($this->works);

    $draft = Indicator::factory()->create();
    $active = Indicator::factory()->active()->create();
    $incomplete = Indicator::factory()->withoutBaseline()->create();

    expect($draft->is_active)->toBeFalse()
        ->and($draft->hasCompleteBaseline())->toBeTrue()
        ->and($active->is_active)->toBeTrue()
        ->and($active->activated_at)->not->toBeNull()
        ->and($incomplete->hasCompleteBaseline())->toBeFalse()
        ->and($incomplete->is_active)->toBeFalse();
});

it('builds indicator states with the units and target types their names promise', function () {
    actingOnTenant($this->works);

    $percentage = Indicator::factory()->percentage()->create();
    $oneOff = Indicator::factory()->oneOff()->create();

    expect($percentage->unit)->toBe(IndicatorUnit::Percentage)
        ->and($percentage->target_type)->toBe(TargetType::PercentageAchievement)
        ->and($oneOff->unit)->toBe(IndicatorUnit::OneOff)
        ->and($oneOff->measurement_frequency)->toBe(MeasurementFrequency::OneOff)
        ->and(Indicator::factory()->timeBound()->create()->target_type)->toBe(TargetType::TimeBound);
});

it('builds a programme-level indicator that belongs to no single project', function () {
    actingOnTenant($this->works);

    $indicator = Indicator::factory()->programmeLevel()->create();

    expect($indicator->project_id)->toBeNull()
        ->and($indicator->tenant_id)->toBe($this->works->id);
});

it('builds reading states with the actor trail each stage requires', function () {
    actingOnTenant($this->works);

    $draft = IndicatorReading::factory()->create();
    $submitted = IndicatorReading::factory()->submitted()->create();
    $validated = IndicatorReading::factory()->validated()->create();

    expect($draft->status)->toBe(IndicatorReadingStatus::Draft)
        ->and($draft->submitted_by_id)->toBeNull()
        ->and($submitted->status)->toBe(IndicatorReadingStatus::Submitted)
        ->and($submitted->submitted_by_id)->not->toBeNull()
        ->and($validated->status)->toBe(IndicatorReadingStatus::Validated)
        ->and($validated->validated_by_id)->not->toBeNull()
        ->and($validated->submitted_by_id)->not->toBeNull()
        ->and($validated->source_type)->toBe(ReadingSourceType::Primary);
});

it('builds assignment states across every project role', function () {
    actingOnTenant($this->works);

    expect(ProjectAssignment::factory()->create()->role)->toBe(ProjectRole::FocalOfficer)
        ->and(ProjectAssignment::factory()->consultant()->create()->role)->toBe(ProjectRole::Consultant)
        ->and(ProjectAssignment::factory()->fieldMonitor()->create()->role)->toBe(ProjectRole::FieldMonitor)
        ->and(ProjectAssignment::factory()->supervisor()->create()->role)->toBe(ProjectRole::Supervisor)
        ->and(ProjectAssignment::factory()->create()->isActive())->toBeTrue()
        ->and(ProjectAssignment::factory()->unassigned()->create()->isActive())->toBeFalse();
});

it('builds contract states across every contract status', function () {
    actingOnTenant($this->works);

    expect(Contract::factory()->create()->status)->toBe(ContractStatus::Awarded)
        ->and(Contract::factory()->active()->create()->status)->toBe(ContractStatus::Active)
        ->and(Contract::factory()->completed()->create()->status)->toBe(ContractStatus::Completed)
        ->and(Contract::factory()->terminated()->create()->status)->toBe(ContractStatus::Terminated);
});

it('builds contractor states for the global vendor registry', function () {
    actingWithoutTenant();

    expect(Contractor::factory()->create()->type)->toBe(FirmType::Contractor)
        ->and(Contractor::factory()->consultantFirm()->create()->type)->toBe(FirmType::ConsultantFirm)
        ->and(Contractor::factory()->supplier()->create()->type)->toBe(FirmType::Supplier)
        ->and(Contractor::factory()->blacklisted()->create()->is_blacklisted)->toBeTrue()
        ->and(Contractor::factory()->withoutRcNumber()->create()->rc_number)->toBeNull();
});

it('lets a factory name a manager and an assignee without inventing extra users', function () {
    actingOnTenant($this->works);

    $manager = User::factory()->create();

    $project = Project::factory()->managedBy($manager)->create();
    $assignment = ProjectAssignment::factory()->forProject($project)->forUser($manager)->create();

    expect($project->manager_id)->toBe($manager->id)
        ->and($assignment->user_id)->toBe($manager->id)
        ->and($assignment->project_id)->toBe($project->id);
});
