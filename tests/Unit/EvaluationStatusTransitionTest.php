<?php

/**
 * The evaluation lifecycle table, in isolation (App\Enums\EvaluationStatus),
 * with the follow-up register's table alongside it
 * (App\Enums\RecommendationStatus) — the two lifecycles the evaluation module
 * is built around.
 *
 * Pure unit tests on the enums: no database, no container. Both tables are
 * consulted FIRST by their chokepoint Action, before any permission is
 * considered, so what they permit is what is structurally possible for every
 * actor on the platform — a super admin included. That is worth asserting
 * without the rest of the stack in the way, and it is the cheapest place to
 * catch a case accidentally added to a match arm.
 *
 * Every allowed transition, and the forbidden ones that matter.
 */

use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;

/*
|--------------------------------------------------------------------------
| Evaluations: planned → in_progress → draft_report → under_review →
|              approved → published, plus cancelled
|--------------------------------------------------------------------------
*/

it('states the whole evaluation lifecycle table, arm by arm', function () {
    expect(EvaluationStatus::Planned->allowedTransitions())
        ->toBe([EvaluationStatus::InProgress, EvaluationStatus::Cancelled])
        ->and(EvaluationStatus::InProgress->allowedTransitions())
        ->toBe([EvaluationStatus::DraftReport, EvaluationStatus::Cancelled])
        ->and(EvaluationStatus::DraftReport->allowedTransitions())
        ->toBe([EvaluationStatus::UnderReview, EvaluationStatus::Cancelled])
        // Review either approves, sends back for rework, or abandons the
        // commission. Rework is not rejection, and it is not a dead end.
        ->and(EvaluationStatus::UnderReview->allowedTransitions())
        ->toBe([EvaluationStatus::Approved, EvaluationStatus::DraftReport, EvaluationStatus::Cancelled])
        // An approved evaluation can only be published — not cancelled. The
        // findings have been signed for; abandoning them afterwards would be
        // how an inconvenient result disappears.
        ->and(EvaluationStatus::Approved->allowedTransitions())->toBe([EvaluationStatus::Published])
        ->and(EvaluationStatus::Published->allowedTransitions())->toBe([])
        ->and(EvaluationStatus::Cancelled->allowedTransitions())->toBe([]);
});

it('permits every step of the commissioned-to-published path', function (EvaluationStatus $from, EvaluationStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'planned → fieldwork' => [EvaluationStatus::Planned, EvaluationStatus::InProgress],
    'fieldwork → draft report' => [EvaluationStatus::InProgress, EvaluationStatus::DraftReport],
    'draft report → under review' => [EvaluationStatus::DraftReport, EvaluationStatus::UnderReview],
    'under review → approved' => [EvaluationStatus::UnderReview, EvaluationStatus::Approved],
    'approved → published' => [EvaluationStatus::Approved, EvaluationStatus::Published],
    'under review → back to the team' => [EvaluationStatus::UnderReview, EvaluationStatus::DraftReport],
]);

it('lets a live commission be abandoned at any stage before approval', function (EvaluationStatus $from) {
    expect($from->canTransitionTo(EvaluationStatus::Cancelled))->toBeTrue();
})->with([
    'planned' => [EvaluationStatus::Planned],
    'in progress' => [EvaluationStatus::InProgress],
    'draft report' => [EvaluationStatus::DraftReport],
    'under review' => [EvaluationStatus::UnderReview],
]);

it('refuses the moves that would let an evaluation dodge review or reverse itself', function (EvaluationStatus $from, EvaluationStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    // Nothing skips review: no findings reach `approved` without an approver
    // who is neither their lead nor the person who filed them.
    'fieldwork straight to approved' => [EvaluationStatus::InProgress, EvaluationStatus::Approved],
    'draft report straight to approved' => [EvaluationStatus::DraftReport, EvaluationStatus::Approved],
    'draft report straight to published' => [EvaluationStatus::DraftReport, EvaluationStatus::Published],
    'under review straight to published' => [EvaluationStatus::UnderReview, EvaluationStatus::Published],
    // Publication is terminal: an MDA cannot unpublish findings it has since
    // come to dislike. A superseding evaluation is the correction.
    'published back to approved' => [EvaluationStatus::Published, EvaluationStatus::Approved],
    'published back to the team' => [EvaluationStatus::Published, EvaluationStatus::DraftReport],
    'published cancelled after the fact' => [EvaluationStatus::Published, EvaluationStatus::Cancelled],
    // An approved evaluation is signed for; it does not go back.
    'approved back to review' => [EvaluationStatus::Approved, EvaluationStatus::UnderReview],
    'approved cancelled' => [EvaluationStatus::Approved, EvaluationStatus::Cancelled],
    // A cancelled commission is closed on the record, never reopened.
    'cancelled restarted' => [EvaluationStatus::Cancelled, EvaluationStatus::InProgress],
    'cancelled back to planned' => [EvaluationStatus::Cancelled, EvaluationStatus::Planned],
    // The fieldwork stage is entered once, from the commission.
    'draft report back to fieldwork' => [EvaluationStatus::DraftReport, EvaluationStatus::InProgress],
    'a status cannot move to itself' => [EvaluationStatus::InProgress, EvaluationStatus::InProgress],
]);

