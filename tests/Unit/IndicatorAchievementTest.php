<?php

/**
 * THE achievement arithmetic (App\Support\IndicatorAchievement).
 *
 * This is the file where a wrong number misleads a governor. Every screen,
 * export, dashboard tile and traffic light in the platform reads its
 * percentage from this one class, so each documented rule gets its own case
 * here rather than being inferred from a feature test that happens to render
 * a badge.
 *
 * The failure modes being guarded, in order of how expensive they are:
 *
 *  - a naive actual/target on a REDUCTION indicator ("cut under-five mortality
 *    from 45 to 20") reports 150% achieved while the figure is getting worse;
 *  - a clamp at 100% hides over-delivery and, worse, hides a regression below
 *    baseline by reading it as "no progress";
 *  - a zero or null target divides by zero, which is either a 500 on a
 *    dashboard or — if it is swallowed — a fabricated 0%;
 *  - a traffic-light threshold typed as a literal makes one state's "on track"
 *    another's, so the bands are asserted against the CONFIG they come from;
 *  - an unvalidated figure counted towards achievement defeats the entire
 *    Data Quality Reviewer role.
 *
 * WHY THIS UNIT FILE TOUCHES THE DATABASE. `percentFor()` is pure arithmetic
 * and most of what is below never leaves PHP. But `bandFor()` reads the
 * threshold through App\Support\SettingsRepository (tenant override → instance
 * setting → config default) and `scopeCountable()` reads the validation
 * policy the same way — both are container + database calls by design, and
 * asserting them against a hand-copied literal instead would test nothing.
 * tests/Pest.php applies RefreshDatabase to the Feature suite only, so this
 * file opts in for itself.
 */

use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\IndicatorAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * An UNSAVED indicator carrying only what the arithmetic reads: unit, target
 * type and baseline. No database, no tenant — the maths must not depend on
 * either, and a fixture would put rows between the sum and the assertion.
 *
 * @param  array<string, mixed>  $attributes
 */
function measure(array $attributes = []): Indicator
{
    return (new Indicator)->forceFill([
        'name' => 'Kilometres of road rehabilitated',
        'unit' => IndicatorUnit::Number,
        'measurement_frequency' => MeasurementFrequency::Quarterly,
        'target_type' => TargetType::Continuous,
        'baseline_value' => null,
        ...$attributes,
    ]);
}

/** The instance's own thresholds, read the way the code reads them. */
function onTrackThreshold(): int
{
    return (int) config('platform.indicators.on_track_percent');
}

function atRiskThreshold(): int
{
    return (int) config('platform.indicators.at_risk_percent');
}

/**
 * The achievement the register would draw for this indicator right now —
 * re-read through the model with the two `ofMany` sub-selects eager-loaded,
 * exactly as IndicatorIndex does it. Which readings qualify is decided inside
 * IndicatorReading::scopeCountable(), which is the thing under test.
 */
function dashboardAchievement(Indicator $indicator): IndicatorAchievement
{
    return Indicator::query()
        ->with(['latestTarget', 'latestCountableReading'])
        ->whereKey($indicator->getKey())
        ->firstOrFail()
        ->achievement();
}

/*
|--------------------------------------------------------------------------
| Target type: continuous and time-bound — movement along the intended distance
|--------------------------------------------------------------------------
| Continuous and time-bound share one formula on purpose: the difference
| between them is WHEN the target is judged (a date on the target row), not
| how the percentage is computed. Both are asserted, so a future divergence
| has to be a deliberate one.
*/

it('scores a rising indicator as the share of the intended distance travelled', function (TargetType $type) {
    $indicator = measure(['target_type' => $type, 'baseline_value' => '0']);

    expect(IndicatorAchievement::percentFor($indicator, '40', '100'))->toBe(40.0);
})->with([
    'continuous' => [TargetType::Continuous],
    'time-bound' => [TargetType::TimeBound],
]);

