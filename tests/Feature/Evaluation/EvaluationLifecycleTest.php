<?php

/**
 * The life of an evaluation: planned → in_progress → draft_report →
 * under_review → approved → published, with `cancelled` reachable from every
 * live state (App\Enums\EvaluationStatus,
 * App\Actions\Evaluation\TransitionEvaluationStatus).
 *
 * Every allowed transition is driven end to end with the actor the design
 * names for it, and the forbidden ones are asserted one at a time, because
 * what each of them would let somebody do is different:
 *
 *  - anything that skips `under_review` produces findings nobody independent
 *    ever read;
 *  - `published → *` would let an MDA unpublish conclusions it has come to
 *    dislike;
 *  - `approved → cancelled` would let an inconvenient finding be abandoned
 *    after it had been signed for.
 *
 * SEPARATION OF DUTIES GETS ITS OWN SECTION. The evaluation lead cannot
 * approve their own evaluation, and neither can whoever sent it up for review.
 * Both are facts about who already acted on this row, both live in the
 * chokepoint rather than in the policy, and both are asserted against an actor
 * who would otherwise be perfectly entitled — otherwise the test would pass on
 * a plain permission failure.
 */

use App\Actions\Evaluation\ApproveEvaluation;
use App\Actions\Evaluation\CommissionEvaluation;
use App\Actions\Evaluation\DraftEvaluationReport;
use App\Actions\Evaluation\PublishEvaluation;
use App\Actions\Evaluation\RecordCriterionScore;
use App\Actions\Evaluation\ResolveReportTemplate;
use App\Actions\Evaluation\SubmitEvaluationForReview;
use App\Actions\Evaluation\TransitionEvaluationStatus;
use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Enums\Role;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Exceptions\Evaluation\InvalidEvaluationTransition;
use App\Models\Evaluation;
use App\Models\EvaluationEvent;
use App\Models\EvaluationTeamMember;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->director = memberOf(User::factory()->create(['name' => 'Bolanle Adewale']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Ifeoma Nnadi']), $this->works, Role::MeOfficer);
    $this->lead = memberOf(User::factory()->create(['name' => 'Segun Fashola']), $this->works, Role::MeOfficer);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    $this->evaluation = app(CommissionEvaluation::class)($this->officer, [
        'project_id' => $this->project->id,
        'scope' => 'project',
        'type' => EvaluationType::MidTerm,
        'title' => 'Mid-term evaluation of the township road programme',
        'purpose' => 'Establish whether the intervention is on course to deliver its outcome targets.',
        'sponsor' => 'State M&E Secretariat',
    ], [
        ['user_id' => $this->lead->id, 'role' => 'lead'],
        ['user_id' => $this->officer->id, 'role' => 'member'],
    ]);
});

/** Re-read an evaluation through the model, inside its own workspace. */
function evaluationNow(Evaluation $evaluation): Evaluation
{
    return app(CurrentTenant::class)->runAs(
        $evaluation->loadMissing('tenant')->tenant,
        fn (): Evaluation => Evaluation::query()
            ->with(['sections', 'criterionScores'])
            ->whereKey($evaluation->getKey())
            ->firstOrFail(),
    );
}

/** The lifecycle ledger, oldest first: [from, to, actor]. */
function evaluationLedger(Evaluation $evaluation): array
{
    return EvaluationEvent::query()
        ->where('evaluation_id', $evaluation->id)
        ->orderBy('id')
        ->get()
        ->map(fn (EvaluationEvent $event): array => [
            $event->from_status?->value,
            $event->to_status->value,
            $event->actor_id,
        ])
        ->all();
}

