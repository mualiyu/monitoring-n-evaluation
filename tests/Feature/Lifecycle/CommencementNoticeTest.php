<?php

/**
 * Step 1 of the monitoring lifecycle (digest §8): the notice that tells a
 * contractor to start, and the statutory window the state has to serve it in.
 *
 * The Actions are proved here; the screens that call them are proved in
 * LifecycleScreensTest. Repeating the domain rules there would be theatre.
 */

use App\Actions\Lifecycle\AcknowledgeCommencementNotice;
use App\Actions\Lifecycle\IssueCommencementNotice;
use App\Enums\CommencementNoticeStatus;
use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Lifecycle\CommencementNoticeIssued;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);
    $this->contract = Contract::factory()->forProject($this->project)->create([
        'award_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
        'commencement_date' => null,
    ]);

    $this->issue = new IssueCommencementNotice;
});

/* -------------------------------------------------------------------------- */
/* Serving a notice */
/* -------------------------------------------------------------------------- */

it('serves a notice, snapshots the award and files the generated PDF in the vault', function () {
    $notice = ($this->issue)($this->contract, $this->admin, 'Site handover on commencement.');

    expect($notice->status)->toBe(CommencementNoticeStatus::Issued)
        ->and($notice->issued_by_id)->toBe($this->admin->id)
        ->and($notice->issued_at)->not->toBeNull()
        ->and($notice->issued_late)->toBeFalse()
        ->and($notice->tenant_id)->toBe($this->works->id)
        ->and($notice->contract_id)->toBe($this->contract->id)
        // The snapshot, not a join: a later variation must not rewrite what
        // the contractor was told.
        ->and($notice->contract_sum->toDecimalString())->toBe($this->contract->sum->toDecimalString())
        ->and($notice->scope_of_works)->toBe($this->contract->scope_of_works)
        ->and($notice->instructions)->toBe('Site handover on commencement.');

    $media = $notice->getMedia('commencement_notice');

    expect($media)->toHaveCount(1)
        ->and($media->first()->disk)->toBe('documents')
        ->and($media->first()->file_name)->toEndWith('.pdf')
        ->and($media->first()->getCustomProperty('generated'))->toBeTrue();

    Storage::disk('documents')->assertExists($media->first()->id.'/'.$media->first()->file_name);
});

it('sets the deadline from the statutory window and marks a late service as late', function () {
    config()->set('platform.monitoring.commencement_notice_days', 3);

    $late = Contract::factory()->forProject($this->project)->create([
        'award_date' => CarbonImmutable::now()->subDays(20)->toDateString(),
        'commencement_date' => null,
    ]);

    $notice = ($this->issue)($late, $this->admin);

    expect($notice->due_at->toDateString())
        ->toBe(CarbonImmutable::now()->subDays(20)->addDays(3)->toDateString())
        ->and($notice->issued_late)->toBeTrue();
});

it('notifies the contractor accounts assigned to the project', function () {
    ProjectAssignment::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'role' => ProjectRole::Consultant,
        'assigned_by_id' => $this->admin->id,
    ]);

    ($this->issue)($this->contract, $this->admin);

    Notification::assertSentTo($this->consultant, CommencementNoticeIssued::class);
    // The officer who served it is not told what they just did.
    Notification::assertNotSentTo($this->admin, CommencementNoticeIssued::class);
});

