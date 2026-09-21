<?php

/**
 * The work-plan approval chain, exercised through the screen that drives it.
 *
 * tests/Unit/WorkplanStatusTransitionTest proves the chain TABLE — which moves
 * exist. This file proves the chain in operation: that every legal move
 * succeeds for the actor who owns it and writes a ledger row, that the
 * separation of duties holds (the officer who submitted a plan cannot sign it
 * off, whatever permissions they hold), that the approval freeze stops the
 * DEFINITION of an activity moving while PROGRESS against it keeps being
 * recorded — and that the forbidden moves are refused rather than rendered as
 * a 500.
 */

use App\Actions\Workplans\AddWorkplanActivity;
use App\Actions\Workplans\ApproveWorkplan;
use App\Actions\Workplans\CreateWorkplan;
use App\Actions\Workplans\RecordActivityProgress;
use App\Actions\Workplans\RemoveWorkplanActivity;
use App\Actions\Workplans\SubmitWorkplanForApproval;
use App\Actions\Workplans\TransitionWorkplanStatus;
use App\Actions\Workplans\UpdateWorkplanActivity;
use App\Enums\ActivityStatus;
use App\Enums\Role;
use App\Enums\WorkplanStatus;
use App\Exceptions\Workplans\InvalidWorkplanTransition;
use App\Exceptions\Workplans\WorkplanRuleViolation;
use App\Livewire\Tenant\Workplans\WorkplanBuilder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Models\WorkplanEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    seedPermissions();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 9));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->admin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(['name' => 'Bola Adeyemi']), $this->works, Role::Consultant);

    $this->plan = Workplan::factory()->forYear(2026)->ownedBy($this->officer)->by($this->officer)->create();

    $this->activity = WorkplanActivity::factory()
        ->forWorkplan($this->plan, 1)
        ->withIndicator()
        ->ownedBy($this->consultant)
        ->create(['title' => 'Conduct implementation monitoring field visits']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Re-read a plan through the model, never ->fresh() (an unscoped read). */
function reloadPlan(Workplan $plan): Workplan
{
    return Workplan::query()->whereKey($plan->getKey())->sole();
}

/* -------------------------------------------------------------------------- */
/* Opening a plan */
/* -------------------------------------------------------------------------- */

it('opens a plan already holding its status, and records the opening in the ledger', function () {
    $plan = (new CreateWorkplan(app(TransitionWorkplanStatus::class)))($this->officer, [
        'title' => 'Annual Work Plan & Budget 2027',
        'year' => 2027,
        'year_basis' => 'calendar',
        'period_start' => '2027-01-01',
        'period_end' => '2027-12-31',
        'owner_id' => $this->officer->id,
    ]);

    // The regression: `status` is not fillable, so leaning on the column
    // default left the RETURNED model holding null — and recordCreation()
    // then wrote that null into workplan_events.to_status, which is NOT NULL.
    // Creating any plan failed with an integrity violation.
    expect($plan->status)->toBe(WorkplanStatus::Draft)
        ->and(reloadPlan($plan)->status)->toBe(WorkplanStatus::Draft);

    $opening = WorkplanEvent::query()->where('workplan_id', $plan->id)->sole();

    expect($opening->isCreation())->toBeTrue()
        ->and($opening->to_status)->toBe(WorkplanStatus::Draft)
        ->and($opening->actor_id)->toBe($this->officer->id);
});

/* -------------------------------------------------------------------------- */
/* Every legal move, with the actor who owns it */
/* -------------------------------------------------------------------------- */

it('walks the whole chain from draft to closed with the right actor at each step', function () {
    $transition = app(TransitionWorkplanStatus::class);

    // The M&E unit submits.
    (new SubmitWorkplanForApproval($transition))($this->plan, $this->officer);
    expect(reloadPlan($this->plan)->status)->toBe(WorkplanStatus::Submitted);

    // The accounting officer signs. The period has already begun, so approval
    // carries the plan straight on to active — a plan signed in March for a
    // year that started in January IS the plan being worked to.
    (new ApproveWorkplan($transition))($this->plan, $this->admin);

    $approved = reloadPlan($this->plan);

    expect($approved->status)->toBe(WorkplanStatus::Active)
        ->and($approved->approved_by_id)->toBe($this->admin->id)
        ->and($approved->submitted_by_id)->toBe($this->officer->id)
        ->and($approved->activated_at)->not->toBeNull();

    // And the year is closed off.
    $transition($this->plan, WorkplanStatus::Closed, $this->admin, 'Year end; delivery reported in the annual report.');

    expect(reloadPlan($this->plan)->status)->toBe(WorkplanStatus::Closed);

    expect(WorkplanEvent::query()->where('workplan_id', $this->plan->id)->orderBy('id')->pluck('to_status')->all())
        ->toBe([
            WorkplanStatus::Submitted,
            WorkplanStatus::Approved,
            WorkplanStatus::Active,
            WorkplanStatus::Closed,
        ]);
});

it('holds a plan approved ahead of its year at approved until the year opens', function () {
    $future = Workplan::factory()->forYear(2027)->ownedBy($this->officer)->by($this->officer)->create();
    WorkplanActivity::factory()->forWorkplan($future)->create();

    $transition = app(TransitionWorkplanStatus::class);

    (new SubmitWorkplanForApproval($transition))($future, $this->officer);
    (new ApproveWorkplan($transition))($future, $this->admin);

    // Signed, but not yet the plan we are working to today.
    expect(reloadPlan($future)->status)->toBe(WorkplanStatus::Approved);

    expect(fn () => $transition($future, WorkplanStatus::Active, $this->admin))
        ->toThrow(WorkplanRuleViolation::class, 'cannot be activated before its period begins');

    // …and it activates the moment the period opens.
    CarbonImmutable::setTestNow(CarbonImmutable::create(2027, 1, 2, 9));

    $transition(reloadPlan($future), WorkplanStatus::Active, $this->admin);

    expect(reloadPlan($future)->status)->toBe(WorkplanStatus::Active);
});

it('sends a plan back with a reason and takes it again when it is resubmitted', function () {
    $transition = app(TransitionWorkplanStatus::class);

    (new SubmitWorkplanForApproval($transition))($this->plan, $this->officer);

    Livewire::actingAs($this->admin)
        ->test(WorkplanBuilder::class, ['workplan' => reloadPlan($this->plan)])
        ->set('decisionReason', 'The training budget is not reconciled with the appropriation — revise and resubmit.')
        ->call('reject')
        ->assertHasNoErrors();

    $rejected = reloadPlan($this->plan);

    expect($rejected->status)->toBe(WorkplanStatus::Rejected)
        ->and($rejected->rejected_by_id)->toBe($this->admin->id)
        ->and($rejected->rejection_reason)->toContain('not reconciled');

    // Resubmission clears the superseded decision from the record — the
    // ledger keeps the history.
    (new SubmitWorkplanForApproval($transition))($rejected, $this->officer);

    $resubmitted = reloadPlan($this->plan);

    expect($resubmitted->status)->toBe(WorkplanStatus::Submitted)
        ->and($resubmitted->rejection_reason)->toBeNull()
        ->and($resubmitted->rejected_by_id)->toBeNull()
        ->and(WorkplanEvent::query()->where('to_status', WorkplanStatus::Rejected)->value('reason'))
        ->toContain('not reconciled');
});

it('refuses to send a plan back without a reason worth acting on', function () {
    (new SubmitWorkplanForApproval(app(TransitionWorkplanStatus::class)))($this->plan, $this->officer);

    Livewire::actingAs($this->admin)
        ->test(WorkplanBuilder::class, ['workplan' => reloadPlan($this->plan)])
        ->set('decisionReason', 'no')
        ->call('reject')
        ->assertHasErrors('decisionReason');

    expect(reloadPlan($this->plan)->status)->toBe(WorkplanStatus::Submitted);
});

/* -------------------------------------------------------------------------- */
/* Separation of duties */
/* -------------------------------------------------------------------------- */

it('refuses to let the officer who submitted a work plan approve it', function () {
    $transition = app(TransitionWorkplanStatus::class);

    // The admin holds BOTH workplans.manage and workplans.approve — so this
    // is not a permission failure, which is exactly the point: the whole value
    // of the approval step is that somebody else looked at the plan.
    (new SubmitWorkplanForApproval($transition))($this->plan, $this->admin);

    expect(fn () => (new ApproveWorkplan($transition))(reloadPlan($this->plan), $this->admin))
        ->toThrow(WorkplanRuleViolation::class, 'cannot approve it');

    expect(reloadPlan($this->plan)->status)->toBe(WorkplanStatus::Submitted);
});

it('surfaces the separation refusal on the screen rather than as a 500', function () {
    (new SubmitWorkplanForApproval(app(TransitionWorkplanStatus::class)))($this->plan, $this->admin);

    // The refusal is rendered back to the officer in the words the Action
    // used, not paraphrased and not swallowed.
    Livewire::actingAs($this->admin)
        ->test(WorkplanBuilder::class, ['workplan' => reloadPlan($this->plan)])
        ->call('approve')
        ->assertOk()
        ->assertSee('That change was refused')
        ->assertSee('Separation of duties is the whole value of the approval step');

    expect(reloadPlan($this->plan)->status)->toBe(WorkplanStatus::Submitted);
});

it('lets a second officer approve the plan the first one submitted', function () {
    $transition = app(TransitionWorkplanStatus::class);
    $director = memberOf(User::factory()->create(['name' => 'Musa Ibrahim']), $this->works, Role::MdaAdmin);

    (new SubmitWorkplanForApproval($transition))($this->plan, $this->admin);
    (new ApproveWorkplan($transition))(reloadPlan($this->plan), $director);

    expect(reloadPlan($this->plan)->approved_by_id)->toBe($director->id);
});

it('refuses approval to an M&E officer, who may draft and submit but never sign', function () {
    $transition = app(TransitionWorkplanStatus::class);

    (new SubmitWorkplanForApproval($transition))($this->plan, $this->officer);

    expect(fn () => (new ApproveWorkplan($transition))(reloadPlan($this->plan), $this->officer))
        ->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->officer)
        ->test(WorkplanBuilder::class, ['workplan' => reloadPlan($this->plan)])
        ->call('approve')
        ->assertForbidden();
});