/** Write every required section and score every criterion — a report ready for review. */
function finishTheReport(Evaluation $evaluation, User $actor): void
{
    $bodies = [];

    foreach ((new ResolveReportTemplate)() as $section) {
        if ($section['required']) {
            $bodies[$section['key']] = 'Written up in full for '.$section['heading'].'.';
        }
    }

    app(DraftEvaluationReport::class)($evaluation, $actor, $bodies);

    foreach ($evaluation->loadMissing('criterionScores')->criterionScores as $score) {
        app(RecordCriterionScore::class)(
            $evaluation,
            $actor,
            $score->criterion,
            '4',
            'Evidenced by the beneficiary interviews and the monitoring returns for the period.',
        );
    }
}

/* -------------------------------------------------------------------------- */
/* Commissioning */
/* -------------------------------------------------------------------------- */

it('commissions an evaluation as planned, with the report skeleton and scorecard already built', function () {
    $evaluation = evaluationNow($this->evaluation);

    expect($evaluation->status)->toBe(EvaluationStatus::Planned)
        ->and($evaluation->commissioned_by_id)->toBe($this->officer->id)
        // Eleven sections and one row per configured criterion, so the review
        // gate has something concrete to refuse on and the scorecard shows
        // what still has to be ANSWERED.
        ->and($evaluation->sections)->toHaveCount(count((new ResolveReportTemplate)()))
        ->and($evaluation->criterionScores)->toHaveCount(count(config('platform.evaluation.criteria')))
        // Not yet scored — emphatically not zero.
        ->and($evaluation->overallScore())->toBeNull()
        // The ledger starts at the commission, not at the first move.
        ->and(evaluationLedger($evaluation))->toBe([[null, 'planned', $this->officer->id]]);
});

it('refuses a commission that names nothing to evaluate', function () {
    expect(fn () => app(CommissionEvaluation::class)($this->officer, [
        'scope' => 'programme',
        'type' => EvaluationType::Impact,
        'title' => 'An evaluation of nothing in particular',
        'purpose' => 'Unclear, which is the problem.',
        'sponsor' => 'State M&E Secretariat',
    ]))->toThrow(EvaluationRuleViolation::class, 'must name what it is evaluating');
});

it('refuses a team with no lead, or with two', function (array $team) {
    // The lead is the separation key: zero leads would make the approval
    // guard vacuous and two would make "the lead" ambiguous.
    expect(fn () => app(CommissionEvaluation::class)($this->officer, [
        'project_id' => $this->project->id,
        'scope' => 'project',
        'type' => EvaluationType::MidTerm,
        'title' => 'A second evaluation of the township road programme',
        'purpose' => 'Establish whether the intervention is on course to deliver.',
        'sponsor' => 'State M&E Secretariat',
    ], $team))->toThrow(EvaluationRuleViolation::class, 'exactly one lead');
})->with([
    'no lead at all' => [fn () => [['external_name' => 'Dr. A. Balogun', 'role' => 'member']]],
    'two leads' => [fn () => [
        ['external_name' => 'Dr. A. Balogun', 'role' => 'lead'],
        ['external_name' => 'Dr. F. Okoro', 'role' => 'lead'],
    ]],
]);

/* -------------------------------------------------------------------------- */
/* Every allowed transition, with the actor the design names for it */
/* -------------------------------------------------------------------------- */

