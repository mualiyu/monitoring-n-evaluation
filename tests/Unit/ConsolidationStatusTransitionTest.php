<?php

/**
 * The consolidation chain table, on its own.
 *
 * App\Actions\Consolidation\TransitionConsolidationStatus consults this enum
 * FIRST — before any permission, before any domain precondition — because an
 * impossible move has to be impossible for everyone. That makes the table
 * itself worth a test of its own: if it is wrong, every guard downstream is
 * guarding the wrong doorway.
 *
 * No database here (see tests/Pest.php): the transition table is a pure
 * function of the enum.
 */

use App\Enums\ConsolidationStatus;

/* -------------------------------------------------------------------------- */
/* The whole matrix, stated once */
/* -------------------------------------------------------------------------- */

/**
 * Every (from, to) pair in the enum, and whether the chain permits it. Written
 * out in full rather than derived from allowedTransitions(), because a table
 * that derives its expectations from the thing it is testing proves only that
 * the code equals itself.
 *
 * @return array<string, bool>
 */
function consolidationMatrix(): array
{
    return [
        // from draft: figures have to be pulled before anything else happens.
        'draft→draft' => false,
        'draft→compiling' => true,
        'draft→in_review' => false,   // a roll-up with nothing rolled up is a title page
        'draft→approved' => false,
        'draft→published' => false,

        // from compiling: up the chain, or back to the top of the window.
        'compiling→draft' => true,
        'compiling→compiling' => false,
        'compiling→in_review' => true,
        'compiling→approved' => false, // review is not optional
        'compiling→published' => false,

        // from in_review: sign it, or send it back to where the narrative lives.
        'in_review→draft' => false,
        'in_review→compiling' => true,
        'in_review→in_review' => false,
        'in_review→approved' => true,
        'in_review→published' => false, // approval and publication are two acts

        // from approved: the snapshot has been taken and signed. A correction
        // is the NEXT consolidation, never a rewrite of this one.
        'approved→draft' => false,
        'approved→compiling' => false,
        'approved→in_review' => false,
        'approved→approved' => false,
        'approved→published' => true,

        // from published: nothing. Terminal, on purpose.
        'published→draft' => false,
        'published→compiling' => false,
        'published→in_review' => false,
        'published→approved' => false,
        'published→published' => false,
    ];
}

it('permits exactly the moves the chain defines and no others', function () {
    $matrix = consolidationMatrix();

    expect($matrix)->toHaveCount(count(ConsolidationStatus::cases()) ** 2);

    foreach (ConsolidationStatus::cases() as $from) {
        foreach (ConsolidationStatus::cases() as $to) {
            $key = $from->value.'→'.$to->value;

            expect($from->canTransitionTo($to))
                ->toBe($matrix[$key], "chain table disagrees about [{$key}]");
        }
    }
});

/* -------------------------------------------------------------------------- */
/* The moves that must never exist */
/* -------------------------------------------------------------------------- */

it('refuses to reopen an approved consolidation', function () {
    // THE forbidden transition of this module. Reopening would mean the
    // figures a Commissioner signed could move afterwards, which is the exact
    // thing the snapshot exists to prevent.
    expect(ConsolidationStatus::Approved->canTransitionTo(ConsolidationStatus::Compiling))->toBeFalse()
        ->and(ConsolidationStatus::Approved->canTransitionTo(ConsolidationStatus::Draft))->toBeFalse()
        ->and(ConsolidationStatus::Approved->allowedTransitions())->toBe([ConsolidationStatus::Published]);
});

it('lets nothing at all out of published', function () {
    expect(ConsolidationStatus::Published->allowedTransitions())->toBe([])
        ->and(ConsolidationStatus::Published->isTerminal())->toBeTrue();

    foreach (ConsolidationStatus::cases() as $to) {
        expect(ConsolidationStatus::Published->canTransitionTo($to))->toBeFalse();
    }
});

it('refuses to review a consolidation that has never been compiled', function () {
    expect(ConsolidationStatus::Draft->canTransitionTo(ConsolidationStatus::InReview))->toBeFalse()
        ->and(ConsolidationStatus::Draft->allowedTransitions())->toBe([ConsolidationStatus::Compiling]);
});

it('lands a return on compiling, where the narrative and the figures still are', function () {
    expect(ConsolidationStatus::InReview->canTransitionTo(ConsolidationStatus::Compiling))->toBeTrue()
        ->and(ConsolidationStatus::InReview->canTransitionTo(ConsolidationStatus::Draft))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* What each state means to the screens */
/* -------------------------------------------------------------------------- */

it('opens editing only while the secretariat still holds the document', function () {
    expect(ConsolidationStatus::Draft->isEditable())->toBeTrue()
        ->and(ConsolidationStatus::Compiling->isEditable())->toBeTrue()
        ->and(ConsolidationStatus::InReview->isEditable())->toBeFalse()
        ->and(ConsolidationStatus::Approved->isEditable())->toBeFalse()
        ->and(ConsolidationStatus::Published->isEditable())->toBeFalse();
});

it('freezes the figures at approval and keeps them frozen', function () {
    expect(ConsolidationStatus::Draft->isFrozen())->toBeFalse()
        ->and(ConsolidationStatus::Compiling->isFrozen())->toBeFalse()
        // In review the figures are closed to EDITING but not yet frozen — a
        // reviewer may still send the whole thing back.
        ->and(ConsolidationStatus::InReview->isFrozen())->toBeFalse()
        ->and(ConsolidationStatus::Approved->isFrozen())->toBeTrue()
        ->and(ConsolidationStatus::Published->isFrozen())->toBeTrue();
});

it('treats frozen and signed as the same question', function () {
    foreach (ConsolidationStatus::cases() as $status) {
        expect($status->isSigned())->toBe($status->isFrozen());
    }
});

it('never leaves a state without an icon, a label and a badge', function () {
    // Status is icon + text platform-wide, never colour alone
    // (rules/ui-design-system.md), so a state with no icon is an unreadable
    // pill on a printed board pack.
    foreach (ConsolidationStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($status->icon())->not->toBe('')
            ->and($status->badge())->not->toBe('');
    }
});

it('is reachable from draft in one direction only', function () {
    // Walking the happy path with the table alone: draft → compiling →
    // in_review → approved → published, and no step skippable.
    $path = [
        ConsolidationStatus::Draft,
        ConsolidationStatus::Compiling,
        ConsolidationStatus::InReview,
        ConsolidationStatus::Approved,
        ConsolidationStatus::Published,
    ];

    foreach ($path as $index => $status) {
        $next = $path[$index + 1] ?? null;

        if ($next !== null) {
            expect($status->canTransitionTo($next))->toBeTrue();
        }

        // And nothing two steps ahead is reachable in one move.
        $skipped = $path[$index + 2] ?? null;

        if ($skipped !== null) {
            expect($status->canTransitionTo($skipped))->toBeFalse();
        }
    }
});
