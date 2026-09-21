<?php

/**
 * The statutory window itself: `monitoring.commencement_notice_days`.
 *
 * CommencementNoticeTest and OverdueNoticeSweepTest already prove the notice
 * lifecycle and the sweep's idempotency against a window the suite pins to 3
 * days. What is unproven until here is that the number is genuinely a SETTING
 * and not a constant with a config file next to it — that the deadline a
 * served notice carries, the lateness it is judged by, and the cutoff the
 * sweep uses are all derived from whatever the instance has configured.
 *
 * Every case therefore reads the window through the SettingsRepository the
 * Actions read, and computes the date it expects FROM that value. A test that
 * asserted "award + 3 days" would keep passing against a platform that had
 * stopped reading the setting at all.
 */

use App\Actions\Lifecycle\FlagOverdueCommencementNotices;
use App\Actions\Lifecycle\IssueCommencementNotice;
use App\Enums\Role;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 08:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    $this->issue = app(IssueCommencementNotice::class);
    $this->flag = app(FlagOverdueCommencementNotices::class);
    $this->settings = app(SettingsRepository::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A contract awarded $daysAgo, with nothing commenced against it. */
function awardedContract(Project $project, int $daysAgo): Contract
{
    return Contract::factory()->forProject($project)->create([
        'award_date' => CarbonImmutable::now()->subDays($daysAgo)->toDateString(),
        'commencement_date' => null,
    ]);
}

it('dates a served notice from the award plus the window the instance sets', function (int $window) {
    config()->set('platform.monitoring.commencement_notice_days', $window);

    expect($this->settings->int('monitoring', 'commencement_notice_days', 3))->toBe($window);

    $contract = awardedContract($this->project, daysAgo: 0);

    $notice = ($this->issue)(
        $contract,
        $this->admin,
        'Site handover on commencement.',
        CarbonImmutable::now()->addDay(),
    );

    // Derived from the setting, not from a literal — which is the whole point:
    // a state that legislates a fortnight gets a fortnight without a release.
    expect($notice->due_at->toDateString())
        ->toBe($contract->award_date->addDays($window)->toDateString())
        ->and($notice->issued_late)->toBeFalse();
})->with([
    'the statutory three days' => [3],
    'a longer window this state legislated' => [14],
    'a single day' => [1],
]);

it('judges a notice served after the configured window late, and one inside it not', function () {
    $window = $this->settings->int('monitoring', 'commencement_notice_days', 3);

    $late = awardedContract($this->project, daysAgo: $window + 2);
    $timely = awardedContract($this->project, daysAgo: $window - 1);

    $lateNotice = ($this->issue)($late, $this->admin, null, CarbonImmutable::now()->addDay());
    $timelyNotice = ($this->issue)($timely, $this->admin, null, CarbonImmutable::now()->addDay());

    expect($lateNotice->issued_late)->toBeTrue()
        ->and($timelyNotice->issued_late)->toBeFalse();
});

it('moves the sweep’s cutoff with the window, in both directions', function () {
    $contract = awardedContract($this->project, daysAgo: 7);

    // A window longer than the award is old: nothing is owed yet.
    config()->set('platform.monitoring.commencement_notice_days', 10);

    expect(($this->flag)())->toBe(['materialised' => 0, 'flagged' => 0])
        ->and(CommencementNotice::query()->count())->toBe(0);

    // Shorten it below the award's age and the same award is overdue, with a
    // deadline computed from the new window.
    config()->set('platform.monitoring.commencement_notice_days', 2);

    expect(($this->flag)())->toBe(['materialised' => 1, 'flagged' => 1]);

    $notice = CommencementNotice::query()->where('contract_id', $contract->id)->sole();

    expect($notice->due_at->toDateString())
        ->toBe($contract->award_date->addDays(2)->toDateString())
        ->and($notice->isOverdue())->toBeTrue();
});