it('walks an evaluation the whole way from commission to publication', function () {
    $transition = app(TransitionEvaluationStatus::class);

    // 1. Fieldwork begins — the team's own act.
    $transition($this->evaluation, EvaluationStatus::InProgress, $this->lead);
    expect(evaluationNow($this->evaluation)->status)->toBe(EvaluationStatus::InProgress);

    // 2. The draft is called complete, which needs it to actually be written.
    finishTheReport($this->evaluation, $this->lead);
    $transition($this->evaluation, EvaluationStatus::DraftReport, $this->lead);
    expect(evaluationNow($this->evaluation)->status)->toBe(EvaluationStatus::DraftReport);

    // 3. The lead files it for review.
    app(SubmitEvaluationForReview::class)($this->evaluation, $this->lead);

    $underReview = evaluationNow($this->evaluation);
    expect($underReview->status)->toBe(EvaluationStatus::UnderReview)
        ->and($underReview->submitted_by_id)->toBe($this->lead->id);

    // 4. The director signs for the findings — neither the lead nor the filer.
    app(ApproveEvaluation::class)($this->evaluation, $this->director);

    $approved = evaluationNow($this->evaluation);
    expect($approved->status)->toBe(EvaluationStatus::Approved)
        ->and($approved->approved_by_id)->toBe($this->director->id)
        ->and($approved->approved_at)->not->toBeNull()
        // Approval settles the findings; it does not make them public.
        ->and($approved->published_at)->toBeNull();

    // 5. Publication is the portal gate — a separate, deliberate act.
    app(PublishEvaluation::class)($this->evaluation, $this->director);

    $published = evaluationNow($this->evaluation);
    expect($published->status)->toBe(EvaluationStatus::Published)
        ->and($published->published_by_id)->toBe($this->director->id)
        ->and(evaluationLedger($this->evaluation))->toBe([
            [null, 'planned', $this->officer->id],
            ['planned', 'in_progress', $this->lead->id],
            ['in_progress', 'draft_report', $this->lead->id],
            ['draft_report', 'under_review', $this->lead->id],
            ['under_review', 'approved', $this->director->id],
            ['approved', 'published', $this->director->id],
        ]);
});

it('sends a report back to the team with a reason, and clears who filed it', function () {
    $transition = app(TransitionEvaluationStatus::class);

    $transition($this->evaluation, EvaluationStatus::InProgress, $this->lead);
    finishTheReport($this->evaluation, $this->lead);
    $transition($this->evaluation, EvaluationStatus::DraftReport, $this->lead);
    app(SubmitEvaluationForReview::class)($this->evaluation, $this->lead);

    $transition(
        $this->evaluation,
        EvaluationStatus::DraftReport,
        $this->director,
        'The counterfactual is not described and the sampling frame is missing from the methodology.',
    );

    $sentBack = evaluationNow($this->evaluation);

    expect($sentBack->status)->toBe(EvaluationStatus::DraftReport)
        // "Who filed this" must mean the person who filed the version now in
        // front of the approver. The ledger keeps the earlier one.
        ->and($sentBack->submitted_by_id)->toBeNull()
        ->and($sentBack->submitted_at)->toBeNull();

    expect(evaluationLedger($this->evaluation))->toContain(
        ['under_review', 'draft_report', $this->director->id],
    );

    // Rework, not rejection: the same report goes back up once it is fixed.
    app(SubmitEvaluationForReview::class)($this->evaluation, $this->lead);
    expect(evaluationNow($this->evaluation)->status)->toBe(EvaluationStatus::UnderReview);
});

it('refuses to send a report back with no stated reason', function () {
    $transition = app(TransitionEvaluationStatus::class);

    $transition($this->evaluation, EvaluationStatus::InProgress, $this->lead);
    finishTheReport($this->evaluation, $this->lead);
    $transition($this->evaluation, EvaluationStatus::DraftReport, $this->lead);
    app(SubmitEvaluationForReview::class)($this->evaluation, $this->lead);

    // A team told only "sent back" cannot act on it.
    expect(fn () => $transition($this->evaluation, EvaluationStatus::DraftReport, $this->director, '  '))
        ->toThrow(EvaluationRuleViolation::class);

    expect(evaluationNow($this->evaluation)->status)->toBe(EvaluationStatus::UnderReview);
});

