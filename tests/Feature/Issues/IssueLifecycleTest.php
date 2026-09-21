<?php

/**
 * TransitionIssueStatus — the single writer of Issue::$status.
 *
 * Every allowed transition is exercised, plus the forbidden ones and each
 * guard the Action adds on top of the lifecycle table: the system-only
 * escalation rung, the mandatory resolution note, the mandatory reason on a
 * close that skips resolution, and the append-only ledger.
 */

use App\Actions\Issues\AssignIssue;
use App\Actions\Issues\RaiseIssue;
use App\Actions\Issues\RecordCorrectiveAction;
use App\Actions\Issues\TransitionIssueStatus;
use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\Role;
use App\Exceptions\Issues\InvalidIssueTransition;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Jobs\Issues\NotifyCriticalIssueRaised;
use App\Jobs\Issues\NotifyIssueAssigned;
use App\Jobs\Issues\NotifyIssueEscalated;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    $this->transition = app(TransitionIssueStatus::class);
});

/* -------------------------------------------------------------------------- */
/* Raising */
/* -------------------------------------------------------------------------- */

it('opens an issue and its ledger in one act', function () {
    $issue = app(RaiseIssue::class)($this->project, $this->officer, [
        'title' => 'Access road impassable',
        'description' => 'The culvert at chainage 2+150 failed and the haul route is cut.',
        'category' => IssueCategory::Access,
        'severity' => IssueSeverity::High,
    ]);

    expect($issue->status)->toBe(IssueStatus::Open)
        ->and($issue->tenant_id)->toBe($this->works->id)
        ->and($issue->raised_by_id)->toBe($this->officer->id)
        ->and($issue->ulid)->not->toBeEmpty();

    // The ledger opens with the raise: a timeline whose first entry is
    // "acknowledged" cannot say when the problem was first known, which is
    // the one date the escalation ladder runs on.
    $events = IssueEvent::query()->where('issue_id', $issue->id)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->from_status)->toBeNull()
        ->and($events->first()->to_status)->toBe(IssueStatus::Open)
        ->and($events->first()->isCreation())->toBeTrue();
});

it('lets a consultant raise an issue — the person who sees the problem records it', function () {
    // The manual's whole premise. `issues.create` is deliberately wide.
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $consultant = User::query()->findOrFail($this->consultant->id);

    $issue = app(RaiseIssue::class)($this->project, $consultant, [
        'title' => 'Cash release outstanding',
        'description' => 'The second interim valuation has been unpaid for five weeks.',
        'category' => IssueCategory::Funding,
        'severity' => IssueSeverity::High,
    ]);

    expect($issue->raised_by_id)->toBe($consultant->id);
});

it('announces a critical raise immediately, and stays quiet about an ordinary one', function () {
    app(RaiseIssue::class)($this->project, $this->officer, [
        'title' => 'Pier cracked on the completed span',
        'description' => 'A pier has cracked and the crossing is closed to traffic.',
        'category' => IssueCategory::Design,
        'severity' => IssueSeverity::Critical,
    ]);

    Bus::assertDispatched(NotifyCriticalIssueRaised::class);

    Bus::fake();

    app(RaiseIssue::class)($this->project, $this->officer, [
        'title' => 'Minor signage query',
        'description' => 'The chainage boards use the superseded state crest.',
        'category' => IssueCategory::Other,
        'severity' => IssueSeverity::Low,
    ]);

    Bus::assertNotDispatched(NotifyCriticalIssueRaised::class);
});

it('records the record that surfaced the issue, whatever kind of record it is', function () {
    $report = ProgressReport::factory()->forProject($this->project)->create();

    $issue = app(RaiseIssue::class)($this->project, $this->officer, [
        'title' => 'Challenges reported on the February return',
        'description' => 'The return describes two weeks lost to unseasonal rainfall.',
        'category' => IssueCategory::Weather,
        'severity' => IssueSeverity::Medium,
    ], $report);

    expect($issue->source_type)->toBe($report->getMorphClass())
        ->and($issue->source_id)->toBe($report->id);
});

it('refuses a source record belonging to a different project', function () {
    $otherProject = Project::factory()->ongoing()->create();
    $report = ProgressReport::factory()->forProject($otherProject)->create();

    expect(fn () => app(RaiseIssue::class)($this->project, $this->officer, [
        'title' => 'Mismatched source',
        'description' => 'This should never be recordable.',
        'category' => IssueCategory::Other,
        'severity' => IssueSeverity::Low,
    ], $report))->toThrow(InvalidArgumentException::class);
});

/* -------------------------------------------------------------------------- */
/* Every allowed transition */
/* -------------------------------------------------------------------------- */

