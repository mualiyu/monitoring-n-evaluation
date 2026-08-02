<?php

/**
 * The deadline engine (progress-reporting.md §3): reminders, the overdue
 * notice and the escalation ladder.
 *
 * The claim under test is IDEMPOTENCY, and it is a structural claim: every
 * send is gated by a monotonic counter advanced under a row lock in the same
 * transaction as the dispatch, so a double cron run cannot double-send. Each
 * case therefore runs the sweep TWICE on the same frozen clock and asserts one
 * notification — because "it worked when I ran it once" is not the property
 * that matters at 07:00 on a box with two schedulers.
 */

use App\Actions\Reporting\FlagOverdueObligations;
use App\Actions\Reporting\SendDeadlineReminders;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Reporting\ReportObligationDueSoon;
use App\Notifications\Reporting\ReportObligationEscalated;
use App\Notifications\Reporting\ReportObligationOverdue;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow('2026-03-02 07:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $this->stateAdmin = userWithRole(Role::StateAdmin);

    $this->project = Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']);

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->period = ReportingPeriod::factory()->monthly()->create();

    $this->obligationDueIn = fn (int $days): ReportObligation => ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->dueIn($days)
        ->create();

    $this->remind = app(SendDeadlineReminders::class);
    $this->flag = app(FlagOverdueObligations::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sends a reminder on each rung of the ladder, and never the same rung twice', function (int $daysToDue, int $expectedStage) {
    $obligation = ($this->obligationDueIn)($daysToDue);

    ($this->remind)();
    ($this->remind)();   // the second cron run of the same morning

    Notification::assertSentTimes(ReportObligationDueSoon::class, 1);

    expect($obligation->fresh()->reminder_stage)->toBe($expectedStage)
        ->and($obligation->fresh()->reminder_last_sent_at)->not->toBeNull();
})->with([
    'seven days out' => [7, 1],
    'three days out' => [3, 2],
    'the day before' => [1, 3],
    'the day itself' => [0, 3],
]);

it('climbs the ladder one rung at a time as the deadline approaches', function () {
    $obligation = ($this->obligationDueIn)(7);

    ($this->remind)();
    expect($obligation->fresh()->reminder_stage)->toBe(1);

    // Days 6, 5 and 4 cross no new rung: the ladder is [7, 3, 1].
    $this->travelTo(Carbon::parse('2026-03-04 07:00:00'));
    ($this->remind)();
    expect($obligation->fresh()->reminder_stage)->toBe(1);

    $this->travelTo(Carbon::parse('2026-03-06 07:00:00'));
    ($this->remind)();
    expect($obligation->fresh()->reminder_stage)->toBe(2);

    $this->travelTo(Carbon::parse('2026-03-08 07:00:00'));
    ($this->remind)();
    expect($obligation->fresh()->reminder_stage)->toBe(3);

    Notification::assertSentTimes(ReportObligationDueSoon::class, 3);
});

it('sends one reminder, not three, to an obligation first seen a day before its deadline', function () {
    // A project mobilized late in the window: the ladder must not fire every
    // rung it skipped in a single morning.
    $obligation = ($this->obligationDueIn)(1);

    ($this->remind)();

    Notification::assertSentTimes(ReportObligationDueSoon::class, 1);
    expect($obligation->fresh()->reminder_stage)->toBe(3);
});

it('reads the reminder ladder from settings rather than from a literal', function () {
    config()->set('platform.reporting.reminder_days_before', [14]);

    ($this->obligationDueIn)(7);
    ($this->remind)();

    Notification::assertSentTimes(ReportObligationDueSoon::class, 1);

    ($this->obligationDueIn)(20);
    ($this->remind)();

    // The 20-day obligation is outside the single 14-day rung; still one.
    Notification::assertSentTimes(ReportObligationDueSoon::class, 1);
});

it('reminds the people accountable for the project, not the whole ministry', function () {
    $bystander = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    ($this->obligationDueIn)(3);
    ($this->remind)();

    Notification::assertSentTo($this->consultant, ReportObligationDueSoon::class);
    Notification::assertNotSentTo($bystander, ReportObligationDueSoon::class);
});

it('says nothing about an obligation that has already been filed or waived', function (string $state) {
    ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->dueIn(1)
        ->{$state}()
        ->create();

    ($this->remind)();
    ($this->flag)();

    Notification::assertNothingSent();
})->with(['fulfilled', 'waived']);

it('announces an overdue return exactly once, however often the sweep runs', function () {
    $obligation = ($this->obligationDueIn)(-2);

    ($this->flag)();
    ($this->flag)();

    Notification::assertSentTimes(ReportObligationOverdue::class, 1);
    expect($obligation->fresh()->overdue_notified_at)->not->toBeNull();
});

it('sends no due-soon reminder about something already overdue', function () {
    ($this->obligationDueIn)(-2);

    ($this->remind)();

    Notification::assertNothingSent();
});

it('escalates to the MDA admin at the first rung and to state oversight at the second', function () {
    $obligation = ($this->obligationDueIn)(-1);

    ($this->flag)();
    ($this->flag)();

    Notification::assertSentTimes(ReportObligationEscalated::class, 1);
    Notification::assertSentTo($this->admin, ReportObligationEscalated::class);
    Notification::assertNotSentTo($this->stateAdmin, ReportObligationEscalated::class);
    expect($obligation->fresh()->escalation_stage)->toBe(1);

    // Seven days past due — the second rung reaches the secretariat.
    $this->travelTo(Carbon::parse('2026-03-09 07:15:00'));

    ($this->flag)();
    ($this->flag)();

    Notification::assertSentTimes(ReportObligationEscalated::class, 2);
    Notification::assertSentTo($this->stateAdmin, ReportObligationEscalated::class);
    expect($obligation->fresh()->escalation_stage)->toBe(2);
});

it('reads the escalation ladder from settings rather than from a literal', function () {
    config()->set('platform.reporting.overdue_escalation_days', [30]);

    $obligation = ($this->obligationDueIn)(-2);
    ($this->flag)();

    // The overdue notice still goes out; only the escalation ladder waits.
    expect($obligation->fresh()->escalation_stage)->toBe(0);
    Notification::assertSentTimes(ReportObligationEscalated::class, 0);
    Notification::assertSentTimes(ReportObligationOverdue::class, 1);
});

it('keeps one MDA\'s deadline traffic out of another\'s', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $healthAdmin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

    $this->current->runAs($health, function () use ($healthAdmin) {
        $project = Project::factory()->ongoing()->create(['manager_id' => $healthAdmin->id]);

        ReportObligation::factory()
            ->forProject($project)
            ->forPeriod($this->period)
            ->dueIn(3)
            ->create();
    });

    ($this->obligationDueIn)(3);
    ($this->remind)();

    // Each ministry hears about its own project and nothing else.
    Notification::assertSentTo($this->consultant, ReportObligationDueSoon::class);
    Notification::assertSentTo($healthAdmin, ReportObligationDueSoon::class);
    Notification::assertSentTimes(ReportObligationDueSoon::class, 2);
});

it('carries the tenant into the queued notification job', function () {
    Notification::assertNothingSent();

    ($this->obligationDueIn)(3);
    ($this->remind)();

    Notification::assertSentTo(
        $this->consultant,
        ReportObligationDueSoon::class,
        function (ReportObligationDueSoon $notification) {
            // The payload renders from inside the right workspace — the job
            // re-bound tenancy before touching a tenant-owned model at all.
            return $notification->toArray($this->consultant)['tenant_id'] === $this->works->id;
        },
    );
});