it('cancels a live commission from any stage, always with a reason on the record', function (string $state) {
    $evaluation = Evaluation::factory()->forProject($this->project)->{$state}()->create();

    app(TransitionEvaluationStatus::class)(
        $evaluation,
        EvaluationStatus::Cancelled,
        $this->director,
        'The programme was restructured before fieldwork began.',
    );

    $cancelled = evaluationNow($evaluation);

    // An abandoned commission is closed on the record, never deleted — and
    // never without an explanation, because a vanishing evaluation is the one
    // thing this register exists to prevent.
    expect($cancelled->status)->toBe(EvaluationStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toContain('restructured');
})->with([
    'planned' => ['planned'],
    'in progress' => ['inProgress'],
    'draft report' => ['draftReport'],
    'under review' => ['underReview'],
]);

it('refuses a cancellation with no stated reason', function () {
    expect(fn () => app(TransitionEvaluationStatus::class)(
        $this->evaluation,
        EvaluationStatus::Cancelled,
        $this->director,
    ))->toThrow(EvaluationRuleViolation::class);

    expect(evaluationNow($this->evaluation)->status)->toBe(EvaluationStatus::Planned);
});

/* -------------------------------------------------------------------------- */
/* Separation of duties — one rule, one test each */
/* -------------------------------------------------------------------------- */

it('refuses to let the evaluation LEAD approve their own evaluation', function () {
    // The lead here is the DIRECTOR: they hold `evaluations.approve` and
    // would be perfectly entitled to approve anybody else's findings. What
    // stops them is that they signed for these.
    $evaluation = Evaluation::factory()->forProject($this->project)->underReview($this->officer)->create();
    EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead($this->director)->create();

    expect(fn () => app(ApproveEvaluation::class)($evaluation, $this->director))
        ->toThrow(EvaluationRuleViolation::class, 'cannot approve their own evaluation');

    expect(evaluationNow($evaluation)->status)->toBe(EvaluationStatus::UnderReview);
});

it('refuses to let whoever FILED the report approve it, even if somebody else led the work', function () {
    $evaluation = Evaluation::factory()->forProject($this->project)->underReview($this->director)->create();
    EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead($this->lead)->create();

    // The person who decided the report was ready cannot also be the person
    // who decides it is right.
    expect(fn () => app(ApproveEvaluation::class)($evaluation, $this->director))
        ->toThrow(EvaluationRuleViolation::class, 'cannot also approve it');
});

it('lets a director who neither led nor filed the report approve it', function () {
    // The mirror of the two refusals above: without this, both would pass on
    // a director who simply cannot approve anything.
    $second = memberOf(User::factory()->create(['name' => 'Chidi Okeke']), $this->works, Role::MdaAdmin);
    actingOnTenant($this->works);

    $evaluation = Evaluation::factory()->forProject($this->project)->underReview($this->officer)->create();
    EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead($this->lead)->create();

    app(ApproveEvaluation::class)($evaluation, $second);

    expect(evaluationNow($evaluation)->status)->toBe(EvaluationStatus::Approved)
        ->and(evaluationNow($evaluation)->approved_by_id)->toBe($second->id);
});

it('still refuses the lead when they try to approve through the bare chokepoint', function () {
    // Not only through ApproveEvaluation: a future second approval path — a
    // console command, a bulk action — inherits the guard because it lives in
    // the chokepoint rather than in the named Action.
    $evaluation = Evaluation::factory()->forProject($this->project)->underReview($this->officer)->create();
    EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead($this->director)->create();

    expect(fn () => app(TransitionEvaluationStatus::class)(
        $evaluation,
        EvaluationStatus::Approved,
        $this->director,
    ))->toThrow(EvaluationRuleViolation::class);
});

/* -------------------------------------------------------------------------- */
/* The completeness gates */
/* -------------------------------------------------------------------------- */

it('refuses to call a draft complete while a required chapter is unwritten', function () {
    app(TransitionEvaluationStatus::class)($this->evaluation, EvaluationStatus::InProgress, $this->lead);

    expect(fn () => app(TransitionEvaluationStatus::class)(
        $this->evaluation,
        EvaluationStatus::DraftReport,
        $this->lead,
    ))->toThrow(EvaluationRuleViolation::class, 'The report is not finished');
});

it('refuses a review when the scorecard is not finished', function () {
    $transition = app(TransitionEvaluationStatus::class);

    $transition($this->evaluation, EvaluationStatus::InProgress, $this->lead);

    // Report written, scorecard untouched. A complete report with an unscored
    // scorecard and a full scorecard with an unwritten findings chapter are
    // the same failure — a review with nothing to review.
    $bodies = [];

    foreach ((new ResolveReportTemplate)() as $section) {
        if ($section['required']) {
            $bodies[$section['key']] = 'Written up in full.';
        }
    }

    app(DraftEvaluationReport::class)($this->evaluation, $this->lead, $bodies);
    $transition($this->evaluation, EvaluationStatus::DraftReport, $this->lead);

    expect(fn () => app(SubmitEvaluationForReview::class)($this->evaluation, $this->lead))
        ->toThrow(EvaluationRuleViolation::class, 'The scorecard is not finished');
});

it('refuses a criterion score with no reasoning behind it', function () {
    // A bare number with no justification is an opinion, and an evaluation is
    // supposed to be the other thing.
    expect(fn () => app(RecordCriterionScore::class)(
        $this->evaluation,
        $this->lead,
        'relevance',
        '4',
        '   ',
    ))->toThrow(EvaluationRuleViolation::class, 'needs its reasoning');
});

it('refuses a score outside the instance’s own scale', function () {
    expect(fn () => app(RecordCriterionScore::class)(
        $this->evaluation,
        $this->lead,
        'relevance',
        (string) (config('platform.evaluation.score_max') + 1),
        'Beyond the scale this instance uses.',
    ))->toThrow(EvaluationRuleViolation::class, 'must be between');
});

it('refuses a criterion the instance does not recognise', function () {
    // The overall score is the weighted mean of these rows, so a stray
    // criterion silently changes every evaluation's headline figure.
    expect(fn () => app(RecordCriterionScore::class)(
        $this->evaluation,
        $this->lead,
        'vibes',
        '5',
        'Felt about right on the day.',
    ))->toThrow(EvaluationRuleViolation::class, 'not one of this instance');
});

it('refuses a report section this evaluation does not have', function () {
    // An unknown key is refused rather than silently ignored: a chapter that
    // vanished because it was misspelt is worse than an error message.
    expect(fn () => app(DraftEvaluationReport::class)($this->evaluation, $this->lead, [
        'concluding_remarks' => 'A chapter this template never had.',
    ]))->toThrow(EvaluationRuleViolation::class, 'not a section');
});

it('freezes the report and the scorecard the moment the findings go up for review', function () {
    $transition = app(TransitionEvaluationStatus::class);

    $transition($this->evaluation, EvaluationStatus::InProgress, $this->lead);
    finishTheReport($this->evaluation, $this->lead);
    $transition($this->evaluation, EvaluationStatus::DraftReport, $this->lead);
    app(SubmitEvaluationForReview::class)($this->evaluation, $this->lead);

    // Findings that can change while they are being approved are not findings.
    expect(fn () => app(DraftEvaluationReport::class)($this->evaluation, $this->lead, [
        'findings' => 'Quietly rewritten while the director was reading it.',
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => app(RecordCriterionScore::class)(
        $this->evaluation,
        $this->lead,
        'relevance',
        '5',
        'Revised upwards after the fact.',
    ))->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* The forbidden moves */
/* -------------------------------------------------------------------------- */

it('refuses a move the lifecycle table does not allow, before any permission is considered', function (
    string $state,
    EvaluationStatus $to,
) {
    $evaluation = Evaluation::factory()->forProject($this->project)->{$state}()->create();

    // The actor holds every evaluation permission there is. An impossible
    // move is impossible for everyone.
    expect(fn () => app(TransitionEvaluationStatus::class)(
        $evaluation,
        $to,
        $this->director,
        'A stated reason, so the refusal is the chain and not the precondition.',
    ))->toThrow(InvalidEvaluationTransition::class);
})->with([
    'fieldwork cannot skip the draft' => ['inProgress', EvaluationStatus::UnderReview],
    'a draft report cannot be approved unread' => ['draftReport', EvaluationStatus::Approved],
    'a draft report cannot be published' => ['draftReport', EvaluationStatus::Published],
    'a review cannot publish without approving' => ['underReview', EvaluationStatus::Published],
    'an approved evaluation cannot be re-reviewed' => ['approved', EvaluationStatus::UnderReview],
    'an approved evaluation cannot be cancelled' => ['approved', EvaluationStatus::Cancelled],
    'a published evaluation cannot be withdrawn' => ['published', EvaluationStatus::Approved],
    'a published evaluation cannot be sent back' => ['published', EvaluationStatus::DraftReport],
    'a published evaluation cannot be cancelled' => ['published', EvaluationStatus::Cancelled],
    'a cancelled commission cannot be restarted' => ['cancelled', EvaluationStatus::InProgress],
    'a cancelled commission cannot be approved' => ['cancelled', EvaluationStatus::Approved],
]);

it('refuses a second attempt at a move the record has already made', function () {
    $evaluation = Evaluation::factory()->forProject($this->project)->planned()->create();

    app(TransitionEvaluationStatus::class)($evaluation, EvaluationStatus::InProgress, $this->director);

    // Two directors pressing the same button in the same second must produce
    // one move and one refusal.
    expect(fn () => app(TransitionEvaluationStatus::class)(
        evaluationNow($evaluation),
        EvaluationStatus::InProgress,
        $this->director,
    ))->toThrow(InvalidEvaluationTransition::class);
});

/* -------------------------------------------------------------------------- */
/* The ledger is append-only */
/* -------------------------------------------------------------------------- */

it('keeps the evaluation ledger append-only', function () {
    $event = EvaluationEvent::factory()->forEvaluation($this->evaluation)->create();

    // A lifecycle step is corrected by recording another one; audit records
    // are retained, never edited or deleted.
    expect(fn () => $event->update(['reason' => 'rewritten']))->toThrow(RuntimeException::class)
        ->and(fn () => $event->delete())->toThrow(RuntimeException::class);
});

/* -------------------------------------------------------------------------- */
/* The derived headline figure */
/* -------------------------------------------------------------------------- */

it('derives the overall score as the weighted mean, skipping what nobody answered', function () {
    $evaluation = evaluationNow($this->evaluation);

    app(RecordCriterionScore::class)($evaluation, $this->lead, 'relevance', '4', 'Evidenced by the needs assessment.');
    app(RecordCriterionScore::class)($evaluation, $this->lead, 'efficiency', '2', 'Unit costs ran well above appraisal.');

    // "Not answered" and "answered badly" are different findings, so the three
    // unscored criteria are skipped rather than counted as zero.
    expect(evaluationNow($evaluation)->overallScore())->toBe(3.0);

    app(RecordCriterionScore::class)(
        $evaluation,
        $this->lead,
        'sustainability',
        '1',
        'No maintenance budget line exists beyond handover.',
        weight: '3.00',
    );

    // (4×1 + 2×1 + 1×3) / 5 = 1.8
    expect(evaluationNow($evaluation)->overallScore())->toBe(1.8)
        ->and(evaluationNow($evaluation)->overallScorePercent(
            (int) config('platform.evaluation.score_max'),
        ))->toBe(36.0);
});

it('clears a score back to unanswered without pretending it was zero', function () {
    $evaluation = evaluationNow($this->evaluation);

    app(RecordCriterionScore::class)($evaluation, $this->lead, 'relevance', '4', 'Evidenced by the needs assessment.');
    expect(evaluationNow($evaluation)->overallScore())->toBe(4.0);

    app(RecordCriterionScore::class)($evaluation, $this->lead, 'relevance', null, '');

    expect(evaluationNow($evaluation)->overallScore())->toBeNull()
        ->and(evaluationNow($evaluation)->unscoredCriteria())->toContain('relevance');
});
