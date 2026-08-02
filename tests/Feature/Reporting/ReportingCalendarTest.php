<?php

/**
 * The statutory calendar (progress-reporting.md §0.1, §1.1, §3) and the
 * obligations generated against it.
 *
 * The due-date rules are the part a state is sanctioned against, so each one
 * is pinned to a date rather than to an offset: H1 is due on 31 July, H2 on
 * 31 January of the following year, and the annual return within Q1. Every
 * case sets the clock with Carbon::setTestNow() — no sleeping, no real clocks.
 */

use App\Actions\Reporting\GenerateReportingPeriods;
use App\Actions\Reporting\GenerateReportObligations;
use App\Enums\ProjectStatus;
use App\Enums\ReportingCadence;
use App\Enums\ReportObligationStatus;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-03-15 09:00:00');

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();

    $this->generatePeriods = app(GenerateReportingPeriods::class);
    $this->generateObligations = app(GenerateReportObligations::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('generates a whole year of the calendar: 12 monthly, 4 quarterly, 2 half-year and 1 annual window', function () {
    $count = ($this->generatePeriods)(2026);

    expect($count)->toBe(19)
        ->and(ReportingPeriod::query()->where('cadence', ReportingCadence::Monthly)->count())->toBe(12)
        ->and(ReportingPeriod::query()->where('cadence', ReportingCadence::Quarterly)->count())->toBe(4)
        ->and(ReportingPeriod::query()->where('cadence', ReportingCadence::Biannual)->count())->toBe(2)
        ->and(ReportingPeriod::query()->where('cadence', ReportingCadence::Annual)->count())->toBe(1);
});

it('computes every statutory due date from the digest rules', function (string $code, string $start, string $end, string $due) {
    ($this->generatePeriods)(2026);

    $period = ReportingPeriod::query()->where('code', $code)->firstOrFail();
    $zone = config('platform.instance.timezone');

    expect($period->period_start->toDateString())->toBe($start)
        ->and($period->period_end->toDateString())->toBe($end)
        // Read on the STATE's clock, which is the clock the deadline was
        // legislated on: the return is due at the last moment of the due date,
        // not at 23:59 UTC (which in Africa/Lagos is the following morning).
        ->and($period->due_at->timezone($zone)->toDateTimeString())->toBe($due.' 23:59:59')
        // …and the window opens at midnight of its first day, likewise local.
        ->and($period->opens_at->timezone($zone)->toDateTimeString())->toBe($start.' 00:00:00');
})->with([
    // monthly: period_end + 7 days
    'March 2026' => ['2026-M03', '2026-03-01', '2026-03-31', '2026-04-07'],
    'December 2026' => ['2026-M12', '2026-12-01', '2026-12-31', '2027-01-07'],
    // quarterly: period_end + 14 days
    'Q1 2026' => ['2026-Q1', '2026-01-01', '2026-03-31', '2026-04-14'],
    'Q4 2026' => ['2026-Q4', '2026-10-01', '2026-12-31', '2027-01-14'],
    // biannual: end of the month FOLLOWING the half — the statutory rule
    'H1 2026' => ['2026-H1', '2026-01-01', '2026-06-30', '2026-07-31'],
    'H2 2026' => ['2026-H2', '2026-07-01', '2026-12-31', '2027-01-31'],
    // annual: within Q1 of the following year
    'Year 2026' => ['2026-A', '2026-01-01', '2026-12-31', '2027-03-31'],
]);

it('creates no duplicates when the calendar command runs twice', function () {
    ($this->generatePeriods)(2026);
    ($this->generatePeriods)(2026);

    expect(ReportingPeriod::query()->count())->toBe(19);
});

it('stores the deadline as the UTC instant of the state’s end of day, not of UTC’s', function () {
    // The instance runs on Africa/Lagos (UTC+1) by default, so the last moment
    // of 7 April in the state is 22:59:59 UTC — an hour BEFORE UTC's own end of
    // day. Storing 23:59:59 UTC would give every MDA a free extra hour and put
    // the desk's countdown and the overdue sweep an hour out of step with each
    // other for that hour, every single deadline.
    ($this->generatePeriods)(2026);

    $march = ReportingPeriod::query()->where('code', '2026-M03')->firstOrFail();

    expect($march->due_at->utc()->toDateTimeString())->toBe('2026-04-07 22:59:59')
        ->and($march->opens_at->utc()->toDateTimeString())->toBe('2026-02-28 23:00:00');
});

it('follows the instance timezone rather than assuming one', function () {
    // White-label: an instance in a UTC-0 state gets UTC-0 boundaries from the
    // same code, because the zone is configuration and never a literal.
    config()->set('platform.instance.timezone', 'UTC');

    ($this->generatePeriods)(2026);

    $march = ReportingPeriod::query()->where('code', '2026-M03')->firstOrFail();

    expect($march->due_at->utc()->toDateTimeString())->toBe('2026-04-07 23:59:59')
        ->and($march->opens_at->utc()->toDateTimeString())->toBe('2026-03-01 00:00:00');
});

/* -------------------------------------------------------------------------- */
/* The command */
/* -------------------------------------------------------------------------- */

it('generates this year AND next when the command is run with no year', function () {
    // The regression this guards is a silent one. The cron fires on 1 December;
    // a run that produced only the current year would rebuild windows everyone
    // has already reported against and create nothing for January. On New
    // Year's Day the obligation sweep would find no open window, no reminder
    // would go out, and the deadline engine would go quiet without failing.
    $this->artisan('reporting:generate-periods')
        ->assertSuccessful();

    expect(ReportingPeriod::query()->count())->toBe(38)
        ->and(ReportingPeriod::query()->where('code', '2026-M03')->exists())->toBeTrue()
        ->and(ReportingPeriod::query()->where('code', '2027-M01')->exists())->toBeTrue()
        ->and(ReportingPeriod::query()->where('code', '2027-A')->exists())->toBeTrue();
});

it('still generates exactly one year when the command is given one', function () {
    $this->artisan('reporting:generate-periods', ['--year' => 2030])
        ->assertSuccessful();

    expect(ReportingPeriod::query()->count())->toBe(19)
        ->and(ReportingPeriod::query()->where('code', '2030-M01')->exists())->toBeTrue();
});

it('converges rather than duplicating when the scheduled command runs two Decembers running', function () {
    $this->artisan('reporting:generate-periods')->assertSuccessful();

    // A year later the overlap — 2027 — is upserted, not re-created.
    $this->travelTo(Carbon::parse('2027-12-01 00:10:00'));
    $this->artisan('reporting:generate-periods')->assertSuccessful();

    expect(ReportingPeriod::query()->count())->toBe(57)   // 2026, 2027, 2028
        ->and(ReportingPeriod::query()->where('code', '2027-M03')->count())->toBe(1);
});

it('reads the due-day offsets from settings rather than from literals', function () {
    config()->set('platform.reporting.monthly_due_days', 21);

    ($this->generatePeriods)(2026);

    expect(ReportingPeriod::query()->where('code', '2026-M03')->firstOrFail()->due_at->toDateString())
        ->toBe('2026-04-21');
});

it('leaves windows open where the instance accepts late returns, and closes them where it does not', function () {
    ($this->generatePeriods)(2026);
    expect(ReportingPeriod::query()->whereNotNull('closes_at')->count())->toBe(0);

    config()->set('platform.reporting.allow_late_submission', false);
    ($this->generatePeriods)(2026);

    $period = ReportingPeriod::query()->where('code', '2026-M03')->firstOrFail();

    expect($period->closes_at?->toDateString())->toBe($period->due_at->toDateString());
});

it('generates one obligation per reporting project per live window, in every MDA', function () {
    ($this->generatePeriods)(2026);

    $this->current->runAs($this->works, fn () => Project::factory()->count(2)->ongoing()->create(['reporting_frequency' => 'monthly']));
    $this->current->runAs($this->health, fn () => Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']));

    ($this->generateObligations)();

    $this->current->runAs($this->works, function () {
        expect(ReportObligation::query()->where('reporting_period_id', marchId())->count())->toBe(2);
    });

    $this->current->runAs($this->health, function () {
        expect(ReportObligation::query()->where('reporting_period_id', marchId())->count())->toBe(1);
    });
});

it('does not hand an MDA obligations for deadlines that have already passed', function () {
    // The regression this guards: `closes_at` is null under the default
    // configuration, so a window never technically closes. Generating for
    // every "open" window would give a project mobilized today an instantly
    // overdue return for January — and mail the escalation to the secretariat.
    ($this->generatePeriods)(2025);
    ($this->generatePeriods)(2026);

    $this->current->runAs($this->works, fn () => Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']));

    ($this->generateObligations)();

    $codes = $this->current->runAs($this->works, fn () => ReportObligation::query()
        ->with('reportingPeriod')
        ->get()
        ->map(fn (ReportObligation $obligation): string => $obligation->reportingPeriod->code)
        ->sort()
        ->values()
        ->all());

    // On 15 March 2026 exactly one monthly window is still live — the current
    // one. January and February 2026 are past their deadlines, and the whole
    // of 2025 is history.
    expect($codes)->toBe(['2026-M03']);
});

it('skips projects whose status does not owe a return', function () {
    ($this->generatePeriods)(2026);

    $this->current->runAs($this->works, function () {
        Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']);
        Project::factory()->draft()->create(['reporting_frequency' => 'monthly']);
        Project::factory()->certified()->create(['reporting_frequency' => 'monthly']);
        Project::factory()->cancelled()->create(['reporting_frequency' => 'monthly']);
    });

    ($this->generateObligations)();

    $this->current->runAs($this->works, function () {
        $obligations = ReportObligation::query()->where('reporting_period_id', marchId())->get();

        expect($obligations)->toHaveCount(1)
            ->and(Project::query()->find($obligations->first()->project_id)->status)
            ->toBe(ProjectStatus::InProgress);
    });
});

it('matches a project to the cadence it reports on, and to the instance default when it names none', function () {
    ($this->generatePeriods)(2026);

    $this->current->runAs($this->works, function () {
        Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']);
        Project::factory()->ongoing()->create(['reporting_frequency' => 'quarterly']);
        Project::factory()->ongoing()->create(['reporting_frequency' => null]);   // inherits monthly
    });

    ($this->generateObligations)();

    $this->current->runAs($this->works, function () {
        $monthly = ReportObligation::query()->where('reporting_period_id', marchId())->count();

        $q1 = ReportingPeriod::query()->where('code', '2026-Q1')->firstOrFail();
        $quarterly = ReportObligation::query()->where('reporting_period_id', $q1->id)->count();

        expect($monthly)->toBe(2)   // the explicit monthly one and the default one
            ->and($quarterly)->toBe(1);
    });
});

it('creates nothing twice when the obligation command runs twice', function () {
    ($this->generatePeriods)(2026);
    $this->current->runAs($this->works, fn () => Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']));

    ($this->generateObligations)();
    $before = $this->current->bypass(fn () => ReportObligation::query()->count());

    ($this->generateObligations)();

    expect($this->current->bypass(fn () => ReportObligation::query()->count()))->toBe($before);
});

it('refreshes the deadline on an unfulfilled obligation, and never on a filed one', function () {
    ($this->generatePeriods)(2026);
    $this->current->runAs($this->works, fn () => Project::factory()->count(2)->ongoing()->create(['reporting_frequency' => 'monthly']));

    ($this->generateObligations)();

    $march = ReportingPeriod::query()->where('code', '2026-M03')->firstOrFail();
    $originalDue = $march->due_at;

    [$pending, $filed] = $this->current->runAs($this->works, fn () => ReportObligation::query()
        ->where('reporting_period_id', $march->id)
        ->orderBy('id')
        ->get()
        ->all());

    $this->current->runAs($this->works, fn () => $filed->forceFill([
        'status' => ReportObligationStatus::Fulfilled,
        'fulfilled_at' => now(),
    ])->save());

    // The secretariat grants a two-week extension on a future deadline.
    $march->forceFill(['due_at' => $originalDue->addDays(14)])->save();

    ($this->generateObligations)();

    $this->current->runAs($this->works, function () use ($pending, $filed, $originalDue) {
        expect($pending->fresh()->due_at->toDateString())->toBe($originalDue->addDays(14)->toDateString())
            // History stays immutable: the filed return keeps the deadline it
            // was actually judged against.
            ->and($filed->fresh()->due_at->toDateString())->toBe($originalDue->toDateString());
    });
});

it('generates nothing for a workspace that has been deactivated', function () {
    ($this->generatePeriods)(2026);
    $this->current->runAs($this->health, fn () => Project::factory()->ongoing()->create(['reporting_frequency' => 'monthly']));

    $this->health->forceFill(['is_active' => false])->save();

    ($this->generateObligations)();

    expect($this->current->bypass(fn () => ReportObligation::query()->count()))->toBe(0);
});

/**
 * The id of the March 2026 monthly window — the one open on the frozen clock.
 */
function marchId(): int
{
    return ReportingPeriod::query()->where('code', '2026-M03')->firstOrFail()->id;
}
