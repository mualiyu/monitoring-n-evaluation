<?php

/**
 * The overdue-activity sweep (App\Actions\Workplans\FlagOverdueActivities).
 *
 * Time is pinned with Carbon::setTestNow() throughout — no sleeps, no real
 * clock. The two properties that matter for a scheduled job are asserted
 * directly:
 *
 *  IDEMPOTENCE. The sweep runs on a schedule and may crash halfway. Running it
 *  twice must announce nothing twice — a nag channel people learn to mute is
 *  worse than no channel. The gate is `overdue_notified_at`, stamped under the
 *  same row lock as the dispatch.
 *
 *  SCOPE. Only APPROVED or ACTIVE plans are considered, and each tenant's
 *  activities are swept inside that tenant's own context, so the recipient
 *  query can never reach across a ministry boundary.
 */

use App\Actions\Workplans\FlagOverdueActivities;
use App\Enums\ActivityStatus;
use App\Enums\Role;
use App\Jobs\Workplans\NotifyActivityOverdue;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Notifications\Workplans\WorkplanActivityOverdue;
use App\Support\WorkplanProgress;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedPermissions();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 6));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * One activity of a live plan, scheduled to end on $end.
 */
function slippingActivity(Tenant $tenant, string $end, string $factoryState = 'active'): WorkplanActivity
{
    return app(CurrentTenant::class)->runAs($tenant, function () use ($end, $factoryState): WorkplanActivity {
        $plan = Workplan::factory()->forYear(2026)->{$factoryState}()->create();

        return WorkplanActivity::factory()
            ->forWorkplan($plan)
            ->scheduled(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::parse($end))
            ->create(['progress_percent' => 20, 'status' => ActivityStatus::InProgress]);
    });
}

/* -------------------------------------------------------------------------- */
/* The date parameters accept the dates this module actually holds */
/* -------------------------------------------------------------------------- */

it('takes an immutable "as of" date on every lateness question', function () {
    // The regression: these all typed Illuminate\Support\Carbon, which is
    // MUTABLE and not a parent of CarbonImmutable — while every date on this
    // module is an `immutable_date` cast. FlagOverdueActivities passes a
    // CarbonImmutable straight into scopeOverdue(), so the whole sweep
    // TypeError'd on the first row it looked at, on every run.
    $asOf = CarbonImmutable::create(2026, 6, 15, 6);

    $activity = slippingActivity($this->works, '2026-05-31');

    $this->current->runAs($this->works, function () use ($asOf, $activity) {
        expect(WorkplanActivity::query()->overdue($asOf)->count())->toBe(1)
            ->and(WorkplanActivity::query()->whereKey($activity->getKey())->sole()->isOverdue($asOf))->toBeTrue()
            ->and($activity->workplan->hasStarted($asOf))->toBeTrue()
            ->and(WorkplanProgress::scheduleHealth($activity->workplan, $asOf))->toHaveKey('elapsed')
            ->and(WorkplanProgress::isBehindSchedule($activity->workplan, $asOf))->toBeBool();
    });
});

/* -------------------------------------------------------------------------- */
/* What the sweep picks up */
/* -------------------------------------------------------------------------- */

it('flags an activity whose planned end has passed with the work unfinished', function () {
    Queue::fake();

    $late = slippingActivity($this->works, '2026-05-31');

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 1]);

    Queue::assertPushed(NotifyActivityOverdue::class, 1);

    $this->current->runAs($this->works, function () use ($late) {
        expect(WorkplanActivity::query()->whereKey($late->getKey())->sole()->overdue_notified_at)
            ->not->toBeNull();
    });
});

it('leaves an activity due today alone — due today is not yet late', function () {
    Queue::fake();

    slippingActivity($this->works, '2026-06-15');

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 0]);

    Queue::assertNotPushed(NotifyActivityOverdue::class);
});

it('flags nothing before the date and everything after it, on the same fixture', function () {
    Queue::fake();

    slippingActivity($this->works, '2026-06-20');

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 0]);

    // One day past the planned end, and the same row is now slipping.
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 21, 6));

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 1]);
});

it('ignores a finished or cancelled line, however long ago its date passed', function (string $state) {
    Queue::fake();

    $this->current->runAs($this->works, function () use ($state) {
        $plan = Workplan::factory()->forYear(2026)->active()->create();

        WorkplanActivity::factory()
            ->forWorkplan($plan)
            ->scheduled(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 3, 31))
            ->{$state}()
            ->create();
    });

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 0]);
})->with(['completed', 'cancelled']);