it('marks exactly the two end states terminal', function () {
    $terminal = array_values(array_filter(
        EvaluationStatus::cases(),
        fn (EvaluationStatus $status): bool => $status->isTerminal(),
    ));

    expect($terminal)->toBe([EvaluationStatus::Published, EvaluationStatus::Cancelled])
        ->and(EvaluationStatus::Published->isLive())->toBeFalse()
        ->and(EvaluationStatus::Cancelled->isLive())->toBeFalse()
        ->and(EvaluationStatus::UnderReview->isLive())->toBeTrue();
});

it('freezes the findings the moment the report goes up for review', function () {
    // Findings that can change while they are being approved are not findings.
    expect(EvaluationStatus::Planned->isEditable())->toBeTrue()
        ->and(EvaluationStatus::InProgress->isEditable())->toBeTrue()
        ->and(EvaluationStatus::DraftReport->isEditable())->toBeTrue()
        ->and(EvaluationStatus::UnderReview->isEditable())->toBeFalse()
        ->and(EvaluationStatus::Approved->isEditable())->toBeFalse()
        ->and(EvaluationStatus::Published->isEditable())->toBeFalse()
        ->and(EvaluationStatus::Cancelled->isEditable())->toBeFalse();
});

it('calls the findings settled only once someone has signed for them', function () {
    expect(EvaluationStatus::Approved->isSettled())->toBeTrue()
        ->and(EvaluationStatus::Published->isSettled())->toBeTrue()
        ->and(EvaluationStatus::UnderReview->isSettled())->toBeFalse()
        ->and(EvaluationStatus::Cancelled->isSettled())->toBeFalse();
});

it('gives every evaluation status a distinct icon and label, so status is never colour alone', function () {
    $labels = array_map(fn (EvaluationStatus $s): string => $s->label(), EvaluationStatus::cases());
    $icons = array_map(fn (EvaluationStatus $s): string => $s->icon(), EvaluationStatus::cases());

    expect(array_unique($labels))->toHaveCount(count(EvaluationStatus::cases()))
        ->and(array_unique($icons))->toHaveCount(count(EvaluationStatus::cases()))
        // Every status also names a badge tone the design system already owns.
        ->and(array_filter(array_map(fn (EvaluationStatus $s): string => $s->badge(), EvaluationStatus::cases())))
        ->toHaveCount(count(EvaluationStatus::cases()));
});

it('offers every status and every type as a select option, keyed by the stored value', function () {
    expect(array_keys(EvaluationStatus::options()))
        ->toBe(array_column(EvaluationStatus::cases(), 'value'))
        ->and(array_keys(EvaluationType::options()))
        ->toBe(array_column(EvaluationType::cases(), 'value'))
        // Each format says what it is for, next to the choice rather than in a
        // tooltip: "ex-post" and "impact" are terms of art.
        ->and(array_filter(array_map(fn (EvaluationType $t): string => $t->description(), EvaluationType::cases())))
        ->toHaveCount(count(EvaluationType::cases()));
});

/*
|--------------------------------------------------------------------------
| Recommendations: the follow-up loop
|--------------------------------------------------------------------------
| The register that makes an evaluation more than a document. Its table is
| stricter than it looks, and the strictness is the point.
*/

it('states the whole recommendation lifecycle table, arm by arm', function () {
    expect(RecommendationStatus::Open->allowedTransitions())
        ->toBe([RecommendationStatus::Accepted, RecommendationStatus::Rejected, RecommendationStatus::Superseded])
        ->and(RecommendationStatus::Accepted->allowedTransitions())
        ->toBe([RecommendationStatus::InProgress, RecommendationStatus::Implemented, RecommendationStatus::Superseded])
        ->and(RecommendationStatus::InProgress->allowedTransitions())
        ->toBe([RecommendationStatus::Implemented, RecommendationStatus::Superseded])
        ->and(RecommendationStatus::Implemented->allowedTransitions())->toBe([])
        ->and(RecommendationStatus::Rejected->allowedTransitions())->toBe([])
        ->and(RecommendationStatus::Superseded->allowedTransitions())->toBe([]);
});