it('measures a rise from a NON-ZERO baseline, not from zero', function () {
    // 1,200 → 2,000 enrolled, currently 1,600: half the intended gain, not 80%.
    $indicator = measure(['baseline_value' => '1200']);

    expect(IndicatorAchievement::percentFor($indicator, '1600', '2000'))->toBe(50.0);
});

it('scores a reduction indicator on the reduction achieved, never as actual over target', function (TargetType $type) {
    // The case the whole baseline formula exists for: under-five mortality
    // 45 → 20, currently 30. A naive actual/target reports 150% achieved on a
    // figure that is still 10 points short.
    $indicator = measure([
        'target_type' => $type,
        'unit' => IndicatorUnit::Number,
        'baseline_value' => '45',
    ]);

    expect(IndicatorAchievement::percentFor($indicator, '30', '20'))->toBe(60.0);
})->with([
    'continuous' => [TargetType::Continuous],
    'time-bound' => [TargetType::TimeBound],
]);

it('reports a figure that moved backwards from baseline as a negative, not as zero', function () {
    // 45 → 20 intended, now 50: worse than it started. A clamp at zero would
    // render this identically to "nothing has happened yet", which is the one
    // reading a commissioner must not be given.
    $indicator = measure(['baseline_value' => '45']);

    expect(IndicatorAchievement::percentFor($indicator, '50', '20'))->toBe(-20.0);
});

it('reports over-delivery above 100 rather than clamping it', function () {
    $indicator = measure(['baseline_value' => '0']);

    expect(IndicatorAchievement::percentFor($indicator, '130', '100'))->toBe(130.0);
});

it('falls back to the plain ratio when a target asks for no change from baseline', function () {
    // "Hold 95% immunisation coverage": there is no distance to travel, so
    // there is nothing to divide by — the baseline formula would be 0/0.
    $indicator = measure([
        'unit' => IndicatorUnit::Percentage,
        'baseline_value' => '95',
    ]);

    expect(IndicatorAchievement::percentFor($indicator, '90', '95'))->toBe(94.74);
});

it('falls back to the plain ratio when the indicator has no baseline at all', function () {
    $indicator = measure(['baseline_value' => null]);

    expect(IndicatorAchievement::percentFor($indicator, '200', '250'))->toBe(80.0);
});

/*
|--------------------------------------------------------------------------
| Target type: percentage achievement — the baseline plays no part
|--------------------------------------------------------------------------
*/

it('ignores the baseline for a percentage-achievement target', function () {
    // The actual is already stated as a share of an agreed total, so it starts
    // from zero delivery by construction. Same numbers as a continuous
    // indicator would score 33.33% here; they must not.
    $indicator = measure([
        'unit' => IndicatorUnit::Percentage,
        'target_type' => TargetType::PercentageAchievement,
        'baseline_value' => '10',
    ]);

    expect(IndicatorAchievement::percentFor($indicator, '40', '80'))->toBe(50.0);
});

it('reads a percentage-achievement target upwards even when the target is below the baseline', function () {
    // A baseline above the target would flip a continuous indicator into
    // lower-is-better. Percentage achievement never consults the baseline, so
    // the direction cannot flip.
    $indicator = measure([
        'unit' => IndicatorUnit::Percentage,
        'target_type' => TargetType::PercentageAchievement,
        'baseline_value' => '90',
    ]);

    expect(IndicatorAchievement::percentFor($indicator, '25', '50'))->toBe(50.0);
});

/*
|--------------------------------------------------------------------------
| Units
|--------------------------------------------------------------------------
*/

it('scores a one-off milestone as all or nothing', function (string $actual, float $expected) {
    $indicator = measure([
        'unit' => IndicatorUnit::OneOff,
        'measurement_frequency' => MeasurementFrequency::OneOff,
        'baseline_value' => '0',
    ]);

    // "60% of a commissioning ceremony" is not information.
    expect(IndicatorAchievement::percentFor($indicator, $actual, '1'))->toBe($expected);
})->with([
    'not yet done' => ['0', 0.0],
    'done' => ['1', 100.0],
    'done more than once' => ['2', 100.0],
    'part-done is still not done' => ['0.9999', 0.0],
]);