it('refuses submission to a consultant, who holds workplans.view and nothing more', function () {
    expect(fn () => (new SubmitWorkplanForApproval(app(TransitionWorkplanStatus::class)))($this->plan, $this->consultant))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* Preconditions */
/* -------------------------------------------------------------------------- */

it('refuses to submit a plan with no activities, because an empty plan is not a plan', function () {
    $empty = Workplan::factory()->forYear(2025)->by($this->officer)->create();

    expect(fn () => (new SubmitWorkplanForApproval(app(TransitionWorkplanStatus::class)))($empty, $this->officer))
        ->toThrow(WorkplanRuleViolation::class, 'empty plan is not a plan');
});

/* -------------------------------------------------------------------------- */
/* Forbidden moves */
/* -------------------------------------------------------------------------- */

it('refuses a move the chain table does not contain, before any permission is considered', function (
    string $factoryState,
    WorkplanStatus $target,
) {
    $plan = Workplan::factory()->forYear(2026)->{$factoryState}()->create();
    WorkplanActivity::factory()->forWorkplan($plan)->create();

    expect(fn () => app(TransitionWorkplanStatus::class)($plan, $target, $this->admin, 'A stated reason.'))
        ->toThrow(InvalidWorkplanTransition::class);

    expect(reloadPlan($plan)->status)->not->toBe($target);
})->with([
    'a draft cannot be approved without being submitted' => ['draft', WorkplanStatus::Approved],
    'a draft cannot go straight live' => ['draft', WorkplanStatus::Active],
    'an active year cannot be redrafted' => ['active', WorkplanStatus::Draft],
    'a closed year cannot be reopened' => ['closed', WorkplanStatus::Active],
    'an approved plan cannot be rejected after the fact' => ['approved', WorkplanStatus::Rejected],
]);

it('keeps the ledger append-only — a chain step is corrected by recording another one', function () {
    (new SubmitWorkplanForApproval(app(TransitionWorkplanStatus::class)))($this->plan, $this->officer);

    $event = WorkplanEvent::query()->where('workplan_id', $this->plan->id)->sole();

    expect(fn () => $event->update(['reason' => 'rewritten']))->toThrow(RuntimeException::class)
        ->and(fn () => $event->delete())->toThrow(RuntimeException::class);
});

/* -------------------------------------------------------------------------- */
/* The approval freeze — definitions stop, reporting continues */
/* -------------------------------------------------------------------------- */

it('freezes activity definitions once a plan is approved', function () {
    $plan = Workplan::factory()->forYear(2026)->approved($this->admin)->create();
    $activity = WorkplanActivity::factory()
        ->forWorkplan($plan)
        ->withIndicator()
        ->create(['title' => 'Commission the mid-term evaluation']);

    expect(fn () => (new UpdateWorkplanActivity)($activity, $this->admin, ['title' => 'A quietly different activity']))
        ->toThrow(WorkplanRuleViolation::class, 'are frozen');

    expect(fn () => (new AddWorkplanActivity)($plan, $this->admin, [
        'title' => 'An activity nobody signed for',
        'schedule_granularity' => 'month',
        'planned_start' => $plan->period_start->toDateString(),
        'planned_end' => $plan->period_start->addMonth()->toDateString(),
        'budget_amount' => '100000.00',
        'weight' => 1,
    ]))->toThrow(WorkplanRuleViolation::class, 'are frozen');

    expect(fn () => (new RemoveWorkplanActivity)($activity, $this->admin))
        ->toThrow(WorkplanRuleViolation::class, 'are frozen');

    // Nothing moved. Compared against the stored value, not the in-memory
    // model — UpdateWorkplanActivity fills before it checks, so the rejected
    // title is still sitting on the unsaved instance.
    $reloaded = WorkplanActivity::query()->whereKey($activity->getKey())->sole();

    expect($reloaded->title)->toBe('Commission the mid-term evaluation')
        ->and(WorkplanActivity::query()->where('workplan_id', $plan->id)->count())->toBe(1);
});

it('keeps recording progress against an approved plan — that is what an approved plan is for', function () {
    $plan = Workplan::factory()->forYear(2026)->approved($this->admin)->create();
    $activity = WorkplanActivity::factory()
        ->forWorkplan($plan)
        ->withIndicator()
        ->ownedBy($this->consultant)
        ->scheduled(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31))
        ->create();

    (new RecordActivityProgress)($activity, $this->officer, [
        'progress_percent' => 60,
        'expenditure_to_date' => '750000.00',
    ]);

    $recorded = WorkplanActivity::query()->whereKey($activity->getKey())->sole();

    expect($recorded->progress_percent)->toBe(60)
        ->and($recorded->status)->toBe(ActivityStatus::InProgress)
        // First progress stamps a start: "60% done with no start date" is a
        // contradiction the record must not carry.
        ->and($recorded->actual_start?->toDateString())->toBe('2026-06-15')
        ->and($recorded->actual_end)->toBeNull()
        ->and($recorded->expenditure_to_date->toDecimalString())->toBe('750000.00');
});

it('lets the officer accountable for a line report on it, and nobody else’s', function () {
    $plan = Workplan::factory()->forYear(2026)->active($this->admin)->create();

    $mine = WorkplanActivity::factory()->forWorkplan($plan)->ownedBy($this->consultant)->create();
    $theirs = WorkplanActivity::factory()->forWorkplan($plan)->create();

    // The consultant holds workplans.view only — and owns this line.
    (new RecordActivityProgress)($mine, $this->consultant, ['progress_percent' => 30]);

    expect(WorkplanActivity::query()->whereKey($mine->getKey())->sole()->progress_percent)->toBe(30);

    expect(fn () => (new RecordActivityProgress)($theirs, $this->consultant, ['progress_percent' => 30]))
        ->toThrow(AuthorizationException::class);

    // …and owning a line still does not let them edit its definition.
    expect(fn () => (new UpdateWorkplanActivity)($mine, $this->consultant, ['title' => 'Renamed by its owner']))
        ->toThrow(AuthorizationException::class);
});

it('refuses progress against a plan nobody has signed', function () {
    expect(fn () => (new RecordActivityProgress)($this->activity, $this->officer, ['progress_percent' => 10]))
        ->toThrow(WorkplanRuleViolation::class, 'only an approved or active plan');
});

it('refuses progress against a closed year and against a cancelled line', function () {
    $closed = Workplan::factory()->forYear(2025)->closed()->create();
    $closedLine = WorkplanActivity::factory()->forWorkplan($closed)->create();

    expect(fn () => (new RecordActivityProgress)($closedLine, $this->officer, ['progress_percent' => 10]))
        ->toThrow(WorkplanRuleViolation::class, 'only an approved or active plan');

    $live = Workplan::factory()->forYear(2026)->active($this->admin)->create();
    $cancelled = WorkplanActivity::factory()->forWorkplan($live)->cancelled()->create();

    expect(fn () => (new RecordActivityProgress)($cancelled, $this->officer, ['progress_percent' => 10]))
        ->toThrow(WorkplanRuleViolation::class, 'cancelled activity records no further progress');
});

it('clears the completion date again when a finished line is reopened', function () {
    $plan = Workplan::factory()->forYear(2026)->active($this->admin)->create();
    $activity = WorkplanActivity::factory()
        ->forWorkplan($plan)
        ->scheduled(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31))
        ->create();

    (new RecordActivityProgress)($activity, $this->officer, ['progress_percent' => 100]);

    expect(WorkplanActivity::query()->whereKey($activity->getKey())->sole()->actual_end?->toDateString())
        ->toBe('2026-06-15');

    (new RecordActivityProgress)($activity, $this->officer, ['progress_percent' => 80]);

    $reopened = WorkplanActivity::query()->whereKey($activity->getKey())->sole();

    expect($reopened->actual_end)->toBeNull()
        ->and($reopened->status)->toBe(ActivityStatus::InProgress);
});

it('surfaces the freeze on the builder screen instead of throwing at the user', function () {
    $plan = Workplan::factory()->forYear(2026)->active($this->admin)->create();
    $activity = WorkplanActivity::factory()->forWorkplan($plan)->create(['title' => 'Signed-for activity']);

    $component = Livewire::actingAs($this->admin)
        ->test(WorkplanBuilder::class, ['workplan' => $plan])
        ->call('editActivity', $activity->ulid)
        ->set('title', 'Quietly renamed after approval')
        ->call('saveActivity');

    $component->assertHasErrors('title');

    expect(WorkplanActivity::query()->whereKey($activity->getKey())->sole()->title)->toBe('Signed-for activity');

    // Progress, meanwhile, still goes through the same screen.
    Livewire::actingAs($this->admin)
        ->test(WorkplanBuilder::class, ['workplan' => $plan])
        ->call('startRecording', $activity->ulid)
        ->set('progressPercent', '45')
        ->set('progressExpenditure', '250000.00')
        ->call('saveProgress')
        ->assertHasNoErrors();

    expect(WorkplanActivity::query()->whereKey($activity->getKey())->sole()->progress_percent)->toBe(45);
});