it('refuses a second notice on the same contract', function () {
    ($this->issue)($this->contract, $this->admin);

    expect(fn () => ($this->issue)($this->contract, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'already has a commencement notice');

    expect(CommencementNotice::query()->where('contract_id', $this->contract->id)->count())->toBe(1);
});

it('refuses a notice against a project that has not been awarded', function () {
    $draft = Project::factory()->draft()->create();
    $contract = Contract::factory()->forProject($draft)->create(['commencement_date' => null]);

    expect(fn () => ($this->issue)($contract, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'cannot be served against a [draft] project');

    expect(CommencementNotice::query()->count())->toBe(0);
});

it('refuses a commencement date that precedes the award', function () {
    expect(fn () => ($this->issue)(
        $this->contract,
        $this->admin,
        null,
        CarbonImmutable::parse($this->contract->award_date)->subWeek(),
    ))->toThrow(LifecycleRuleViolation::class, 'before the contract was awarded');
});

it('serves the notice a sweep already materialised rather than creating a second row', function () {
    $pending = CommencementNotice::factory()->forContract($this->contract)->pending()->create();

    $notice = ($this->issue)($this->contract, $this->admin);

    expect($notice->id)->toBe($pending->id)
        ->and($notice->status)->toBe(CommencementNoticeStatus::Issued)
        ->and(CommencementNotice::query()->where('contract_id', $this->contract->id)->count())->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* Acknowledgement */
/* -------------------------------------------------------------------------- */

it('records the contractor receipt exactly once', function () {
    $acknowledge = new AcknowledgeCommencementNotice;
    $notice = ($this->issue)($this->contract, $this->admin);

    $acknowledged = $acknowledge($notice, $this->admin, 'Signed copy returned.');

    expect($acknowledged->status)->toBe(CommencementNoticeStatus::Acknowledged)
        ->and($acknowledged->acknowledged_by_contractor_at)->not->toBeNull()
        ->and($acknowledged->acknowledgement_note)->toBe('Signed copy returned.');

    expect(fn () => $acknowledge($acknowledged, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'already been acknowledged');
});

it('refuses to acknowledge a notice that was never served', function () {
    $acknowledge = new AcknowledgeCommencementNotice;
    $pending = CommencementNotice::factory()->forContract($this->contract)->pending()->create();

    expect(fn () => $acknowledge($pending, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'has not been served');
});

it('lets the assigned contractor confirm receipt, and a stranger not', function () {
    $acknowledge = new AcknowledgeCommencementNotice;
    $notice = ($this->issue)($this->contract, $this->admin);

    $outsider = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    expect(fn () => $acknowledge($notice, $outsider))
        ->toThrow(AuthorizationException::class);

    ProjectAssignment::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'role' => ProjectRole::Consultant,
        'assigned_by_id' => $this->admin->id,
    ]);

    $acknowledged = $acknowledge($notice, $this->consultant->fresh());

    expect($acknowledged->status)->toBe(CommencementNoticeStatus::Acknowledged);
});

/* -------------------------------------------------------------------------- */
/* Authorization */
/* -------------------------------------------------------------------------- */

it('lets an M&E officer serve a notice and refuses a consultant', function () {
    $notice = ($this->issue)($this->contract, $this->officer);

    expect($notice->status)->toBe(CommencementNoticeStatus::Issued);

    $other = Contract::factory()->forProject($this->project)->create(['commencement_date' => null]);

    expect(fn () => ($this->issue)($other, $this->consultant))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* State machine */
/* -------------------------------------------------------------------------- */

it('describes the notice lifecycle as pending → issued → acknowledged, and nothing else', function () {
    expect(CommencementNoticeStatus::Pending->allowedTransitions())->toBe([CommencementNoticeStatus::Issued])
        ->and(CommencementNoticeStatus::Issued->allowedTransitions())->toBe([CommencementNoticeStatus::Acknowledged])
        ->and(CommencementNoticeStatus::Acknowledged->allowedTransitions())->toBe([])
        ->and(CommencementNoticeStatus::Acknowledged->isTerminal())->toBeTrue()
        // The forbidden move: receipt cannot be claimed before service.
        ->and(CommencementNoticeStatus::Pending->canTransitionTo(CommencementNoticeStatus::Acknowledged))->toBeFalse()
        ->and(CommencementNoticeStatus::Pending->isServed())->toBeFalse()
        ->and(CommencementNoticeStatus::Issued->isServed())->toBeTrue();
});
