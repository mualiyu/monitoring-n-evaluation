<?php

/**
 * The chain table itself (progress-reporting.md §2) — the cheapest guard in
 * the module and the first one TransitionProgressReportStatus consults, because
 * an impossible move is impossible for everyone, whatever they may do.
 */

use App\Enums\ProgressReportStatus;

it('allows exactly the chain the design specifies', function (ProgressReportStatus $from, array $expected) {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'a draft is filed' => [ProgressReportStatus::Draft, [ProgressReportStatus::Submitted]],
    'a filed return is reviewed or sent back' => [
        ProgressReportStatus::Submitted,
        [ProgressReportStatus::Reviewed, ProgressReportStatus::Returned],
    ],
    'a reviewed return is approved or sent back' => [
        ProgressReportStatus::Reviewed,
        [ProgressReportStatus::Approved, ProgressReportStatus::Returned],
    ],
    'a returned return is filed again' => [ProgressReportStatus::Returned, [ProgressReportStatus::Submitted]],
    'an approved return is final' => [ProgressReportStatus::Approved, []],
]);

it('refuses the shortcut that skips review', function () {
    expect(ProgressReportStatus::Submitted->canTransitionTo(ProgressReportStatus::Approved))->toBeFalse();
});

it('makes approval terminal, because the figures have already moved the project', function () {
    expect(ProgressReportStatus::Approved->isTerminal())->toBeTrue();

    foreach (ProgressReportStatus::cases() as $target) {
        expect(ProgressReportStatus::Approved->canTransitionTo($target))->toBeFalse();
    }
});

it('never reopens a filed return as a draft', function (ProgressReportStatus $from) {
    expect($from->canTransitionTo(ProgressReportStatus::Draft))->toBeFalse();
})->with(ProgressReportStatus::cases());

it('lets the author edit only before filing and after a return', function () {
    expect(ProgressReportStatus::Draft->isEditable())->toBeTrue()
        ->and(ProgressReportStatus::Returned->isEditable())->toBeTrue()
        // Evidence that can change after review is not evidence (§5).
        ->and(ProgressReportStatus::Submitted->isEditable())->toBeFalse()
        ->and(ProgressReportStatus::Reviewed->isEditable())->toBeFalse()
        ->and(ProgressReportStatus::Approved->isEditable())->toBeFalse();
});

it('counts everything past draft as filed, which is what compliance measures', function () {
    expect(ProgressReportStatus::Draft->isFiled())->toBeFalse();

    foreach ([
        ProgressReportStatus::Submitted,
        ProgressReportStatus::Reviewed,
        ProgressReportStatus::Approved,
        ProgressReportStatus::Returned,
    ] as $status) {
        expect($status->isFiled())->toBeTrue();
    }
});

it('does not carry a consolidated state — that arrives with Phase 2', function () {
    expect(array_column(ProgressReportStatus::cases(), 'value'))
        ->toBe(['draft', 'submitted', 'reviewed', 'approved', 'returned']);
});
