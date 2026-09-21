<?php

/**
 * The roll-up (App\Support\WorkplanProgress) — the single derivation every
 * screen, export and notification reads.
 *
 * DB-free on purpose: summarise() takes an iterable of activities, so the
 * weighting rule can be exercised on unsaved models with no fixture noise
 * between the arithmetic and the assertion.
 *
 * The documented decision under test: progress is weighted by the explicit
 * `weight` column, NOT by budget — a zero-cost activity (a validation
 * meeting, an indicator review retreat) still counts, and the manual's own
 * Annual Work Plan is full of them.
 */

use App\Enums\ActivityStatus;
use App\Models\WorkplanActivity;
use App\Support\Money;
use App\Support\WorkplanProgress;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $attributes
 */
function activityRow(array $attributes = []): WorkplanActivity
{
    return (new WorkplanActivity)->forceFill([
        'title' => 'Activity',
        'indicator_id' => 1,
        'weight' => 1,
        'progress_percent' => 0,
        'status' => ActivityStatus::NotStarted,
        'budget_amount' => '0.00',
        'expenditure_to_date' => '0.00',
        'planned_start' => CarbonImmutable::create(2026, 1, 1),
        'planned_end' => CarbonImmutable::create(2026, 3, 31),
        ...$attributes,
    ]);
}

it('reports no progress figure at all for a plan with no activities', function () {
    $summary = WorkplanProgress::summarise([]);

    // NULL, not 0.0 — an empty plan is not a plan at 0%, and <x-ui.progress>
    // renders null as "—" rather than an empty red bar.
    expect($summary['progress'])->toBeNull()
        ->and($summary['activities'])->toBe(0)
        ->and($summary['budget']->isZero())->toBeTrue();
});

it('averages equal-weight activities', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['progress_percent' => 100, 'status' => ActivityStatus::Completed]),
        activityRow(['progress_percent' => 50, 'status' => ActivityStatus::InProgress]),
        activityRow(['progress_percent' => 0]),
    ]);

    expect($summary['progress'])->toBe(50.0)
        ->and($summary['counted'])->toBe(3)
        ->and($summary['completed'])->toBe(1)
        ->and($summary['in_progress'])->toBe(1)
        ->and($summary['not_started'])->toBe(1);
});

it('weights by the weight column, not by budget', function () {
    // The decisive case: the CHEAP activity is the one that is finished, and
    // the expensive one has not started. Budget weighting would report ~4%;
    // weight weighting reports 50%, which is what "one of two equally
    // important activities is done" means.
    $summary = WorkplanProgress::summarise([
        activityRow(['progress_percent' => 100, 'status' => ActivityStatus::Completed, 'budget_amount' => '1000.00']),
        activityRow(['progress_percent' => 0, 'budget_amount' => '24000.00']),
    ]);

    expect($summary['progress'])->toBe(50.0);
});

it('lets an explicit weight carry more of the year', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['progress_percent' => 100, 'status' => ActivityStatus::Completed, 'weight' => 3]),
        activityRow(['progress_percent' => 0, 'weight' => 1]),
    ]);

    // (3 × 100 + 1 × 0) / 4
    expect($summary['progress'])->toBe(75.0);
});

it('counts a weightless row as one activity rather than losing it', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['progress_percent' => 100, 'status' => ActivityStatus::Completed, 'weight' => 0]),
        activityRow(['progress_percent' => 0]),
    ]);

    expect($summary['progress'])->toBe(50.0)
        ->and($summary['weight'])->toBe(2);
});

it('drops cancelled activities from the denominator but still counts them', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['progress_percent' => 100, 'status' => ActivityStatus::Completed]),
        activityRow(['progress_percent' => 0, 'status' => ActivityStatus::Cancelled, 'budget_amount' => '9000.00']),
    ]);

    // 100%, not 50%: work nobody is doing any more must not drag a
    // well-run programme down. Its budget leaves the total with it.
    expect($summary['progress'])->toBe(100.0)
        ->and($summary['counted'])->toBe(1)
        ->and($summary['activities'])->toBe(2)
        ->and($summary['cancelled'])->toBe(1)
        ->and($summary['budget']->toDecimalString())->toBe('0.00');
});

it('clamps a progress figure that somehow escaped its range', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['progress_percent' => 150]),
        activityRow(['progress_percent' => -20]),
    ]);

    expect($summary['progress'])->toBe(50.0);
});

it('sums budget and expenditure as integers and reports financial progress', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['budget_amount' => '1500000.00', 'expenditure_to_date' => '750000.00']),
        activityRow(['budget_amount' => '500000.00', 'expenditure_to_date' => '250000.00']),
    ]);

    expect($summary['budget'])->toBeInstanceOf(Money::class)
        ->and($summary['budget']->toDecimalString())->toBe('2000000.00')
        ->and($summary['expenditure']->toDecimalString())->toBe('1000000.00')
        ->and($summary['financial_progress'])->toBe(50.0);
});

it('has no financial progress when nothing has been budgeted', function () {
    $summary = WorkplanProgress::summarise([activityRow()]);

    // Null rather than 0% or a division by zero: "not applicable" is a
    // different answer from "none spent".
    expect($summary['financial_progress'])->toBeNull();
});

it('counts the activities that break the manual rule', function () {
    $summary = WorkplanProgress::summarise([
        activityRow(['indicator_id' => 7]),
        activityRow(['indicator_id' => null]),
        activityRow(['indicator_id' => null, 'status' => ActivityStatus::Cancelled]),
    ]);

    // Cancelled lines still count towards the warning: a dropped activity
    // with no indicator was still planned without one.
    expect($summary['unlinked'])->toBe(2);
});

it('derives an activity status from its progress and its planned date', function (
    int $percent,
    string $plannedEnd,
    ActivityStatus $expected,
) {
    $status = ActivityStatus::derive(
        $percent,
        CarbonImmutable::parse($plannedEnd),
        CarbonImmutable::create(2026, 6, 15, 9),
    );

    expect($status)->toBe($expected);
})->with([
    'untouched and still in time' => [0, '2026-08-31', ActivityStatus::NotStarted],
    'under way and in time' => [40, '2026-08-31', ActivityStatus::InProgress],
    'finished' => [100, '2026-08-31', ActivityStatus::Completed],
    // Delivered late is still delivered — a permanently red bar is a bar
    // people learn to ignore.
    'finished after its date is complete, not delayed' => [100, '2026-05-31', ActivityStatus::Completed],
    'past its date and unfinished' => [40, '2026-05-31', ActivityStatus::Delayed],
    'past its date and never started is the worst case, not an exempt one' => [0, '2026-05-31', ActivityStatus::Delayed],
    'due today is not yet late' => [10, '2026-06-15', ActivityStatus::InProgress],
]);
