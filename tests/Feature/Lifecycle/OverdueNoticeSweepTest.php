<?php

/**
 * The compliance sweep behind step 1: awards that have gone past the statutory
 * window with no notice served.
 *
 * Idempotency is the property under test. Every gate is a column written under
 * a row lock in the same transaction as the dispatch, so a second run the same
 * morning must be completely silent — otherwise an entity administrator is
 * told the same thing every day until someone mutes the sender.
 */

use App\Actions\Lifecycle\FlagOverdueCommencementNotices;
use App\Enums\CommencementNoticeStatus;
use App\Enums\Role;
use App\Jobs\Lifecycle\NotifyCommencementNoticeOverdue;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Lifecycle\CommencementNoticeOverdue;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    // Every date in this suite is relative to a fixed clock: the sweep is
    // deadline arithmetic, and a test that sleeps or reads the wall clock
    // fails at midnight.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 08:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->flag = new FlagOverdueCommencementNotices;

    config()->set('platform.monitoring.commencement_notice_days', 3);
});

afterEach(function () {
    Carbon::setTestNow();
});

function overdueAward(Project $project, int $awardedDaysAgo = 20): Contract
{
    return Contract::factory()->forProject($project)->create([
        'award_date' => CarbonImmutable::now()->subDays($awardedDaysAgo)->toDateString(),
        'commencement_date' => null,
    ]);
}

it('materialises a pending notice for an overdue award and announces it once', function () {
    $project = Project::factory()->ongoing()->create();
    $contract = overdueAward($project);

    $first = ($this->flag)();

    expect($first)->toBe(['materialised' => 1, 'flagged' => 1]);

    $notice = CommencementNotice::query()->where('contract_id', $contract->id)->firstOrFail();

    expect($notice->status)->toBe(CommencementNoticeStatus::Pending)
        ->and($notice->tenant_id)->toBe($this->works->id)
        // Award + the statutory window, snapshotted.
        ->and($notice->due_at->toDateString())->toBe(CarbonImmutable::now()->subDays(17)->toDateString())
        ->and($notice->overdue_notified_at)->not->toBeNull()
        ->and($notice->contract_sum->toDecimalString())->toBe($contract->sum->toDecimalString());

    Notification::assertSentTo($this->admin, CommencementNoticeOverdue::class);

    // The second run: no new row, no second notification.
    $second = ($this->flag)();

    expect($second)->toBe(['materialised' => 0, 'flagged' => 0])
        ->and(CommencementNotice::query()->count())->toBe(1);

    Notification::assertSentToTimes($this->admin, CommencementNoticeOverdue::class, 1);
});

it('leaves an award still inside its window alone', function () {
    $project = Project::factory()->ongoing()->create();
    overdueAward($project, awardedDaysAgo: 1);

    expect(($this->flag)())->toBe(['materialised' => 0, 'flagged' => 0])
        ->and(CommencementNotice::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('records but does not chase an award whose notice has already been served', function () {
    $project = Project::factory()->ongoing()->create();
    $contract = overdueAward($project);

    CommencementNotice::factory()->forContract($contract)->issued()->create();

    expect(($this->flag)())->toBe(['materialised' => 0, 'flagged' => 0]);

    Notification::assertNothingSent();
});

it('skips variations, terminated contracts and projects with nothing to commence', function () {
    $project = Project::factory()->ongoing()->create();
    $original = overdueAward($project);

    // A variation is raised against works already commenced.
    Contract::factory()->forProject($project)->variation($original)->create([
        'award_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
    ]);

    // Nothing to commence.
    Contract::factory()->forProject($project)->terminated()->create([
        'award_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'commencement_date' => null,
    ]);

    $draft = Project::factory()->draft()->create();
    overdueAward($draft, awardedDaysAgo: 40);

    $cancelled = Project::factory()->cancelled()->create();
    overdueAward($cancelled, awardedDaysAgo: 40);

    expect(($this->flag)())->toBe(['materialised' => 1, 'flagged' => 1]);

    expect(CommencementNotice::query()->where('contract_id', $original->id)->exists())->toBeTrue()
        ->and(CommencementNotice::query()->count())->toBe(1);
});

it('honours a longer statutory window set for the instance', function () {
    config()->set('platform.monitoring.commencement_notice_days', 30);

    $project = Project::factory()->ongoing()->create();
    overdueAward($project, awardedDaysAgo: 20);

    expect(($this->flag)())->toBe(['materialised' => 0, 'flagged' => 0]);
});

it('carries tenancy into every workspace it sweeps', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $healthAdmin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

    $worksProject = Project::factory()->ongoing()->create();
    overdueAward($worksProject);

    app(CurrentTenant::class)->runAs($health, function (): void {
        $project = Project::factory()->ongoing()->create();
        overdueAward($project);
    });

    expect(($this->flag)())->toBe(['materialised' => 2, 'flagged' => 2]);

    // Each notice landed in its own workspace, and each administrator heard
    // only about their own.
    app(CurrentTenant::class)->runAs($this->works, function (): void {
        expect(CommencementNotice::query()->count())->toBe(1);
    });

    app(CurrentTenant::class)->runAs($health, function (): void {
        expect(CommencementNotice::query()->count())->toBe(1);
    });

    Notification::assertSentToTimes($this->admin, CommencementNoticeOverdue::class, 1);
    Notification::assertSentToTimes($healthAdmin, CommencementNoticeOverdue::class, 1);
});

it('runs from the scheduled console command', function () {
    $project = Project::factory()->ongoing()->create();
    overdueAward($project);

    $this->artisan('lifecycle:flag-overdue-notices')
        ->expectsOutputToContain('1 outstanding notice(s) recorded and 1 overdue notice(s) announced.')
        ->assertSuccessful();

    expect(CommencementNotice::query()->count())->toBe(1);
});

it('says nothing about a notice served between the sweep and the worker', function () {
    $project = Project::factory()->ongoing()->create();
    $contract = overdueAward($project);

    $notice = CommencementNotice::factory()->forContract($contract)->overdue()->create();

    // The job re-reads the row: by the time it runs, an officer has served it.
    $notice->forceFill([
        'status' => CommencementNoticeStatus::Issued,
        'issued_at' => now(),
        'issued_by_id' => $this->admin->id,
    ])->save();

    (new NotifyCommencementNoticeOverdue($notice->id))->handle();

    Notification::assertNothingSent();
});
