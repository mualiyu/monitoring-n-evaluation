<?php

/**
 * TransitionProgressReportStatus — the only writer of ProgressReport::$status
 * and of the whole approval chain (progress-reporting.md §2).
 *
 * The cases that matter most are the refusals: a consultant reaching for
 * review or approval, an officer clearing the return they filed themselves,
 * a director approving their own review, and every hop the chain table does
 * not contain. A chain that can be walked around is not a chain.
 */

use App\Actions\Reporting\ApproveProgressReport;
use App\Actions\Reporting\DiscardProgressReportDraft;
use App\Actions\Reporting\ReturnProgressReport;
use App\Actions\Reporting\ReviewProgressReport;
use App\Actions\Reporting\SaveProgressReportDraft;
use App\Actions\Reporting\StartProgressReport;
use App\Actions\Reporting\SubmitProgressReport;
use App\Actions\Reporting\TransitionProgressReportStatus;
use App\Enums\ProgressReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReportEntryMode;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Exceptions\Reporting\InvalidReportTransition;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Jobs\Reporting\NotifyProgressReportChain;
use App\Models\ProgressReport;
use App\Models\ProgressReportEvent;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Reporting\ProgressReportChainUpdated;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->period = ReportingPeriod::factory()->monthly()->create();
    $this->project = Project::factory()->ongoing()->create([
        'physical_progress' => '20.00',
        'expenditure_to_date' => '10000000.00',
        'manager_id' => $this->officer->id,
    ]);

    // The assignment is what makes this consultant a consultant ON this
    // project: StartProgressReport authorizes `view` on the project, and
    // Project::scopeVisibleTo narrows project-level roles to their active
    // assignments. An unassigned consultant is refused — asserted below.
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->consultant = $this->consultant->fresh();

    $this->submit = app(SubmitProgressReport::class);
    $this->review = app(ReviewProgressReport::class);
    $this->approve = app(ApproveProgressReport::class);
    $this->return = app(ReturnProgressReport::class);

    $this->reportBy = fn (User $author): ProgressReport => ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($author)
        ->create(['physical_progress_claimed' => '35.00']);
});

it('walks the full chain: draft → submitted → reviewed → approved', function () {
    $report = ($this->reportBy)($this->consultant);

    ($this->submit)($report, $this->consultant);
    expect($report->fresh()->status)->toBe(ProgressReportStatus::Submitted);

    ($this->review)($report->fresh(), $this->officer);
    expect($report->fresh()->status)->toBe(ProgressReportStatus::Reviewed);

    ($this->approve)($report->fresh(), $this->admin);
    $report->refresh();

    expect($report->status)->toBe(ProgressReportStatus::Approved)
        ->and($report->submitted_by_id)->toBe($this->consultant->id)
        ->and($report->reviewed_by_id)->toBe($this->officer->id)
        ->and($report->approved_by_id)->toBe($this->admin->id);
});

it('writes one chain-ledger row per step, with the actor who signed for it', function () {
    $report = ($this->reportBy)($this->consultant);

    ($this->submit)($report, $this->consultant);
    ($this->review)($report->fresh(), $this->officer);
    ($this->approve)($report->fresh(), $this->admin);

    $events = ProgressReportEvent::query()
        ->where('progress_report_id', $report->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('to_status')->all())->toBe([
            ProgressReportStatus::Submitted,
            ProgressReportStatus::Reviewed,
            ProgressReportStatus::Approved,
        ])
        ->and($events->pluck('from_status')->all())->toBe([
            ProgressReportStatus::Draft,
            ProgressReportStatus::Submitted,
            ProgressReportStatus::Reviewed,
        ])
        ->and($events->pluck('actor_id')->all())->toBe([
            $this->consultant->id, $this->officer->id, $this->admin->id,
        ]);
});

it('keeps the chain ledger append-only', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);

    $event = ProgressReportEvent::query()->where('progress_report_id', $report->id)->firstOrFail();

    expect(fn () => $event->update(['reason' => 'rewritten']))->toThrow(RuntimeException::class)
        ->and(fn () => $event->delete())->toThrow(RuntimeException::class);
});

