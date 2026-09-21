<?php

/**
 * The chain table itself — the cheapest guard in the module and the first one
 * TransitionWorkplanStatus consults, because an impossible move is impossible
 * for everyone, whatever permissions they may hold.
 */

use App\Enums\WorkplanStatus;

it('allows exactly the chain the design specifies', function (WorkplanStatus $from, array $expected) {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'a draft is submitted' => [WorkplanStatus::Draft, [WorkplanStatus::Submitted]],
    'a submitted plan is approved or sent back' => [
        WorkplanStatus::Submitted,
        [WorkplanStatus::Approved, WorkplanStatus::Rejected],
    ],
    'an approved plan goes live, or is closed off unstarted' => [
        WorkplanStatus::Approved,
        [WorkplanStatus::Active, WorkplanStatus::Closed],
    ],
    'an active plan is closed' => [WorkplanStatus::Active, [WorkplanStatus::Closed]],
    'a rejected plan is revised and resubmitted' => [WorkplanStatus::Rejected, [WorkplanStatus::Submitted]],
    'a closed year is history' => [WorkplanStatus::Closed, []],
]);

it('forbids the moves that would defeat the chain', function (WorkplanStatus $from, WorkplanStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    // Skipping submission would skip the submitter — and with them the
    // separation-of-duties guard that refuses an approver who submitted.
    'draft cannot be approved directly' => [WorkplanStatus::Draft, WorkplanStatus::Approved],
    'draft cannot go straight live' => [WorkplanStatus::Draft, WorkplanStatus::Active],
    // A running year's committed programme is not rewritten in place.
    'an active plan cannot return to draft' => [WorkplanStatus::Active, WorkplanStatus::Draft],
    'an approved plan cannot be rejected after the fact' => [WorkplanStatus::Approved, WorkplanStatus::Rejected],
    'a closed year cannot be reopened' => [WorkplanStatus::Closed, WorkplanStatus::Active],
    'a closed year cannot be redrafted' => [WorkplanStatus::Closed, WorkplanStatus::Draft],
    'a submitted plan cannot go live without approval' => [WorkplanStatus::Submitted, WorkplanStatus::Active],
]);

it('treats only a closed year as terminal', function () {
    foreach (WorkplanStatus::cases() as $status) {
        expect($status->isTerminal())->toBe($status === WorkplanStatus::Closed);
    }
});

it('allows activity definitions to be edited only before approval', function (WorkplanStatus $status, bool $editable) {
    expect($status->allowsDefinitionEdits())->toBe($editable);
})->with([
    'draft' => [WorkplanStatus::Draft, true],
    'rejected — it is back with its author' => [WorkplanStatus::Rejected, true],
    'submitted — it is out of the author\'s hands' => [WorkplanStatus::Submitted, false],
    'approved — an officer signed for these figures' => [WorkplanStatus::Approved, false],
    'active' => [WorkplanStatus::Active, false],
    'closed' => [WorkplanStatus::Closed, false],
]);

it('accepts progress only against a plan that is being delivered', function (WorkplanStatus $status, bool $accepts) {
    expect($status->acceptsProgress())->toBe($accepts);
})->with([
    'draft' => [WorkplanStatus::Draft, false],
    'submitted' => [WorkplanStatus::Submitted, false],
    'rejected' => [WorkplanStatus::Rejected, false],
    'approved' => [WorkplanStatus::Approved, true],
    'active' => [WorkplanStatus::Active, true],
    // A closed year takes no further figures — that is what closing it means.
    'closed' => [WorkplanStatus::Closed, false],
]);

it('gives every status a label and a badge key', function () {
    foreach (WorkplanStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($status->badge())->not->toBe('');
    }
});