it('permits every step of the follow-up loop', function (RecommendationStatus $from, RecommendationStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'open → accepted' => [RecommendationStatus::Open, RecommendationStatus::Accepted],
    'open → declined' => [RecommendationStatus::Open, RecommendationStatus::Rejected],
    'accepted → being implemented' => [RecommendationStatus::Accepted, RecommendationStatus::InProgress],
    'accepted → implemented' => [RecommendationStatus::Accepted, RecommendationStatus::Implemented],
    'being implemented → implemented' => [RecommendationStatus::InProgress, RecommendationStatus::Implemented],
    'open → superseded' => [RecommendationStatus::Open, RecommendationStatus::Superseded],
    'accepted → superseded' => [RecommendationStatus::Accepted, RecommendationStatus::Superseded],
    'being implemented → superseded' => [RecommendationStatus::InProgress, RecommendationStatus::Superseded],
]);

it('refuses a quiet decline months after the recommendation was accepted', function () {
    // THE rule this table exists for. Declining is a decision taken when the
    // recommendation lands, with a reason. Accepting it and then dropping it
    // is precisely the move the register makes visible — and the honest way
    // to record it is a supersession that names what replaced it.
    expect(RecommendationStatus::Accepted->canTransitionTo(RecommendationStatus::Rejected))->toBeFalse()
        ->and(RecommendationStatus::InProgress->canTransitionTo(RecommendationStatus::Rejected))->toBeFalse();
});

it('refuses to reopen or rewind a closed recommendation', function (RecommendationStatus $from, RecommendationStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'implemented reopened' => [RecommendationStatus::Implemented, RecommendationStatus::Open],
    'implemented restarted' => [RecommendationStatus::Implemented, RecommendationStatus::InProgress],
    'implemented superseded' => [RecommendationStatus::Implemented, RecommendationStatus::Superseded],
    'declined accepted later' => [RecommendationStatus::Rejected, RecommendationStatus::Accepted],
    'superseded reopened' => [RecommendationStatus::Superseded, RecommendationStatus::Open],
    'accepted un-accepted' => [RecommendationStatus::Accepted, RecommendationStatus::Open],
    'being implemented rewound' => [RecommendationStatus::InProgress, RecommendationStatus::Accepted],
]);

it('defines outstanding as the three live states and nothing else', function () {
    // One definition, shared by the register, the stat row and the nightly
    // sweep, so a screen and a job can never disagree about what is owed.
    $outstanding = array_values(array_filter(
        RecommendationStatus::cases(),
        fn (RecommendationStatus $status): bool => $status->isOutstanding(),
    ));

    expect($outstanding)->toBe([
        RecommendationStatus::Open,
        RecommendationStatus::Accepted,
        RecommendationStatus::InProgress,
    ]);
});

it('requires a stated reason for exactly the two closures that make a recommendation go away', function () {
    expect(RecommendationStatus::Rejected->requiresReason())->toBeTrue()
        ->and(RecommendationStatus::Superseded->requiresReason())->toBeTrue()
        ->and(RecommendationStatus::Implemented->requiresReason())->toBeFalse()
        ->and(RecommendationStatus::Accepted->requiresReason())->toBeFalse()
        ->and(RecommendationStatus::InProgress->requiresReason())->toBeFalse()
        ->and(RecommendationStatus::Open->requiresReason())->toBeFalse();
});

it('gives every recommendation status a distinct icon and label', function () {
    $labels = array_map(fn (RecommendationStatus $s): string => $s->label(), RecommendationStatus::cases());
    $icons = array_map(fn (RecommendationStatus $s): string => $s->icon(), RecommendationStatus::cases());

    expect(array_unique($labels))->toHaveCount(count(RecommendationStatus::cases()))
        ->and(array_unique($icons))->toHaveCount(count(RecommendationStatus::cases()));
});

it('ranks priority most-pressing-first for a SQL ordering', function () {
    // Returned as an int rather than relying on declaration order, because the
    // register sorts in SQL where declaration order means nothing.
    $weights = array_map(
        fn (RecommendationPriority $p): int => $p->weight(),
        RecommendationPriority::cases(),
    );

    expect($weights)->toBe([0, 1, 2, 3])
        ->and(RecommendationPriority::Critical->weight())
        ->toBeLessThan(RecommendationPriority::Low->weight())
        ->and(array_keys(RecommendationPriority::options()))
        ->toBe(array_column(RecommendationPriority::cases(), 'value'));
});
