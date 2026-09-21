<?php

/**
 * Who may do what to a measure, and — the part this module exists for — who
 * may never do it to a figure they touched themselves.
 *
 * SEPARATION OF DUTIES IS THE POINT. Recording a number is delivery work;
 * clearing it is assurance. The manual gives data quality to a third party
 * (digest §1, §5) and the Data Quality Reviewer role exists for exactly that
 * reason, so each half of the rule gets its own test rather than being folded
 * into one "permissions work" case:
 *
 *  - the person who RECORDED a figure can never validate it;
 *  - nor can the person who FILED it, even if somebody else measured it;
 *  - and neither can a state admin holding every permission on the platform,
 *    which is precisely the case permissions alone cannot express.
 *
 * The rest is the seeded matrix, asserted one role at a time and both ways:
 * what the role may do, and what it may not.
 */

use App\Actions\Indicators\CreateResultFramework;
use App\Actions\Indicators\RetireIndicatorDefinition;
use App\Actions\Indicators\SaveIndicatorDefinition;
use App\Actions\Indicators\SetIndicatorTarget;
use App\Actions\Indicators\TransitionIndicatorReadingStatus;
use App\Actions\Oversight\ListReadingsAwaitingValidation;
use App\Actions\Projects\ActivateIndicator;
use App\Enums\FrameworkLevel;
use App\Enums\IndicatorReadingStatus;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\Role;
use App\Enums\TargetType;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorReading;
use App\Models\Project;
use App\Models\ResultFramework;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);
    $this->statement = ResultFramework::factory()->forProject($this->project)->create();
    $this->indicator = Indicator::factory()->active()->inFramework($this->statement)->create([
        'name' => 'Boreholes commissioned',
    ]);

    $this->current->forget();

    $this->reviewer = userWithRole(Role::DataQualityReviewer);
    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->executive = userWithRole(Role::ExecutiveViewer);

    $this->current->forget();
});

/**
 * Re-read a figure inside the workspace that owns it. Never ->fresh(), which
 * is newQueryWithoutScopes() and would read across the tenant boundary.
 */
function readingNow(IndicatorReading $reading): IndicatorReading
{
    return app(CurrentTenant::class)->runAs(
        $reading->loadMissing('tenant')->tenant,
        fn (): IndicatorReading => IndicatorReading::query()->whereKey($reading->getKey())->firstOrFail(),
    );
}

/* -------------------------------------------------------------------------- */
/* Separation of duties — one rule, one test each */
/* -------------------------------------------------------------------------- */

it('refuses to let the person who RECORDED a figure validate it', function () {
    // The reviewer here holds `indicators.readings.validate` globally and
    // would be perfectly entitled to clear anybody else's figure. What stops
    // them is that they measured this one.
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->reviewer)
        ->submitted($this->officer)
        ->create();

    $this->current->forget();

    expect(fn () => (new TransitionIndicatorReadingStatus)(
        $reading,
        IndicatorReadingStatus::Validated,
        $this->reviewer,
    ))->toThrow(IndicatorRuleViolation::class, 'cannot validate it');

    expect(readingNow($reading)->status)->toBe(IndicatorReadingStatus::Submitted);
});

it('refuses to let the person who FILED a figure validate it, even if somebody else measured it', function () {
    // An M&E officer can file a figure a field monitor measured. A reviewer
    // who happens to be that officer would clear their own submission while
    // passing a recorder-only check, so BOTH identities are weighed.
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->monitor)
        ->submitted($this->reviewer)
        ->create();

    $this->current->forget();

    expect(fn () => (new TransitionIndicatorReadingStatus)(
        $reading,
        IndicatorReadingStatus::Validated,
        $this->reviewer,
    ))->toThrow(IndicatorRuleViolation::class, 'recorded or filed');
});

it('refuses the same to a state admin holding every permission there is', function () {
    // This is the case a permission matrix cannot express: StateAdmin holds
    // `.record` and `.validate` alike and can enter a figure on an entity's
    // behalf. Without the identity guard, the one separation the module
    // exists to enforce would hold for everyone except the people with the
    // most authority — precisely backwards.
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->stateAdmin)
        ->submitted($this->stateAdmin)
        ->create();

    $this->current->forget();

    expect(fn () => (new TransitionIndicatorReadingStatus)(
        $reading,
        IndicatorReadingStatus::Validated,
        $this->stateAdmin,
    ))->toThrow(IndicatorRuleViolation::class);
});

it('lets that same reviewer clear a figure somebody else measured and filed', function () {
    // The mirror of the three refusals above: without this, they would all
    // pass on a reviewer who simply cannot validate anything.
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->officer)
        ->submitted($this->officer)
        ->create();

    $this->current->forget();

    (new TransitionIndicatorReadingStatus)($reading, IndicatorReadingStatus::Validated, $this->reviewer);

    expect(readingNow($reading)->status)->toBe(IndicatorReadingStatus::Validated);
});