it('says nothing about a plan nobody has signed — a draft’s dates are a proposal', function (string $state, int $expected) {
    Queue::fake();

    slippingActivity($this->works, '2026-05-31', $state);

    expect((new FlagOverdueActivities)())->toBe(['overdue' => $expected]);
})->with([
    'a draft plan' => ['draft', 0],
    'a plan out for approval' => ['submitted', 0],
    'a plan sent back' => ['rejected', 0],
    'a closed year' => ['closed', 0],
    'an approved plan' => ['approved', 1],
    'the plan being delivered' => ['active', 1],
]);

it('skips a workspace whose subdomain has been closed', function () {
    Queue::fake();

    slippingActivity($this->works, '2026-05-31');

    $this->works->forceFill(['is_active' => false])->save();

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 0]);
});

/* -------------------------------------------------------------------------- */
/* Idempotence */
/* -------------------------------------------------------------------------- */

it('announces a slipped activity exactly once, however often the sweep runs', function () {
    Queue::fake();

    slippingActivity($this->works, '2026-05-31');

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 1]);

    // Same day, same sweep, run again — a scheduler retry, or a second worker.
    expect((new FlagOverdueActivities)())->toBe(['overdue' => 0]);

    // And tomorrow, when the activity is a day later still.
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 16, 6));

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 0]);

    Queue::assertPushed(NotifyActivityOverdue::class, 1);
});

it('does not double a stamp or a notice when the sweep runs twice end to end', function () {
    Notification::fake();

    $late = slippingActivity($this->works, '2026-05-31');

    $owner = $this->current->runAs(
        $this->works,
        fn (): User => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin),
    );

    $this->current->runAs($this->works, function () use ($late, $owner) {
        WorkplanActivity::query()->whereKey($late->getKey())->sole()
            ->forceFill(['owner_id' => $owner->id])->save();
    });

    // QUEUE_CONNECTION=sync in the test environment, so the job runs inline
    // and the notification really is sent — the whole path, twice.
    (new FlagOverdueActivities)();
    (new FlagOverdueActivities)();

    Notification::assertSentToTimes($owner, WorkplanActivityOverdue::class, 1);

    $this->current->runAs($this->works, function () use ($late) {
        $row = WorkplanActivity::query()->whereKey($late->getKey())->sole();

        expect($row->overdue_notified_at?->toDateTimeString())->toBe('2026-06-15 06:00:00');
    });
});

it('tells nobody about work that was finished between the sweep and the worker', function () {
    Notification::fake();

    $late = slippingActivity($this->works, '2026-05-31');

    $owner = $this->current->runAs(
        $this->works,
        fn (): User => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin),
    );

    $this->current->runAs($this->works, function () use ($late, $owner) {
        WorkplanActivity::query()->whereKey($late->getKey())->sole()
            ->forceFill([
                'owner_id' => $owner->id,
                // Recorded 100% in the minutes before the worker picked it up.
                'progress_percent' => 100,
                'status' => ActivityStatus::Completed,
            ])->save();
    });

    (new FlagOverdueActivities)();

    Notification::assertNothingSentTo($owner);
});

/* -------------------------------------------------------------------------- */
/* Tenancy */
/* -------------------------------------------------------------------------- */

it('sweeps each workspace inside its own tenancy, flagging both without mixing them', function () {
    Queue::fake();

    $worksLate = slippingActivity($this->works, '2026-05-31');
    $healthLate = slippingActivity($this->health, '2026-04-30');

    expect((new FlagOverdueActivities)())->toBe(['overdue' => 2]);

    $this->current->runAs($this->works, function () use ($worksLate, $healthLate) {
        expect(WorkplanActivity::query()->whereKey($worksLate->getKey())->sole()->overdue_notified_at)->not->toBeNull()
            ->and(WorkplanActivity::query()->find($healthLate->getKey()))->toBeNull();
    });

    $this->current->runAs($this->health, function () use ($healthLate) {
        expect(WorkplanActivity::query()->whereKey($healthLate->getKey())->sole()->overdue_notified_at)->not->toBeNull();
    });
});

it('tells only this workspace’s administrators about this workspace’s slippage', function () {
    Notification::fake();

    $late = slippingActivity($this->works, '2026-05-31');

    $worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $healthAdmin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);

    actingWithoutTenant();

    (new FlagOverdueActivities)();

    Notification::assertSentTo($worksAdmin, WorkplanActivityOverdue::class);
    Notification::assertNotSentTo($healthAdmin, WorkplanActivityOverdue::class);

    expect($late->getKey())->toBeInt();
});
