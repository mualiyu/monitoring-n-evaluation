<?php

/**
 * The deviation arithmetic — the part of the threshold engine that must not be
 * wrong.
 *
 *   schedule slippage    = elapsed schedule %  −  physical progress %
 *   expenditure variance = financial progress % −  physical progress %
 *
 * Pure statics: no database, no tenant, no container. Everything here is a
 * decision about what a number MEANS, and each one has a failure mode that
 * would be invisible in a feature test:
 *
 *  - a missing schedule read as 0% elapsed raises a slippage exception against
 *    every project that has reported any progress at all;
 *  - an unclamped elapsed figure lets a project two years late swamp every
 *    genuine 20-point slip on the board;
 *  - an unpriced project read as 0% spent reports as wildly underspent.
 */

use App\Actions\Issues\MeasureProjectDeviation;
use Carbon\CarbonImmutable;

/* -------------------------------------------------------------------------- */
/* Elapsed schedule */
/* -------------------------------------------------------------------------- */

it('reads the elapsed share of the contract period', function (string $asOf, float $expected) {
    $elapsed = MeasureProjectDeviation::scheduleElapsed(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-11'),
        CarbonImmutable::parse($asOf),
    );

    expect($elapsed)->toBe($expected);
})->with([
    'the day it starts' => ['2026-01-01', 0.0],
    'a fifth of the way' => ['2026-01-03', 20.0],
    'halfway' => ['2026-01-06', 50.0],
    'the day it is due' => ['2026-01-11', 100.0],
]);

it('clamps an overrunning project at 100%, not at 140%', function () {
    // "The time is gone" is the strongest statement the elapsed figure can
    // make. Without the clamp the slippage of a project two years late grows
    // without bound and the severity of every genuine 20-point slip is lost
    // among them.
    $elapsed = MeasureProjectDeviation::scheduleElapsed(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-11'),
        CarbonImmutable::parse('2027-06-01'),
    );

    expect($elapsed)->toBe(100.0);
});

it('clamps a project measured before it started at 0%', function () {
    $elapsed = MeasureProjectDeviation::scheduleElapsed(
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-12-01'),
        CarbonImmutable::parse('2026-01-01'),
    );

    expect($elapsed)->toBe(0.0);
});

it('returns null rather than zero when there is no schedule to measure', function (?string $start, ?string $end) {
    // Null is "not measurable", never "perfectly on schedule". Reading a
    // missing schedule as 0% elapsed would raise a slippage exception against
    // every project that has reported any progress at all.
    $elapsed = MeasureProjectDeviation::scheduleElapsed(
        $start === null ? null : CarbonImmutable::parse($start),
        $end === null ? null : CarbonImmutable::parse($end),
        CarbonImmutable::parse('2026-06-01'),
    );

    expect($elapsed)->toBeNull();
})->with([
    'no start date' => [null, '2026-12-01'],
    'no end date' => ['2026-01-01', null],
    'neither' => [null, null],
    'an end date on the start date' => ['2026-01-01', '2026-01-01'],
    'an end date before the start date' => ['2026-06-01', '2026-01-01'],
]);

/* -------------------------------------------------------------------------- */
/* Schedule slippage */
/* -------------------------------------------------------------------------- */

it('measures slippage as elapsed time minus work done, in points', function () {
    // 70% of the time gone, 38% of the work done: 32 points behind.
    expect(MeasureProjectDeviation::slippagePoints(70.0, 38.0))->toBe(32.0);
});

it('reports a project ahead of schedule as negative slippage, never as a trip', function () {
    // Positive means BAD, deliberately — it keeps the comparison against a
    // tolerance a single >= and stops an early project tripping the alert.
    expect(MeasureProjectDeviation::slippagePoints(40.0, 65.0))->toBe(-25.0);
});

it('reports no slippage for a project exactly on programme', function () {
    expect(MeasureProjectDeviation::slippagePoints(50.0, 50.0))->toBe(0.0);
});

it('cannot measure slippage without a schedule', function () {
    expect(MeasureProjectDeviation::slippagePoints(null, 38.0))->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* Expenditure variance */
/* -------------------------------------------------------------------------- */

it('measures expenditure variance as money spent minus work done, in points', function () {
    // 62% of the contract value drawn against 35% of the work: 27 points of
    // money ahead of delivery — the classic advance-payment signature.
    expect(MeasureProjectDeviation::variancePoints(62.0, 35.0))->toBe(27.0);
});

it('reports an underspending project as negative variance, never as a trip', function () {
    expect(MeasureProjectDeviation::variancePoints(20.0, 55.0))->toBe(-35.0);
});

it('cannot measure variance on a project with no contract value', function () {
    // An unpriced project is not a project at 0% spend, and reporting it as
    // one would make every unpriced project look wildly underspent.
    expect(MeasureProjectDeviation::variancePoints(null, 35.0))->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* The two are independent */
/* -------------------------------------------------------------------------- */

it('keeps the two deviations independent, because they ask different questions', function () {
    // Dead on schedule, 40 points ahead on spend. A single "is this project in
    // trouble" number would hide exactly this case, which is the one an
    // auditor cares about most.
    $physical = 50.0;

    expect(MeasureProjectDeviation::slippagePoints(50.0, $physical))->toBe(0.0)
        ->and(MeasureProjectDeviation::variancePoints(90.0, $physical))->toBe(40.0);
});

it('rounds to two places, so a stored decimal(8,2) never disagrees with the comparison', function () {
    $elapsed = MeasureProjectDeviation::scheduleElapsed(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-04'),
        CarbonImmutable::parse('2026-01-02'),
    );

    // 1 day of 3 = 33.333…%, stored and compared as 33.33.
    expect($elapsed)->toBe(33.33)
        ->and(MeasureProjectDeviation::slippagePoints($elapsed, 10.0))->toBe(23.33);
});