it('lets the validator publish by default, and refuses it once a state asks for a second pair of eyes', function () {
    actingOnTenant($this->works);

    $first = IndicatorReading::factory()->forIndicator($this->indicator)
        ->forPeriod('2026-01-01', '2026-03-31')
        ->recordedBy($this->officer)->validated($this->stateAdmin)->create();

    $second = IndicatorReading::factory()->forIndicator($this->indicator)
        ->forPeriod('2026-04-01', '2026-06-30')
        ->recordedBy($this->officer)->validated($this->stateAdmin)->create();

    $this->current->forget();

    // Off by default: most states run a single secretariat desk.
    config(['platform.indicators.require_separate_publisher' => false]);
    (new TransitionIndicatorReadingStatus)($first, IndicatorReadingStatus::Published, $this->stateAdmin);
    expect(readingNow($first)->status)->toBe(IndicatorReadingStatus::Published);

    // A state that wants the second pair of eyes turns it on from a settings
    // screen, without a release.
    config(['platform.indicators.require_separate_publisher' => true]);
    expect(fn () => (new TransitionIndicatorReadingStatus)(
        $second,
        IndicatorReadingStatus::Published,
        $this->stateAdmin,
    ))->toThrow(IndicatorRuleViolation::class, 'second pair of eyes');
});

/* -------------------------------------------------------------------------- */
/* The role matrix, one role per surface */
/* -------------------------------------------------------------------------- */

it('lets a consultant READ the framework they report against and never edit it', function () {
    actingOnTenant($this->works);

    $consultant = $this->consultant;

    expect(Gate::forUser($consultant)->allows('viewAny', ResultFramework::class))->toBeTrue()
        ->and(Gate::forUser($consultant)->allows('view', $this->statement))->toBeTrue()
        ->and(Gate::forUser($consultant)->allows('view', $this->indicator))->toBeTrue()
        // Shaping the logframe is M&E work, never a contractor's.
        ->and(Gate::forUser($consultant)->allows('create', ResultFramework::class))->toBeFalse()
        ->and(Gate::forUser($consultant)->allows('update', $this->statement))->toBeFalse()
        ->and(Gate::forUser($consultant)->allows('delete', $this->statement))->toBeFalse()
        // Nor may they define a measure or open one for reporting.
        ->and(Gate::forUser($consultant)->allows('create', Indicator::class))->toBeFalse()
        ->and(Gate::forUser($consultant)->allows('update', $this->indicator))->toBeFalse()
        ->and(Gate::forUser($consultant)->allows('activate', $this->indicator))->toBeFalse();
});

it('refuses a consultant’s attempt to add a result statement, through the Action itself', function () {
    actingOnTenant($this->works);

    // Not only the policy: the Action re-asks, so a second entry path cannot
    // route around the screen that hides the button.
    expect(fn () => (new CreateResultFramework)(
        FrameworkLevel::Impact,
        'A statement a contractor wrote about the state\'s own objectives.',
        $this->consultant,
        $this->project,
    ))->toThrow(AuthorizationException::class);
});

it('refuses a consultant’s attempt to activate an indicator or move its target', function () {
    actingOnTenant($this->works);

    $draft = Indicator::factory()->create(['name' => 'Culverts installed']);

    expect(fn () => (new ActivateIndicator)($draft, $this->consultant))
        ->toThrow(AuthorizationException::class);

    // A target quietly lowered to meet the actual is the classic M&E
    // fabrication; the people who may move one are the people the state can
    // hold to it.
    expect(fn () => (new SetIndicatorTarget)(
        $this->indicator,
        MeasurementFrequency::Annual,
        '2026-01-01',
        '2026-12-31',
        '1',
        $this->consultant,
    ))->toThrow(AuthorizationException::class);
});

it('lets a consultant record and file a figure, and never validate one', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->consultant)
        ->create();

    expect(Gate::forUser($this->consultant)->allows('create', IndicatorReading::class))->toBeTrue()
        ->and(Gate::forUser($this->consultant)->allows('update', $reading))->toBeTrue()
        ->and(Gate::forUser($this->consultant)->allows('submit', $reading))->toBeTrue()
        // `indicators.readings.validate` is never seeded to a field role, so
        // "a consultant cannot clear their own figure" needs no runtime check
        // to be true.
        ->and(Gate::forUser($this->consultant)->allows('validate', $reading))->toBeFalse()
        ->and(Gate::forUser($this->consultant)->allows('publish', $reading))->toBeFalse();
});

it('lets the M&E officer build the framework and open indicators for reporting', function () {
    actingOnTenant($this->works);

    expect(Gate::forUser($this->officer)->allows('create', ResultFramework::class))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('update', $this->statement))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('create', Indicator::class))->toBeTrue()
        ->and(Gate::forUser($this->officer)->allows('activate', $this->indicator))->toBeTrue()
        // …and still not the assurance half of the job.
        ->and(Gate::forUser($this->officer)->allows(
            'validate',
            IndicatorReading::factory()->forIndicator($this->indicator)->submitted()->create(),
        ))->toBeFalse();
});