it('refuses a hop the chain table does not contain', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);

    // Skipping review: the reviewer's read is what approval rests on.
    expect(fn () => ($this->approve)($report->fresh(), $this->admin))
        ->toThrow(InvalidReportTransition::class, 'cannot move from [submitted] to [approved]');
});

it('makes an approved report terminal — no reopening, no second approval', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);
    ($this->review)($report->fresh(), $this->officer);
    ($this->approve)($report->fresh(), $this->admin);

    $transition = app(TransitionProgressReportStatus::class);

    foreach ([ProgressReportStatus::Returned, ProgressReportStatus::Submitted, ProgressReportStatus::Approved] as $target) {
        expect(fn () => $transition($report->fresh(), $target, $this->admin))
            ->toThrow(InvalidReportTransition::class);
    }
});

it('never lets a consultant review or approve — they hold neither permission', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);

    expect(fn () => ($this->review)($report->fresh(), $this->consultant->fresh()))
        ->toThrow(AuthorizationException::class);

    ($this->review)($report->fresh(), $this->officer);

    expect(fn () => ($this->approve)($report->fresh(), $this->consultant->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('never lets an M&E officer review the return they filed themselves', function () {
    // The on-behalf path: the officer types the contractor's numbers and
    // could otherwise clear their own submission.
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->officer)
        ->onBehalf()
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->officer);

    expect(fn () => ($this->review)($report->fresh(), $this->officer))
        ->toThrow(ReportRuleViolation::class, 'cannot review it');
});

it('never lets the submitter approve, whoever they are', function () {
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->admin)
        ->onBehalf()
        ->create(['physical_progress_claimed' => '35.00']);

    ($this->submit)($report, $this->admin);
    ($this->review)($report->fresh(), $this->officer);

    expect(fn () => ($this->approve)($report->fresh(), $this->admin))
        ->toThrow(ReportRuleViolation::class, 'cannot approve it');
});

it('refuses the reviewer their own approval while separation is required', function () {
    $secondAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $report = ($this->reportBy)($this->consultant);

    ($this->submit)($report, $this->consultant);
    ($this->review)($report->fresh(), $this->admin);   // an MDA admin may also review

    expect(fn () => ($this->approve)($report->fresh(), $this->admin))
        ->toThrow(ReportRuleViolation::class, 'cannot also approve it');

    // A second signature clears it.
    ($this->approve)($report->fresh(), $secondAdmin);

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Approved);
});

it('allows the reviewer to approve where the instance turns separation off', function () {
    // A single-M&E-office MDA: nobody else can sign, and a rule that cannot be
    // satisfied stops returns being approved at all.
    config()->set('platform.reporting.require_separate_approver', false);

    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);
    ($this->review)($report->fresh(), $this->admin);
    ($this->approve)($report->fresh(), $this->admin);

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Approved);
});

it('returns a report with a reason, and refuses to return one without', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);

    expect(fn () => ($this->return)($report->fresh(), $this->officer, '   '))
        ->toThrow(ReportRuleViolation::class, 'requires a stated reason');

    ($this->return)($report->fresh(), $this->officer, 'Expenditure does not match the valuation.');
    $report->refresh();

    expect($report->status)->toBe(ProgressReportStatus::Returned)
        ->and($report->returned_by_id)->toBe($this->officer->id)
        ->and($report->return_reason)->toBe('Expenditure does not match the valuation.');
});

it('lets a returned report be corrected and resubmitted', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);
    ($this->return)($report->fresh(), $this->officer, 'Please attach the valuation certificate.');

    $report = $report->fresh();
    expect($report->isEditable())->toBeTrue();

    app(SaveProgressReportDraft::class)($report, $this->consultant, [
        'narrative_work_done' => 'Corrected: sub-base laid across 1.2 km, valuation certificate attached.',
    ]);

    ($this->submit)($report->fresh(), $this->consultant);

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Submitted)
        ->and(ProgressReportEvent::query()->where('progress_report_id', $report->id)->count())->toBe(3);
});