it('walks the whole chain from open to closed, writing a ledger row per step', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    ($this->transition)($issue, IssueStatus::Acknowledged, $this->officer);
    expect($issue->status)->toBe(IssueStatus::Acknowledged)
        ->and($issue->acknowledged_by_id)->toBe($this->officer->id);

    ($this->transition)($issue, IssueStatus::InProgress, $this->officer);
    expect($issue->status)->toBe(IssueStatus::InProgress);

    ($this->transition)($issue, IssueStatus::Resolved, $this->officer, 'Crossing reinstated on 14 March.');
    expect($issue->status)->toBe(IssueStatus::Resolved)
        ->and($issue->resolution_note)->toBe('Crossing reinstated on 14 March.')
        ->and($issue->resolved_by_id)->toBe($this->officer->id);

    ($this->transition)($issue, IssueStatus::Closed, $this->admin);
    expect($issue->status)->toBe(IssueStatus::Closed)
        ->and($issue->closed_by_id)->toBe($this->admin->id);

    // One raise event is not written here (the factory is a fixture), so the
    // ledger holds exactly the four steps taken.
    expect(IssueEvent::query()->where('issue_id', $issue->id)->count())->toBe(4);
});

it('reopens a resolution that did not hold, clearing the stamps that no longer apply', function () {
    $issue = Issue::factory()->forProject($this->project)->resolved($this->officer)->create();

    ($this->transition)($issue, IssueStatus::InProgress, $this->officer);

    expect($issue->status)->toBe(IssueStatus::InProgress)
        // The record must not still claim to be resolved while its status says
        // otherwise. The ledger keeps the resolution that was.
        ->and($issue->resolved_at)->toBeNull()
        ->and($issue->resolved_by_id)->toBeNull();

    $resolutions = IssueEvent::query()
        ->where('issue_id', $issue->id)
        ->where('to_status', IssueStatus::Resolved)
        ->count();

    expect($resolutions)->toBe(0); // the factory state wrote no ledger row
});

it('keeps the FIRST acknowledgement, so an escalation cannot erase the delay that caused it', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    CarbonImmutable::setTestNow('2026-03-01 09:00:00');
    ($this->transition)($issue, IssueStatus::Acknowledged, $this->officer);
    $firstAck = $issue->acknowledged_at;

    CarbonImmutable::setTestNow('2026-03-20 09:00:00');
    ($this->transition)($issue, IssueStatus::Escalated, null, 'Escalated automatically.');
    ($this->transition)($issue, IssueStatus::Acknowledged, $this->admin);

    expect($issue->acknowledged_at->toDateTimeString())->toBe($firstAck->toDateTimeString())
        ->and($issue->acknowledged_by_id)->toBe($this->officer->id);

    CarbonImmutable::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* Forbidden transitions */
/* -------------------------------------------------------------------------- */

it('refuses a move the lifecycle table does not allow', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    // open → resolved skips acknowledgement and being worked on.
    expect(fn () => ($this->transition)($issue, IssueStatus::Resolved, $this->officer, 'Done somehow.'))
        ->toThrow(InvalidIssueTransition::class);
});

it('refuses to move a closed issue at all', function () {
    $issue = Issue::factory()->forProject($this->project)->closed($this->admin)->create();

    expect(fn () => ($this->transition)($issue, IssueStatus::InProgress, $this->officer))
        ->toThrow(InvalidIssueTransition::class);
});

/* -------------------------------------------------------------------------- */
/* The guards on top of the table */
/* -------------------------------------------------------------------------- */

it('refuses to let a person escalate — escalation is what happens when nobody acted', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    expect(fn () => ($this->transition)($issue, IssueStatus::Escalated, $this->admin, 'I want this seen.'))
        ->toThrow(IssueRuleViolation::class, 'Only the threshold engine raises the escalation rung');
});

it('refuses an unattributed move on any rung but escalation', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    expect(fn () => ($this->transition)($issue, IssueStatus::Acknowledged, null))
        ->toThrow(IssueRuleViolation::class, 'requires an actor');
});

it('refuses to resolve an issue without saying what was done', function () {
    $issue = Issue::factory()->forProject($this->project)->inProgress()->create();

    expect(fn () => ($this->transition)($issue, IssueStatus::Resolved, $this->officer))
        ->toThrow(IssueRuleViolation::class, 'requires a note saying what was actually done');

    expect(fn () => ($this->transition)($issue, IssueStatus::Resolved, $this->officer, '   '))
        ->toThrow(IssueRuleViolation::class);
});

it('refuses a close that skips resolution without a stated reason', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    expect(fn () => ($this->transition)($issue, IssueStatus::Closed, $this->admin))
        ->toThrow(IssueRuleViolation::class, 'requires a stated reason');

    // …and accepts one with a reason: raised in error, duplicate, overtaken.
    ($this->transition)($issue, IssueStatus::Closed, $this->admin, 'Duplicate of the right-of-way issue.');

    expect($issue->status)->toBe(IssueStatus::Closed);
});

