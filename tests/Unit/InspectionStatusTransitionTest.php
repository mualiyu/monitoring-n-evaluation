<?php

/**
 * The inspection lifecycle table, in isolation (App\Enums\InspectionStatus).
 *
 * A pure unit test on the enum: no database, no container. The table is
 * consulted FIRST by TransitionInspectionStatus, before any permission is
 * considered, so what it permits is what is structurally possible for every
 * actor on the platform — and it is worth asserting without the rest of the
 * stack in the way.
 *
 * Every allowed transition, and the forbidden ones that matter.
 */

use App\Enums\InspectionStatus;

it('allows the diary-to-sign-off path and nothing else', function () {
    expect(InspectionStatus::Scheduled->allowedTransitions())
        ->toBe([InspectionStatus::InProgress, InspectionStatus::Cancelled])
        ->and(InspectionStatus::InProgress->allowedTransitions())
        ->toBe([InspectionStatus::Submitted, InspectionStatus::Cancelled])
        ->and(InspectionStatus::Submitted->allowedTransitions())
        ->toBe([InspectionStatus::Reviewed])
        ->and(InspectionStatus::Reviewed->allowedTransitions())->toBe([])
        ->and(InspectionStatus::Cancelled->allowedTransitions())->toBe([]);
});

it('permits every step of the happy path', function (InspectionStatus $from, InspectionStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'scheduled → under way' => [InspectionStatus::Scheduled, InspectionStatus::InProgress],
    'under way → filed' => [InspectionStatus::InProgress, InspectionStatus::Submitted],
    'filed → signed off' => [InspectionStatus::Submitted, InspectionStatus::Reviewed],
    'scheduled → cancelled' => [InspectionStatus::Scheduled, InspectionStatus::Cancelled],
    'under way → cancelled' => [InspectionStatus::InProgress, InspectionStatus::Cancelled],
]);

/*
| The forbidden moves, each one stating the rule it protects.
*/

it('refuses to file a report for a visit that was never opened', function () {
    // The conduct form is where GPS, checklist and photographs are captured.
    // A report filed without ever opening it is a desk exercise wearing a site
    // visit's name.
    expect(InspectionStatus::Scheduled->canTransitionTo(InspectionStatus::Submitted))->toBeFalse();
});

it('refuses to cancel a filed report', function () {
    // A filed report is already a government record. It is corrected by
    // another record, never withdrawn.
    expect(InspectionStatus::Submitted->canTransitionTo(InspectionStatus::Cancelled))->toBeFalse()
        ->and(InspectionStatus::Reviewed->canTransitionTo(InspectionStatus::Cancelled))->toBeFalse();
});

it('refuses to reopen a signed-off inspection', function () {
    foreach (InspectionStatus::cases() as $target) {
        expect(InspectionStatus::Reviewed->canTransitionTo($target))->toBeFalse();
    }
});

it('refuses to resurrect a cancelled visit', function () {
    // A cancelled visit is not rescheduled in place — that would erase the
    // record that it was cancelled, and the reason with it. A new visit is a
    // new row.
    foreach (InspectionStatus::cases() as $target) {
        expect(InspectionStatus::Cancelled->canTransitionTo($target))->toBeFalse();
    }
});

it('has no rung that sends a report back to its inspector', function () {
    // Deliberate: an inspection is the state's observation of a site on a
    // date. A reviewer who disagrees records that against the original and
    // orders another visit; a "returned" rung would make an observation
    // negotiable after the fact.
    foreach (InspectionStatus::cases() as $from) {
        expect($from->canTransitionTo(InspectionStatus::Scheduled))->toBeFalse();
    }
});

/*
| The derived predicates the screens and the sweeps read.
*/

it('treats only a visit under way as editable', function () {
    expect(InspectionStatus::InProgress->isEditable())->toBeTrue()
        ->and(InspectionStatus::Scheduled->isEditable())->toBeFalse()
        ->and(InspectionStatus::Submitted->isEditable())->toBeFalse()
        ->and(InspectionStatus::Reviewed->isEditable())->toBeFalse()
        ->and(InspectionStatus::Cancelled->isEditable())->toBeFalse();
});

it('treats scheduled and under-way visits as open, and nothing else', function () {
    expect(InspectionStatus::Scheduled->isOpen())->toBeTrue()
        ->and(InspectionStatus::InProgress->isOpen())->toBeTrue()
        ->and(InspectionStatus::Submitted->isOpen())->toBeFalse()
        ->and(InspectionStatus::Reviewed->isOpen())->toBeFalse()
        ->and(InspectionStatus::Cancelled->isOpen())->toBeFalse();
});

it('treats a filed report as filed whether or not it has been signed off', function () {
    expect(InspectionStatus::Submitted->isFiled())->toBeTrue()
        ->and(InspectionStatus::Reviewed->isFiled())->toBeTrue()
        ->and(InspectionStatus::InProgress->isFiled())->toBeFalse()
        ->and(InspectionStatus::Cancelled->isFiled())->toBeFalse();
});

it('marks the two end states as terminal', function () {
    expect(InspectionStatus::Reviewed->isTerminal())->toBeTrue()
        ->and(InspectionStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(InspectionStatus::Scheduled->isTerminal())->toBeFalse()
        ->and(InspectionStatus::InProgress->isTerminal())->toBeFalse();
});

it('gives every status a label and a badge the shared component knows', function () {
    // Status is conveyed by icon + text, never colour alone — which only works
    // if every case maps onto a key <x-ui.badge> actually has.
    $known = ['pending', 'in_progress', 'submitted', 'approved', 'cancelled'];

    foreach (InspectionStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($known)->toContain($status->badge());
    }
});