it('reads a milestone as a milestone whatever its target type says', function () {
    // The unit is checked before the target type, deliberately: a one-off
    // scored as a percentage of an agreed total would report 50% of an event
    // that either happened or did not.
    $indicator = measure([
        'unit' => IndicatorUnit::OneOff,
        'target_type' => TargetType::PercentageAchievement,
        'baseline_value' => '0',
    ]);

    expect(IndicatorAchievement::percentFor($indicator, '1', '2'))->toBe(0.0)
        ->and(IndicatorAchievement::percentFor($indicator, '2', '2'))->toBe(100.0);
});

it('treats a time measure as lower-is-better when it has no baseline to infer direction from', function (string $actual, float $expected) {
    // Days to process a permit: at or under target is full achievement, and
    // beyond it the shortfall is target/actual.
    $indicator = measure(['unit' => IndicatorUnit::Time, 'baseline_value' => null]);

    expect(IndicatorAchievement::percentFor($indicator, $actual, '5'))->toBe($expected);
})->with([
    'well inside the target' => ['3', 100.0],
    'exactly on target' => ['5', 100.0],
    'twice as long as allowed' => ['10', 50.0],
    'four times as long' => ['20', 25.0],
]);

it('takes direction from the baseline rather than the unit when both are available', function () {
    // 14 days → 5 days, currently 8: two-thirds of the intended saving.
    $indicator = measure(['unit' => IndicatorUnit::Time, 'baseline_value' => '14']);

    expect(IndicatorAchievement::percentFor($indicator, '8', '5'))->toBe(66.67);
});

it('counts a number unit upwards by default even with no baseline', function () {
    expect(IndicatorAchievement::percentFor(measure(['unit' => IndicatorUnit::Number]), '30', '60'))->toBe(50.0)
        ->and(IndicatorAchievement::percentFor(measure(['unit' => IndicatorUnit::Percentage]), '30', '60'))->toBe(50.0);
});

/*
|--------------------------------------------------------------------------
| The edges: zero targets, null targets, no readings
|--------------------------------------------------------------------------
*/

it('answers a zero target on every branch without ever dividing by zero', function (Indicator $indicator, string $actual, float $expected) {
    // Each row reaches a different branch of percentFor() with a zero in the
    // denominator position. None may throw, and none may invent a figure: a
    // swallowed DivisionByZeroError reported as 0% is the worse of the two.
    expect(IndicatorAchievement::percentFor($indicator, $actual, '0'))->toBe($expected);
})->with([
    'upwards, target missed' => [fn () => measure(), '5', 0.0],
    'upwards, target met exactly' => [fn () => measure(), '0', 100.0],
    'percentage achievement, target missed' => [fn () => measure(['target_type' => TargetType::PercentageAchievement]), '5', 0.0],
    'downwards, target overshot' => [fn () => measure(['unit' => IndicatorUnit::Time]), '5', 0.0],
    'downwards, target met' => [fn () => measure(['unit' => IndicatorUnit::Time]), '0', 100.0],
    'reduction to zero, fully achieved' => [fn () => measure(['baseline_value' => '45']), '0', 100.0],
    'one-off milestone with a zero target' => [fn () => measure(['unit' => IndicatorUnit::OneOff]), '0', 100.0],
]);

it('treats a zero target as met by zero and missed by anything else', function () {
    // "Commission 0 new boreholes this quarter" is achieved by commissioning
    // none. Reporting 0% for a target that was met exactly would be wrong;
    // reporting 100% for five unbudgeted boreholes would be worse.
    $indicator = measure();

    expect(IndicatorAchievement::percentFor($indicator, '0', '0'))->toBe(100.0)
        ->and(IndicatorAchievement::percentFor($indicator, '5', '0'))->toBe(0.0);
});