it('lets an executive viewer read a figure and neither validate nor publish it', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()->forIndicator($this->indicator)->submitted()->create();

    $this->current->forget();

    // Read-only oversight, asserted as read-only rather than merely as
    // "denied": an ExecutiveViewer who could see nothing would pass a
    // denial-only test for entirely the wrong reason.
    expect(Gate::forUser($this->executive)->allows('viewAny', Indicator::class))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('view', $this->indicator))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('viewAny', IndicatorDefinition::class))->toBeTrue()
        ->and(Gate::forUser($this->executive)->allows('validate', $reading))->toBeFalse()
        ->and(Gate::forUser($this->executive)->allows('publish', $reading))->toBeFalse()
        // Nor may the governor's office reword the state's own indicator list.
        ->and(Gate::forUser($this->executive)->allows('create', IndicatorDefinition::class))->toBeFalse();
});

it('keeps the data quality reviewer out of publication, which is the secretariat’s act', function () {
    actingOnTenant($this->works);

    $reading = IndicatorReading::factory()->forIndicator($this->indicator)->validated()->create();

    $this->current->forget();

    // Validation says the figure is sound; publication says the state stands
    // behind it outside the platform. `indicators.readings.publish` is seeded
    // to the secretariat alone.
    expect(Gate::forUser($this->reviewer)->allows('validate', $reading))->toBeTrue()
        ->and(Gate::forUser($this->reviewer)->allows('publish', $reading))->toBeFalse()
        ->and(Gate::forUser($this->stateAdmin)->allows('publish', $reading))->toBeTrue();
});

/* -------------------------------------------------------------------------- */
/* The state library is written by the state, read by everyone */
/* -------------------------------------------------------------------------- */

it('refuses an entity — even its admin — the right to reword the state indicator list', function () {
    $definition = IndicatorDefinition::factory()->create(['code' => 'EDU-001']);

    actingOnTenant($this->works);

    // One MDA rewording a measure every other ministry reports against is the
    // failure the predetermined list exists to prevent. That is what the Q4
    // indicator retreat is for.
    expect(Gate::forUser($this->admin)->allows('viewAny', IndicatorDefinition::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $definition))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', IndicatorDefinition::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $definition))->toBeFalse();

    expect(fn () => (new SaveIndicatorDefinition)([
        'code' => 'WRK-999',
        'name' => 'A measure one ministry invented for everybody',
        'unit' => IndicatorUnit::Number,
        'default_measurement_frequency' => MeasurementFrequency::Quarterly,
        'default_target_type' => TargetType::Continuous,
    ], $this->admin))->toThrow(AuthorizationException::class);
});

it('lets the secretariat write and retire a library entry', function () {
    $this->current->forget();

    $definition = (new SaveIndicatorDefinition)([
        'code' => 'edu-002',
        'name' => 'Classrooms completed and in use',
        'unit' => IndicatorUnit::Number,
        'default_measurement_frequency' => MeasurementFrequency::Quarterly,
        'default_target_type' => TargetType::Continuous,
    ], $this->stateAdmin);

    // The code is the identity MDAs quote, so it is normalised once and never
    // rewritten afterwards.
    expect($definition->code)->toBe('EDU-002')
        ->and($definition->is_active)->toBeTrue();

    (new RetireIndicatorDefinition)($definition, $this->stateAdmin);

    expect(IndicatorDefinition::query()->whereKey($definition->getKey())->firstOrFail()->is_active)->toBeFalse();
});

it('never deletes a library entry, whoever asks', function () {
    $definition = IndicatorDefinition::factory()->create();

    $this->current->forget();

    // Retirement, never deletion: a definition that vanished would leave
    // every figure published under it meaning nothing.
    expect(Gate::forUser($this->stateAdmin)->allows('delete', $definition))->toBeFalse()
        ->and(Gate::forUser($this->reviewer)->allows('delete', $definition))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The cross-MDA queue is a named privilege, not a convenience */
/* -------------------------------------------------------------------------- */

it('refuses the cross-entity validation queue to an entity role holding validate in its own workspace', function () {
    actingOnTenant($this->works);

    // Granted the validate permission INSIDE their own workspace — the exact
    // shape that would sneak past a check made after the tenancy bypass.
    $localReviewer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    setPermissionsTeamId($this->works->id);
    $localReviewer->givePermissionTo('indicators.readings.validate');
    $localReviewer->forgetCachedPermissions();

    $this->current->forget();

    expect(fn () => (new ListReadingsAwaitingValidation)($localReviewer))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => (new ListReadingsAwaitingValidation)->count($localReviewer))
        ->toThrow(AuthorizationException::class);
});

it('opens the same queue to the reviewer the role exists for', function () {
    $this->current->runAs($this->works, fn () => IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->submitted()
        ->create());

    $this->current->forget();

    expect((new ListReadingsAwaitingValidation)->count($this->reviewer))->toBe(1)
        ->and((new ListReadingsAwaitingValidation)($this->reviewer)->total())->toBe(1);
});
