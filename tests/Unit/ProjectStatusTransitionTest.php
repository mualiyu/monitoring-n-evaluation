<?php

/**
 * The project lifecycle contract (projects-module.md §2). The enum is the
 * single source of truth consulted by TransitionProjectStatus, so it is tested
 * on its own: every allowed edge, the whole forbidden complement, and the
 * regression guard for `mid_term`.
 *
 * No database and no container — this is a pure state machine.
 */

use App\Enums\ProjectStatus;

/** The §2 table, transcribed edge by edge. */
function allowedProjectStatusEdges(): array
{
    return [
        [ProjectStatus::Draft, ProjectStatus::Awarded],
        [ProjectStatus::Draft, ProjectStatus::Cancelled],
        [ProjectStatus::Awarded, ProjectStatus::Mobilized],
        [ProjectStatus::Awarded, ProjectStatus::Suspended],
        [ProjectStatus::Awarded, ProjectStatus::Cancelled],
        [ProjectStatus::Mobilized, ProjectStatus::InProgress],
        [ProjectStatus::Mobilized, ProjectStatus::Suspended],
        [ProjectStatus::Mobilized, ProjectStatus::Cancelled],
        [ProjectStatus::InProgress, ProjectStatus::Completed],
        [ProjectStatus::InProgress, ProjectStatus::Suspended],
        [ProjectStatus::InProgress, ProjectStatus::Cancelled],
        [ProjectStatus::Completed, ProjectStatus::Certified],
        [ProjectStatus::Completed, ProjectStatus::InProgress],
        [ProjectStatus::Certified, ProjectStatus::Closed],
        [ProjectStatus::Suspended, ProjectStatus::InProgress],
        [ProjectStatus::Suspended, ProjectStatus::Cancelled],
    ];
}

it('allows every transition the lifecycle table describes', function (ProjectStatus $from, ProjectStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with(allowedProjectStatusEdges());

it('offers exactly the successors the lifecycle table describes', function (ProjectStatus $from, array $expected) {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'draft' => [ProjectStatus::Draft, [ProjectStatus::Awarded, ProjectStatus::Cancelled]],
    'awarded' => [ProjectStatus::Awarded, [ProjectStatus::Mobilized, ProjectStatus::Suspended, ProjectStatus::Cancelled]],
    'mobilized' => [ProjectStatus::Mobilized, [ProjectStatus::InProgress, ProjectStatus::Suspended, ProjectStatus::Cancelled]],
    'in progress' => [ProjectStatus::InProgress, [ProjectStatus::Completed, ProjectStatus::Suspended, ProjectStatus::Cancelled]],
    'completed' => [ProjectStatus::Completed, [ProjectStatus::Certified, ProjectStatus::InProgress]],
    'certified' => [ProjectStatus::Certified, [ProjectStatus::Closed]],
    'suspended' => [ProjectStatus::Suspended, [ProjectStatus::InProgress, ProjectStatus::Cancelled]],
    'closed' => [ProjectStatus::Closed, []],
    'cancelled' => [ProjectStatus::Cancelled, []],
]);

it('forbids every transition the lifecycle table does not describe', function () {
    $allowed = array_map(
        fn (array $edge) => $edge[0]->value.'→'.$edge[1]->value,
        allowedProjectStatusEdges(),
    );

    $wronglyAllowed = [];

    foreach (ProjectStatus::cases() as $from) {
        foreach (ProjectStatus::cases() as $to) {
            $edge = $from->value.'→'.$to->value;

            if (! in_array($edge, $allowed, true) && $from->canTransitionTo($to)) {
                $wronglyAllowed[] = $edge;
            }
        }
    }

    expect($wronglyAllowed)->toBeEmpty();
});

it('refuses to walk a finished project backwards', function (ProjectStatus $from, ProjectStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'certified back to in progress' => [ProjectStatus::Certified, ProjectStatus::InProgress],
    'certified back to completed' => [ProjectStatus::Certified, ProjectStatus::Completed],
    'closed back to in progress' => [ProjectStatus::Closed, ProjectStatus::InProgress],
    'closed back to certified' => [ProjectStatus::Closed, ProjectStatus::Certified],
    'closed to cancelled' => [ProjectStatus::Closed, ProjectStatus::Cancelled],
    'cancelled back to draft' => [ProjectStatus::Cancelled, ProjectStatus::Draft],
    'cancelled back to in progress' => [ProjectStatus::Cancelled, ProjectStatus::InProgress],
    'cancelled to closed' => [ProjectStatus::Cancelled, ProjectStatus::Closed],
]);

it('refuses to skip the steps between drafting and delivery', function (ProjectStatus $from, ProjectStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'draft straight to completed' => [ProjectStatus::Draft, ProjectStatus::Completed],
    'draft straight to in progress' => [ProjectStatus::Draft, ProjectStatus::InProgress],
    'draft straight to mobilized' => [ProjectStatus::Draft, ProjectStatus::Mobilized],
    'draft straight to certified' => [ProjectStatus::Draft, ProjectStatus::Certified],
    'awarded straight to in progress' => [ProjectStatus::Awarded, ProjectStatus::InProgress],
    'awarded straight to completed' => [ProjectStatus::Awarded, ProjectStatus::Completed],
    'mobilized straight to completed' => [ProjectStatus::Mobilized, ProjectStatus::Completed],
    'in progress straight to certified' => [ProjectStatus::InProgress, ProjectStatus::Certified],
    'completed straight to closed' => [ProjectStatus::Completed, ProjectStatus::Closed],
    'suspended straight to completed' => [ProjectStatus::Suspended, ProjectStatus::Completed],
]);

it('never treats a status as a transition to itself', function (ProjectStatus $status) {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(ProjectStatus::cases());

it('has no mid_term status, because mid-term evaluation is an event and not a state', function () {
    expect(ProjectStatus::tryFrom('mid_term'))->toBeNull()
        ->and(array_column(ProjectStatus::cases(), 'value'))->not->toContain('mid_term');
});

it('carries exactly the nine lifecycle states the design froze', function () {
    expect(array_column(ProjectStatus::cases(), 'value'))->toBe([
        'draft', 'awarded', 'mobilized', 'in_progress',
        'completed', 'certified', 'closed', 'suspended', 'cancelled',
    ]);
});

it('treats only closed and cancelled as terminal', function () {
    $terminal = array_values(array_filter(
        ProjectStatus::cases(),
        fn (ProjectStatus $status) => $status->isTerminal(),
    ));

    expect($terminal)->toBe([ProjectStatus::Closed, ProjectStatus::Cancelled]);
});

it('freezes scope and financial fields from certification onward', function () {
    $frozen = array_values(array_filter(
        ProjectStatus::cases(),
        fn (ProjectStatus $status) => $status->isFrozen(),
    ));

    expect($frozen)->toBe([ProjectStatus::Certified, ProjectStatus::Closed]);
});

it('counts every state except draft and cancelled as having a contract behind it', function () {
    $notAwarded = array_values(array_filter(
        ProjectStatus::cases(),
        fn (ProjectStatus $status) => ! $status->isAwardedOrBeyond(),
    ));

    expect($notAwarded)->toBe([ProjectStatus::Draft, ProjectStatus::Cancelled]);
});