it('scores a reduction all the way to zero as fully achieved', function () {
    // Guinea-worm cases 45 → 0, now 0. The baseline branch handles this one:
    // the denominator is the baseline itself, which is not zero.
    $indicator = measure(['baseline_value' => '45']);

    expect(IndicatorAchievement::percentFor($indicator, '0', '0'))->toBe(100.0)
        ->and(IndicatorAchievement::percentFor($indicator, '9', '0'))->toBe(80.0);
});

it('reports no data rather than a number when there is no target', function () {
    $achievement = IndicatorAchievement::for(measure(), '40', null);

    expect($achievement->percent)->toBeNull()
        ->and($achievement->band)->toBe(IndicatorAchievement::NO_DATA)
        ->and($achievement->hasData())->toBeFalse()
        ->and($achievement->isOnTrack())->toBeFalse()
        ->and($achievement->percentLabel())->toBe('—');
});

it('reports no data rather than a number when nothing has been measured', function () {
    $achievement = IndicatorAchievement::for(measure(), null, '100');

    expect($achievement->percent)->toBeNull()
        ->and($achievement->band)->toBe(IndicatorAchievement::NO_DATA)
        // The target is still carried, because the screen shows it next to
        // the em dash — "we said 100 and nobody has measured" is the message.
        ->and($achievement->target)->toBe('100');
});

it('keeps the baseline on the value object even when there is nothing to compute', function () {
    $achievement = IndicatorAchievement::for(measure(['baseline_value' => '45']), null, null);

    expect($achievement->baseline)->toBe('45.0000')
        ->and($achievement->band)->toBe(IndicatorAchievement::NO_DATA);
});

/*
|--------------------------------------------------------------------------
| The traffic light — asserted against the configured thresholds
|--------------------------------------------------------------------------
| Never against 90 and 70 as literals: one state calls 85% on track where its
| neighbour calls 90%, and a test that hard-codes the number would pass on an
| instance where the code had stopped reading the setting at all.
*/

it('bands a percentage against the instance thresholds, at the boundary and either side of it', function () {
    $onTrack = onTrackThreshold();
    $atRisk = atRiskThreshold();

    expect(IndicatorAchievement::bandFor((float) $onTrack))->toBe(IndicatorAchievement::ON_TRACK)
        ->and(IndicatorAchievement::bandFor($onTrack + 10.0))->toBe(IndicatorAchievement::ON_TRACK)
        // Immediately below on-track is amber, not green.
        ->and(IndicatorAchievement::bandFor($onTrack - 0.01))->toBe(IndicatorAchievement::AT_RISK)
        ->and(IndicatorAchievement::bandFor((float) $atRisk))->toBe(IndicatorAchievement::AT_RISK)
        ->and(IndicatorAchievement::bandFor($atRisk - 0.01))->toBe(IndicatorAchievement::OFF_TRACK)
        ->and(IndicatorAchievement::bandFor(0.0))->toBe(IndicatorAchievement::OFF_TRACK)
        ->and(IndicatorAchievement::bandFor(-20.0))->toBe(IndicatorAchievement::OFF_TRACK);
});

it('follows the thresholds when a state retunes them', function () {
    // The same figure, two instances. 82% is off-track under a 90/70 state and
    // on-track under an 80/60 one, and that is a policy decision the code must
    // not have an opinion about.
    config(['platform.indicators.on_track_percent' => 90, 'platform.indicators.at_risk_percent' => 70]);
    expect(IndicatorAchievement::bandFor(82.0))->toBe(IndicatorAchievement::AT_RISK);

    config(['platform.indicators.on_track_percent' => 80, 'platform.indicators.at_risk_percent' => 60]);
    expect(IndicatorAchievement::bandFor(82.0))->toBe(IndicatorAchievement::ON_TRACK);
});

it('clamps a misconfigured at-risk threshold rather than silently promoting at-risk indicators', function () {
    // at_risk ABOVE on_track would make the amber band empty and read every
    // amber indicator as green. A settings screen is not a compiler.
    config(['platform.indicators.on_track_percent' => 90, 'platform.indicators.at_risk_percent' => 95]);

    expect(IndicatorAchievement::bandFor(92.0))->toBe(IndicatorAchievement::ON_TRACK)
        ->and(IndicatorAchievement::bandFor(85.0))->toBe(IndicatorAchievement::OFF_TRACK);
});

