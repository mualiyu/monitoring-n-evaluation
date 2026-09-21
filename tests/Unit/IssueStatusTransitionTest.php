<?php

/**
 * The lifecycle table itself — the cheapest guard in the module and the first
 * one TransitionIssueStatus consults, because an impossible move is impossible
 * for everyone, whatever they may do.
 *
 * Pure enum logic: no database, no tenant, no container. The table is the part
 * that must not be wrong, and a test that needs three fixtures to assert it
 * does not get written often enough.
 */

use App\Enums\ExceptionStatus;
use App\Enums\IssueStatus;

it('allows exactly the lifecycle the design specifies', function (IssueStatus $from, array $expected) {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'an open issue is accepted, picked up, escalated or closed' => [
        IssueStatus::Open,
        [IssueStatus::Acknowledged, IssueStatus::InProgress, IssueStatus::Escalated, IssueStatus::Closed],
    ],
    'an acknowledged issue moves on, escalates, resolves or closes' => [
        IssueStatus::Acknowledged,
        [IssueStatus::InProgress, IssueStatus::Escalated, IssueStatus::Resolved, IssueStatus::Closed],
    ],
    'work in progress escalates, resolves or closes' => [
        IssueStatus::InProgress,
        [IssueStatus::Escalated, IssueStatus::Resolved, IssueStatus::Closed],
    ],
    'an escalated issue rejoins the normal chain' => [
        IssueStatus::Escalated,
        [IssueStatus::Acknowledged, IssueStatus::InProgress, IssueStatus::Resolved, IssueStatus::Closed],
    ],
    'a resolved issue closes, or reopens when the fix did not hold' => [
        IssueStatus::Resolved,
        [IssueStatus::InProgress, IssueStatus::Closed],
    ],
    'a closed issue is final' => [IssueStatus::Closed, []],
]);

it('makes closing absorbing — the register has stopped chasing the item', function () {
    expect(IssueStatus::Closed->isTerminal())->toBeTrue();

    foreach (IssueStatus::cases() as $target) {
        expect(IssueStatus::Closed->canTransitionTo($target))->toBeFalse();
    }
});

it('never reopens an issue as freshly raised', function (IssueStatus $from) {
    expect($from->canTransitionTo(IssueStatus::Open))->toBeFalse();
})->with(fn () => array_filter(
    IssueStatus::cases(),
    fn (IssueStatus $status): bool => $status !== IssueStatus::Open,
));

it('lets a resolution that did not hold be reopened rather than re-raised', function () {
    // The alternative — a second issue for the same obstruction — loses the
    // history that makes "this has been 'fixed' three times" visible at all.
    expect(IssueStatus::Resolved->canTransitionTo(IssueStatus::InProgress))->toBeTrue()
        ->and(IssueStatus::Resolved->canTransitionTo(IssueStatus::Acknowledged))->toBeFalse();
});

it('refuses to escalate an issue that is already finished', function (IssueStatus $from) {
    expect($from->canTransitionTo(IssueStatus::Escalated))->toBeFalse();
})->with([
    'resolved' => [IssueStatus::Resolved],
    'closed' => [IssueStatus::Closed],
]);

it('counts exactly the live states as open', function () {
    $open = array_values(array_filter(
        IssueStatus::cases(),
        fn (IssueStatus $status): bool => $status->isOpen(),
    ));

    expect($open)->toBe([
        IssueStatus::Open,
        IssueStatus::Acknowledged,
        IssueStatus::InProgress,
        IssueStatus::Escalated,
    ]);
});

it('offers the escalation ladder only the states a person could still have acted on', function () {
    $escalatable = array_values(array_filter(
        IssueStatus::cases(),
        fn (IssueStatus $status): bool => $status->isEscalatable(),
    ));

    // Escalated is absent: a director told once is not told nightly.
    expect($escalatable)->toBe([
        IssueStatus::Open,
        IssueStatus::Acknowledged,
        IssueStatus::InProgress,
    ]);
});

it('freezes editing only once an issue is closed', function () {
    expect(IssueStatus::Closed->isEditable())->toBeFalse();

    foreach (IssueStatus::cases() as $status) {
        if ($status !== IssueStatus::Closed) {
            expect($status->isEditable())->toBeTrue();
        }
    }
});

it('gives every status a distinct badge tone and icon so none renders blank', function () {
    foreach (IssueStatus::cases() as $status) {
        expect($status->badgeStatus())->not->toBe('')
            ->and($status->icon())->not->toBe('');
    }
});

/*
|--------------------------------------------------------------------------
| The exception-report chain
|--------------------------------------------------------------------------
| Shorter on purpose: an exception report is a NOTICE, not a piece of work.
| The work it provokes is an Issue, which is why there is no `in_progress`.
*/

it('allows exactly the exception chain the design specifies', function () {
    expect(ExceptionStatus::Open->allowedTransitions())
        ->toBe([ExceptionStatus::Acknowledged, ExceptionStatus::Resolved])
        ->and(ExceptionStatus::Acknowledged->allowedTransitions())
        ->toBe([ExceptionStatus::Resolved])
        ->and(ExceptionStatus::Resolved->allowedTransitions())->toBe([]);
});

it('never reopens a resolved exception report', function () {
    foreach (ExceptionStatus::cases() as $target) {
        expect(ExceptionStatus::Resolved->canTransitionTo($target))->toBeFalse();
    }
});

it('treats an acknowledged deviation as still live, so the engine does not duplicate it', function () {
    expect(ExceptionStatus::Open->isLive())->toBeTrue()
        ->and(ExceptionStatus::Acknowledged->isLive())->toBeTrue()
        ->and(ExceptionStatus::Resolved->isLive())->toBeFalse();
});