it('refuses to submit a return with no account of the work done', function () {
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->create(['narrative_work_done' => '   ', 'physical_progress_claimed' => '35.00']);

    expect(fn () => ($this->submit)($report, $this->consultant))
        ->toThrow(ReportRuleViolation::class, 'without an account of the work done');
});

it('refuses a claim below the project\'s recorded progress unless it is explained', function () {
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '12.00']);   // project stands at 20%

    expect(fn () => ($this->submit)($report, $this->consultant))
        ->toThrow(ReportRuleViolation::class, 'requires a stated reason');

    $report->forceFill(['progress_decrease_reason' => 'Defective culvert removed and re-measured after inspection.'])->save();

    ($this->submit)($report->fresh(), $this->consultant);

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Submitted);
});

it('refuses a submission into a window that has not opened', function () {
    $upcoming = ReportingPeriod::factory()->upcoming()->create();

    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($upcoming)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    expect(fn () => ($this->submit)($report, $this->consultant))
        ->toThrow(ReportRuleViolation::class, 'not open for submissions');
});

it('refuses a submission into a closed window where the instance forbids late returns', function () {
    config()->set('platform.reporting.allow_late_submission', false);
    $closed = ReportingPeriod::factory()->closed()->create();

    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($closed)
        ->by($this->consultant)
        ->create(['physical_progress_claimed' => '35.00']);

    expect(fn () => ($this->submit)($report, $this->consultant))
        ->toThrow(ReportRuleViolation::class, 'is closed');
});

it('freezes the figures at submission — an autosave cannot move a filed return', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);

    expect(fn () => app(SaveProgressReportDraft::class)($report->fresh(), $this->consultant, [
        'physical_progress_claimed' => '99.00',
    ]))->toThrow(AuthorizationException::class);
});

it('lets only the author edit their own draft', function () {
    $report = ($this->reportBy)($this->consultant);

    expect(fn () => app(SaveProgressReportDraft::class)($report, $this->officer->fresh(), [
        'narrative_work_done' => 'Rewritten by someone else.',
    ]))->toThrow(AuthorizationException::class);
});

it('discards a draft and hands the window back to the deadline engine', function () {
    $start = app(StartProgressReport::class);
    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    $report = $start($this->project, $this->period, $this->consultant);
    expect($report->report_obligation_id)->toBe($obligation->id);

    app(DiscardProgressReportDraft::class)($report, $this->consultant);

    expect(ProgressReport::query()->count())->toBe(0)
        ->and($obligation->fresh()->status->value)->toBe('pending')
        // The slot is genuinely free again — a soft-deleted draft must not
        // block the next attempt.
        ->and($start($this->project, $this->period, $this->consultant)->exists)->toBeTrue();
});

it('fulfils the obligation for its window even when the draft never pointed at one', function () {
    // Started before the nightly sweep raised the obligation, so the draft
    // carries no link to it. Matching only on the stored id would leave the
    // obligation pending forever: the MDA gets chased, escalated to the
    // secretariat, and scored as a miss for a return sitting on the desk.
    $report = ($this->reportBy)($this->consultant);

    expect($report->report_obligation_id)->toBeNull();

    $obligation = ReportObligation::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->create();

    ($this->submit)($report->fresh(), $this->consultant);

    expect($obligation->fresh()->status->value)->toBe('fulfilled')
        ->and($obligation->fresh()->progress_report_id)->toBe($report->id)
        // The link is backfilled, so the row and the return agree from here on.
        ->and($report->fresh()->report_obligation_id)->toBe($obligation->id);
});