it('prefers an instance setting over the config default', function () {
    config(['platform.indicators.on_track_percent' => 90]);

    Setting::query()->create([
        'group' => 'indicators',
        'key' => 'on_track_percent',
        'value' => 75,
    ]);

    expect(IndicatorAchievement::bandFor(78.0))->toBe(IndicatorAchievement::ON_TRACK);
});

it('bands the value object the same way it bands a bare percentage', function () {
    $indicator = measure(['baseline_value' => '0']);

    $onTrack = onTrackThreshold();

    $achievement = IndicatorAchievement::for($indicator, (string) $onTrack, '100');

    expect($achievement->percent)->toBe((float) $onTrack)
        ->and($achievement->band)->toBe(IndicatorAchievement::bandFor((float) $onTrack))
        ->and($achievement->isOnTrack())->toBeTrue()
        ->and($achievement->hasData())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| How the number is presented
|--------------------------------------------------------------------------
| Status is icon + text, never colour alone (rules/ui-design-system.md) — a
| board pack printed in greyscale, a colour-blind permanent secretary.
*/

it('renders the percentage the way a reader expects to see it', function (float $percent, string $label) {
    $achievement = IndicatorAchievement::for(measure(['baseline_value' => '0']), (string) $percent, '100');

    expect($achievement->percentLabel())->toBe($label);
})->with([
    'a whole number drops its decimal' => [40.0, '40%'],
    'a fraction keeps one place' => [66.7, '66.7%'],
    'zero is zero, not a dash' => [0.0, '0%'],
    'a regression keeps its sign' => [-20.0, '-20%'],
    'a large figure is grouped' => [1000.0, '1,000%'],
]);

it('gives every band a distinct icon, tone and label so colour is never the only signal', function () {
    $bands = [
        IndicatorAchievement::for(measure(['baseline_value' => '0']), '100', '100'),
        IndicatorAchievement::for(measure(['baseline_value' => '0']), (string) atRiskThreshold(), '100'),
        IndicatorAchievement::for(measure(['baseline_value' => '0']), '0', '100'),
        IndicatorAchievement::unknown(),
    ];

    $icons = array_map(fn (IndicatorAchievement $a): string => $a->icon(), $bands);
    $labels = array_map(fn (IndicatorAchievement $a): string => $a->label(), $bands);
    $tones = array_map(fn (IndicatorAchievement $a): string => $a->tone(), $bands);
    $badges = array_map(fn (IndicatorAchievement $a): string => $a->badgeStatus(), $bands);

    expect(array_map(fn (IndicatorAchievement $a): string => $a->band, $bands))->toBe([
        IndicatorAchievement::ON_TRACK,
        IndicatorAchievement::AT_RISK,
        IndicatorAchievement::OFF_TRACK,
        IndicatorAchievement::NO_DATA,
    ])
        ->and($icons)->toHaveCount(4)->and(array_unique($icons))->toHaveCount(4)
        ->and(array_unique($labels))->toHaveCount(4)
        ->and(array_unique($tones))->toHaveCount(4)
        ->and(array_unique($badges))->toHaveCount(4);
});

it('names the bands in the order a summary row reads them', function () {
    expect(array_keys(IndicatorAchievement::bands()))->toBe([
        IndicatorAchievement::ON_TRACK,
        IndicatorAchievement::AT_RISK,
        IndicatorAchievement::OFF_TRACK,
        IndicatorAchievement::NO_DATA,
    ]);
});

/*
|--------------------------------------------------------------------------
| Which readings are allowed to move the number at all
|--------------------------------------------------------------------------
| `indicators.require_validation_for_dashboards` is the setting that makes the
| Data Quality Reviewer role mean something: with it on, a figure nobody has
| checked must not reach a dashboard, however recent it is.
*/

describe('countable readings', function () {
    beforeEach(function () {
        $this->works = Tenant::factory()->create(['slug' => 'works']);
        actingOnTenant($this->works);

        $this->indicator = Indicator::factory()->active()->create([
            'name' => 'Classrooms completed and handed over',
            'baseline_value' => '0.0000',
        ]);

        IndicatorTarget::factory()->forIndicator($this->indicator)->ofValue('100.0000')->create();
    });

    it('keeps an unvalidated figure off the dashboard while validation is required', function () {
        config(['platform.indicators.require_validation_for_dashboards' => true]);

        IndicatorReading::factory()->forIndicator($this->indicator)->ofValue('80.0000')->submitted()->create();

        $achievement = dashboardAchievement($this->indicator);

        // No data — emphatically not 80%. A figure that has not been checked
        // must not colour a traffic light, which is the whole reason the
        // reviewer role exists.
        expect($achievement->percent)->toBeNull()
            ->and($achievement->band)->toBe(IndicatorAchievement::NO_DATA);
    });

    it('counts the same figure once a reviewer has validated it', function () {
        config(['platform.indicators.require_validation_for_dashboards' => true]);

        IndicatorReading::factory()->forIndicator($this->indicator)->ofValue('80.0000')->validated()->create();

        expect(dashboardAchievement($this->indicator)->percent)->toBe(80.0);
    });

    it('counts a published figure too — publication is past validation, not around it', function () {
        config(['platform.indicators.require_validation_for_dashboards' => true]);

        IndicatorReading::factory()->forIndicator($this->indicator)->ofValue('95.0000')->published()->create();

        expect(dashboardAchievement($this->indicator)->percent)->toBe(95.0);
    });

    it('counts a submitted figure when a state chooses not to require validation', function () {
        config(['platform.indicators.require_validation_for_dashboards' => false]);

        IndicatorReading::factory()->forIndicator($this->indicator)->ofValue('80.0000')->submitted()->create();

        expect(dashboardAchievement($this->indicator)->percent)->toBe(80.0);
    });

    it('never counts a draft, whichever way the setting is turned', function (bool $requireValidation) {
        config(['platform.indicators.require_validation_for_dashboards' => $requireValidation]);

        // A draft is a working note, not a return.
        IndicatorReading::factory()->forIndicator($this->indicator)->ofValue('80.0000')->create();

        expect(dashboardAchievement($this->indicator)->percent)->toBeNull();
    })->with([
        'validation required' => [true],
        'validation not required' => [false],
    ]);

    it('quotes the newest countable figure, not the newest figure', function () {
        config(['platform.indicators.require_validation_for_dashboards' => true]);

        // Last quarter's figure cleared review; this quarter's has not. The
        // register must show the validated one rather than reporting "no
        // data" for an indicator that has a perfectly good number.
        IndicatorReading::factory()->forIndicator($this->indicator)
            ->forPeriod('2026-01-01', '2026-03-31')
            ->ofValue('60.0000')
            ->validated()
            ->create();

        IndicatorReading::factory()->forIndicator($this->indicator)
            ->forPeriod('2026-04-01', '2026-06-30')
            ->ofValue('90.0000')
            ->submitted()
            ->create();

        expect(dashboardAchievement($this->indicator)->percent)->toBe(60.0);
    });

    it('quotes the latest target when several periods carry one', function () {
        config(['platform.indicators.require_validation_for_dashboards' => true]);

        IndicatorTarget::factory()->forIndicator($this->indicator)
            ->ofValue('200.0000')
            ->create(['period_start' => '2027-01-01', 'period_end' => '2027-12-31']);

        IndicatorReading::factory()->forIndicator($this->indicator)
            ->forPeriod('2027-01-01', '2027-03-31')
            ->ofValue('50.0000')
            ->validated()
            ->create();

        // Against the 200 target, not the 100 one.
        expect(dashboardAchievement($this->indicator)->percent)->toBe(25.0);
    });
});