it('needs no further words to close an already-resolved issue', function () {
    $issue = Issue::factory()->forProject($this->project)->resolved($this->officer)->create();

    ($this->transition)($issue, IssueStatus::Closed, $this->admin);

    expect($issue->status)->toBe(IssueStatus::Closed);
});

/* -------------------------------------------------------------------------- */
/* Authorization at the chokepoint */
/* -------------------------------------------------------------------------- */

it('refuses a consultant the rungs their role does not hold', function () {
    $issue = Issue::factory()->forProject($this->project)->inProgress()->create();
    $consultant = User::query()->findOrFail($this->consultant->id);

    expect(fn () => ($this->transition)($issue, IssueStatus::Resolved, $consultant, 'All sorted.'))
        ->toThrow(AuthorizationException::class);
});

it('refuses an M&E officer the close, which is the MDA admin\'s act', function () {
    $issue = Issue::factory()->forProject($this->project)->resolved($this->officer)->create();
    $officer = User::query()->findOrFail($this->officer->id);

    expect(fn () => ($this->transition)($issue, IssueStatus::Closed, $officer))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* The ledger is append-only */
/* -------------------------------------------------------------------------- */

it('refuses to edit or delete a ledger row', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    ($this->transition)($issue, IssueStatus::Acknowledged, $this->officer);

    $event = IssueEvent::query()->where('issue_id', $issue->id)->firstOrFail();

    expect(fn () => $event->update(['reason' => 'rewritten']))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('records a system escalation with no actor, rather than blaming whoever ran the sweep', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    ($this->transition)($issue, IssueStatus::Escalated, null, 'Escalated automatically: open for 9 days.');

    $event = IssueEvent::query()
        ->where('issue_id', $issue->id)
        ->where('to_status', IssueStatus::Escalated)
        ->firstOrFail();

    expect($event->actor_id)->toBeNull()
        ->and($event->isSystemAction())->toBeTrue()
        ->and($issue->escalated_at)->not->toBeNull();

    Bus::assertDispatched(NotifyIssueEscalated::class);
});

/* -------------------------------------------------------------------------- */
/* Owner + corrective action */
/* -------------------------------------------------------------------------- */

it('assigns an owner and tells them', function () {
    $issue = Issue::factory()->forProject($this->project)->create();
    $owner = User::query()->findOrFail($this->officer->id);

    app(AssignIssue::class)($issue, $owner, $this->admin);

    expect($issue->owner_id)->toBe($owner->id);

    Bus::assertDispatched(NotifyIssueAssigned::class);
});

it('refuses to assign an issue to someone outside the workspace', function () {
    $issue = Issue::factory()->forProject($this->project)->create();
    $outsider = User::factory()->create();

    expect(fn () => app(AssignIssue::class)($issue, $outsider, $this->admin))
        ->toThrow(IssueRuleViolation::class, 'someone who works in this entity');
});

it('stays quiet when somebody takes an issue themselves', function () {
    $issue = Issue::factory()->forProject($this->project)->create();
    $officer = User::query()->findOrFail($this->officer->id);

    app(AssignIssue::class)($issue, $officer, $officer);

    Bus::assertNotDispatched(NotifyIssueAssigned::class);
});

it('records the corrective action, the deadline and a revised severity', function () {
    $issue = Issue::factory()->forProject($this->project)->create();

    app(RecordCorrectiveAction::class)($issue, $this->officer, [
        'corrective_action' => 'Temporary bailey crossing to be installed.',
        'due_date' => '2026-10-15',
        'severity' => IssueSeverity::Critical,
    ]);

    expect($issue->corrective_action)->toBe('Temporary bailey crossing to be installed.')
        ->and($issue->due_date->toDateString())->toBe('2026-10-15')
        // Severity is a judgement, not a fact, and it drives the escalation
        // ladder — an officer must be able to correct a raiser's reading.
        ->and($issue->severity)->toBe(IssueSeverity::Critical);
});

it('refuses to edit a closed issue', function () {
    $issue = Issue::factory()->forProject($this->project)->closed($this->admin)->create();

    expect(fn () => app(RecordCorrectiveAction::class)($issue, $this->officer, [
        'corrective_action' => 'Too late.',
    ]))->toThrow(IssueRuleViolation::class, 'no longer be edited');
});

it('marks an issue overdue only once its own deadline has passed', function () {
    CarbonImmutable::setTestNow('2026-09-20 09:00:00');

    $withDeadline = Issue::factory()->forProject($this->project)->overdue(5)->create();
    $future = Issue::factory()->forProject($this->project)->dueIn(5)->create();
    // An issue with no due date is never overdue: a deadline nobody set is not
    // a deadline missed, and flagging one teaches officers to ignore the flag.
    $undated = Issue::factory()->forProject($this->project)->create();

    expect($withDeadline->isOverdue())->toBeTrue()
        ->and($future->isOverdue())->toBeFalse()
        ->and($undated->isOverdue())->toBeFalse();

    CarbonImmutable::setTestNow();
});