it('never announces an approval that was rolled back', function () {
    $report = ($this->reportBy)($this->consultant);

    ($this->submit)($report, $this->consultant);
    ($this->review)($report->fresh(), $this->officer);

    // Certified between the review and the signature: the project record is
    // frozen, so the propagation refuses and the whole approval transaction
    // rolls back — including the status write.
    $this->project->forceFill(['status' => ProjectStatus::Certified])->save();

    // Fresh fakes: the earlier steps legitimately sent their own notifications,
    // and what is under test is only what happens after this point.
    Notification::fake();
    Queue::fake([NotifyProgressReportChain::class]);

    expect(fn () => ($this->approve)($report->fresh(), $this->admin))
        ->toThrow(ProjectRuleViolation::class);

    expect($report->fresh()->status)->toBe(ProgressReportStatus::Reviewed);

    // The mail WAS queued — the dispatch happens inside the transaction that
    // then rolled back, which is exactly the hole. A worker picking it up a
    // moment later must find the row saying `reviewed` and say nothing, rather
    // than telling the author and the project manager that a return was
    // approved when the database says it was not.
    $queued = null;

    Queue::assertPushed(NotifyProgressReportChain::class, function (NotifyProgressReportChain $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });

    $queued->handle();

    Notification::assertNothingSent();
});

it('still announces a step that really happened', function () {
    // The guard above must not silence the chain: the counterpart proves a
    // genuine transition still reaches the people who own the next step.
    $report = ($this->reportBy)($this->consultant);

    Notification::fake();

    ($this->submit)($report, $this->consultant);

    Notification::assertSentTo($this->officer, ProgressReportChainUpdated::class);
});

it('refuses to discard anything that has been filed', function () {
    $report = ($this->reportBy)($this->consultant);
    ($this->submit)($report, $this->consultant);

    expect(fn () => app(DiscardProgressReportDraft::class)($report->fresh(), $this->consultant))
        ->toThrow(ReportRuleViolation::class, 'Only a draft may be discarded');
});

it('opens one live return per window, whoever asks for it', function () {
    $start = app(StartProgressReport::class);

    $first = $start($this->project, $this->period, $this->consultant);
    $second = $start($this->project, $this->period, $this->consultant);

    expect($second->id)->toBe($first->id)
        ->and(ProgressReport::query()->count())->toBe(1);

    app(SaveProgressReportDraft::class)($first, $this->consultant, [
        'narrative_work_done' => 'Sub-base laid across 1.2 km.',
    ]);

    ($this->submit)($first->fresh(), $this->consultant);

    // Once filed, the window is answered: a second return would give it two
    // answers.
    expect(fn () => $start($this->project, $this->period, $this->officer))
        ->toThrow(ReportRuleViolation::class, 'already has a live report');
});

it('records provenance: a consultant files for themselves, an officer files on behalf', function () {
    $start = app(StartProgressReport::class);

    $selfService = $start($this->project, $this->period, $this->consultant->fresh());
    expect($selfService->entry_mode)->toBe(ReportEntryMode::SelfService);

    app(DiscardProgressReportDraft::class)($selfService, $this->consultant);

    $onBehalf = $start($this->project, $this->period, $this->officer->fresh());
    expect($onBehalf->entry_mode)->toBe(ReportEntryMode::OnBehalf);
});

it('refuses to open a return against a project that does not report', function () {
    $draft = Project::factory()->draft()->create();

    expect(fn () => app(StartProgressReport::class)($draft, $this->period, $this->officer))
        ->toThrow(ReportRuleViolation::class, 'does not report progress');
});

it('refuses a consultant a return on a project of this workspace they are not assigned to', function () {
    // `reports.create` says a consultant may file returns. It does not say
    // which projects are theirs — and the wizard's option list is a screen, not
    // a control: a Livewire endpoint takes whatever payload it is handed.
    $unassigned = Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);

    expect(fn () => app(StartProgressReport::class)($unassigned, $this->period, $this->consultant->fresh()))
        ->toThrow(AuthorizationException::class);

    expect(ProgressReport::query()->where('project_id', $unassigned->id)->count())->toBe(0);
});

it('refuses anyone a return on another workspace’s project, whatever their role here', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $foreign = app(CurrentTenant::class)->runAs($health, fn (): Project => Project::factory()->ongoing()->create());

    actingOnTenant($this->works);

    // An MDA admin of Works holds every reporting permission there is — in
    // Works. The tenant match inside the policy is what stops the ULID of
    // another ministry's project from becoming a return in this one.
    expect(fn () => app(StartProgressReport::class)($foreign, $this->period, $this->admin->fresh()))
        ->toThrow(AuthorizationException::class);
});
