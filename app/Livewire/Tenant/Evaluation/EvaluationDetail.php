<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Evaluation;

use App\Actions\Evaluation\ApproveEvaluation;
use App\Actions\Evaluation\DraftEvaluationReport;
use App\Actions\Evaluation\PublishEvaluation;
use App\Actions\Evaluation\RaiseRecommendation;
use App\Actions\Evaluation\RecordCriterionScore;
use App\Actions\Evaluation\SubmitEvaluationForReview;
use App\Actions\Evaluation\TransitionEvaluationStatus;
use App\Enums\EvaluationStatus;
use App\Enums\RecommendationPriority;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Exceptions\Evaluation\InvalidEvaluationTransition;
use App\Models\Evaluation;
use App\Models\EvaluationEvent;
use App\Models\Recommendation;
use App\Models\User;
use App\Rules\IsWorkspaceMember;
use App\Support\Money;
use App\Support\SettingsRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The evaluation itself: the commission, the criteria scorecard, the
 * eleven-section report builder, the team, the document vault, the
 * recommendations it raised, and the lifecycle controls.
 *
 * One screen rather than five, because an evaluator writing findings scores a
 * criterion in the same minute, and a director approving one reads the
 * scorecard and the findings together. Splitting them would mean five routes
 * that each have to re-answer "may you see this".
 *
 * EVERY MUTATING METHOD AUTHORIZES AGAIN. Route middleware does not protect a
 * Livewire update POST by itself, and hiding a button is a courtesy rather
 * than a control. The separation rules (the lead cannot approve; the submitter
 * cannot approve) are domain rules owned by TransitionEvaluationStatus — here
 * they only decide whether the button is rendered, and when the Action refuses
 * anyway its own words are shown verbatim.
 */
#[Layout('layouts::tenant')]
class EvaluationDetail extends Component
{
    public Evaluation $evaluation;

    /** Section bodies, keyed by the STABLE template key the builder posts. */
    /** @var array<string, string> */
    public array $sections = [];

    /** Criterion scores being edited, keyed by criterion. */
    /** @var array<string, array{score: string, justification: string, evidence: string}> */
    public array $scores = [];

    /* Raising a recommendation. */
    public bool $raising = false;

    public string $recommendationTitle = '';

    public string $recommendationBody = '';

    public string $recommendationAddresseeId = '';

    public string $recommendationAddresseeBody = '';

    public string $recommendationPriority = 'medium';

    public string $recommendationCost = '';

    public string $recommendationTimeline = '';

    public string $recommendationDueOn = '';

    /* Lifecycle controls. */
    public bool $sendingBack = false;

    public string $decisionReason = '';

    public bool $cancelling = false;

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Evaluation $evaluation): void
    {
        $this->authorize('view', $evaluation);

        $this->evaluation = $evaluation;

        $this->hydrateForms();
    }

    /**
     * The editable copies of the report and the scorecard. Rebuilt whenever
     * the record moves, so a screen left open across a transition does not
     * keep offering a stale draft.
     */
    private function hydrateForms(): void
    {
        $this->evaluation->loadMissing(['sections', 'criterionScores']);

        $this->sections = [];

        foreach ($this->evaluation->sections as $section) {
            $this->sections[$section->key] = (string) $section->body;
        }

        $this->scores = [];

        foreach ($this->evaluation->criterionScores as $score) {
            $this->scores[$score->criterion] = [
                'score' => $score->score === null ? '' : (string) $score->score,
                'justification' => (string) $score->justification,
                'evidence' => (string) $score->evidence_reference,
            ];
        }
    }

    /* ---------------------------------------------------------------- */
    /* What this actor may do */
    /* ---------------------------------------------------------------- */

    public function canEdit(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->can('update', $this->evaluation);
    }

    public function canStartFieldwork(): bool
    {
        return $this->canEdit() && $this->evaluation->status === EvaluationStatus::Planned;
    }

    public function canMarkDrafted(): bool
    {
        return $this->canEdit() && $this->evaluation->status === EvaluationStatus::InProgress;
    }

    public function canSubmit(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->evaluation->status === EvaluationStatus::DraftReport
            && $user->can('submit', $this->evaluation);
    }

    /**
     * The approve button appears only when this actor could actually succeed.
     * The lead guard is the separation rule made visible: an evaluator who
     * signed for the findings must not be offered the button that blesses
     * them.
     */
    public function canApprove(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->evaluation->status === EvaluationStatus::UnderReview
            && $user->can('approve', $this->evaluation)
            && ! $this->evaluation->isLedBy($user)
            && $this->evaluation->submitted_by_id !== $user->id;
    }

    public function canSendBack(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->evaluation->status === EvaluationStatus::UnderReview
            && $user->can('approve', $this->evaluation);
    }

    public function canPublish(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->evaluation->status === EvaluationStatus::Approved
            && $user->can('publish', $this->evaluation);
    }

    public function canCancel(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->evaluation->status->isLive()
            && $user->can('cancel', $this->evaluation);
    }

    public function canRecommend(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->can('create', Recommendation::class)
            && ! $this->evaluation->status->isTerminal();
    }

    /** The report artifact exists once the findings are settled. */
    public function canDownloadReport(): bool
    {
        return $this->evaluation->status->isSettled();
    }

    /**
     * Why the decision panel is empty, when it is. A panel that simply
     * disappears reads as a bug; naming the reason turns a dead end into an
     * explanation.
     */
    public function blockedReason(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        if ($this->evaluation->status === EvaluationStatus::Cancelled) {
            return __('This commission was cancelled. The record is kept, but no further step is possible.');
        }

        if ($this->evaluation->status === EvaluationStatus::Published) {
            return __('This evaluation has been published. Findings are corrected by a superseding evaluation, never by unpublishing these.');
        }

        if ($this->evaluation->status === EvaluationStatus::UnderReview && $this->evaluation->isLedBy($user)) {
            return __('You led this evaluation, so you cannot approve it. Approval is an independent judgement on the findings.');
        }

        if ($this->evaluation->status === EvaluationStatus::UnderReview
            && $this->evaluation->submitted_by_id === $user->id
            && $user->can('approve', $this->evaluation)) {
            return __('You sent this report up for review, so it needs a second pair of eyes to approve it.');
        }

        if (! $this->canStartFieldwork() && ! $this->canMarkDrafted() && ! $this->canSubmit()
            && ! $this->canApprove() && ! $this->canSendBack() && ! $this->canPublish()) {
            return __('You have read access to this evaluation, but no step of its lifecycle is yours.');
        }

        return null;
    }

    /* ---------------------------------------------------------------- */
    /* Reads */
    /* ---------------------------------------------------------------- */

    #[Computed]
    public function scoreMax(): int
    {
        return app(SettingsRepository::class)->int('evaluation', 'score_max', 5);
    }

    /**
     * The append-only lifecycle history — who did what, when and why. Read
     * from the ledger rather than the record's own columns, because a report
     * sent back twice has two events and one `submitted_at`.
     *
     * @return Collection<int, EvaluationEvent>
     */
    #[Computed]
    public function timeline(): Collection
    {
        return EvaluationEvent::query()
            ->where('evaluation_id', $this->evaluation->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The follow-up entries this evaluation raised. The thing that makes the
     * whole module matter, so it is on the evaluation's own screen and not
     * only on the register.
     *
     * @return Collection<int, Recommendation>
     */
    #[Computed]
    public function recommendations(): Collection
    {
        return Recommendation::query()
            ->where('source_type', $this->evaluation->getMorphClass())
            ->where('source_id', $this->evaluation->id)
            ->with('addressee:id,name')
            ->orderBy('status')
            ->orderBy('id')
            ->get();
    }

    /**
     * Colleagues a recommendation can be addressed to. An id-keyed map, so
     * the select submits the id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function addresseeOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function priorityOptions(): array
    {
        return RecommendationPriority::options();
    }

    /* ---------------------------------------------------------------- */
    /* Writing the report and the scorecard */
    /* ---------------------------------------------------------------- */

    public function saveReport(DraftEvaluationReport $draft): void
    {
        $this->authorize('update', $this->evaluation);
        $this->failure = null;

        $this->validate([
            'sections.*' => ['nullable', 'string', 'max:100000'],
        ]);

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $written = $draft($this->evaluation, $actor, $this->sections);
        } catch (EvaluationRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(trans_choice(
            '{0} Nothing had changed — the report is as you left it.'
            .'|{1} One section saved.'
            .'|[2,*] :count sections saved.',
            $written,
            ['count' => $written],
        ));
    }

    public function saveScore(RecordCriterionScore $record, string $criterion): void
    {
        $this->authorize('update', $this->evaluation);
        $this->failure = null;

        $entry = $this->scores[$criterion] ?? null;

        if ($entry === null) {
            return;
        }

        $this->validate([
            "scores.{$criterion}.score" => ['nullable', 'numeric', 'min:0', 'max:'.$this->scoreMax()],
            "scores.{$criterion}.justification" => ['nullable', 'string', 'max:5000'],
            "scores.{$criterion}.evidence" => ['nullable', 'string', 'max:255'],
        ], [
            "scores.{$criterion}.max" => __('Scores run from 0 to :max on this instance.', ['max' => $this->scoreMax()]),
        ]);

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $record(
                $this->evaluation,
                $actor,
                $criterion,
                $entry['score'] === '' ? null : $entry['score'],
                $entry['justification'],
                $entry['evidence'] === '' ? null : $entry['evidence'],
            );
        } catch (EvaluationRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Score recorded.'));
    }

    /* ---------------------------------------------------------------- */
    /* Lifecycle */
    /* ---------------------------------------------------------------- */

    public function startFieldwork(TransitionEvaluationStatus $transition): void
    {
        $this->move($transition, EvaluationStatus::InProgress, __('Fieldwork has begun.'));
    }

    public function markDrafted(TransitionEvaluationStatus $transition): void
    {
        $this->move(
            $transition,
            EvaluationStatus::DraftReport,
            __('The draft report is complete. It can now go up for review.'),
        );
    }

    public function submitForReview(SubmitEvaluationForReview $submit): void
    {
        $this->authorize('submit', $this->evaluation);
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $submit($this->evaluation, $actor);
        } catch (EvaluationRuleViolation|InvalidEvaluationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Report sent up for review. You cannot approve findings you filed.'));
    }

    public function approve(ApproveEvaluation $approve): void
    {
        $this->authorize('approve', $this->evaluation);
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $approve($this->evaluation, $actor);
        } catch (EvaluationRuleViolation|InvalidEvaluationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Findings approved. They can now be published.'));
    }

    public function publish(PublishEvaluation $publish): void
    {
        $this->authorize('publish', $this->evaluation);
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $publish($this->evaluation, $actor);
        } catch (EvaluationRuleViolation|InvalidEvaluationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Evaluation published. It may now be disseminated.'));
    }

    public function startSendBack(): void
    {
        abort_unless($this->canSendBack(), 403);

        $this->sendingBack = true;
        $this->decisionReason = '';
        $this->resetValidation();
    }

    public function cancelSendBack(): void
    {
        $this->sendingBack = false;
        $this->decisionReason = '';
        $this->resetValidation();
    }

    public function confirmSendBack(TransitionEvaluationStatus $transition): void
    {
        abort_unless($this->canSendBack(), 403);

        $this->validate([
            'decisionReason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'decisionReason.required' => __('Say what needs revising. A team told only “sent back” cannot act on it.'),
            'decisionReason.min' => __('Give the team enough to work with — a few words at least.'),
        ], ['decisionReason' => __('reason')]);

        $this->sendingBack = false;

        $this->move(
            $transition,
            EvaluationStatus::DraftReport,
            __('Report sent back to the team with your reason.'),
            $this->decisionReason,
        );

        $this->decisionReason = '';
    }

    public function startCancel(): void
    {
        abort_unless($this->canCancel(), 403);

        $this->cancelling = true;
        $this->decisionReason = '';
        $this->resetValidation();
    }

    public function cancelCancel(): void
    {
        $this->cancelling = false;
        $this->decisionReason = '';
        $this->resetValidation();
    }

    public function confirmCancel(TransitionEvaluationStatus $transition): void
    {
        abort_unless($this->canCancel(), 403);

        $this->validate([
            'decisionReason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'decisionReason.required' => __('Say why this commission is being abandoned. An evaluation that disappears without a reason is the one thing this register exists to prevent.'),
        ], ['decisionReason' => __('reason')]);

        $this->cancelling = false;

        $this->move(
            $transition,
            EvaluationStatus::Cancelled,
            __('Commission cancelled. The record is kept with your reason.'),
            $this->decisionReason,
        );

        $this->decisionReason = '';
    }

    private function move(
        TransitionEvaluationStatus $transition,
        EvaluationStatus $to,
        string $message,
        ?string $reason = null,
    ): void {
        // The chokepoint authorizes per target status through the policy; this
        // is the screen's own gate so a doomed round trip never starts.
        $this->authorize($this->abilityFor($to), $this->evaluation);
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $transition($this->evaluation, $to, $actor, $reason);
        } catch (EvaluationRuleViolation|InvalidEvaluationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh($message);
    }

    private function abilityFor(EvaluationStatus $to): string
    {
        return match ($to) {
            EvaluationStatus::Approved => 'approve',
            EvaluationStatus::Published => 'publish',
            EvaluationStatus::Cancelled => 'cancel',
            EvaluationStatus::UnderReview => 'submit',
            // Sending back from review is the approver's act; marking a draft
            // complete is the team's. The screen only ever reaches the first
            // through canSendBack(), which already asked for `approve`.
            EvaluationStatus::DraftReport => $this->evaluation->status === EvaluationStatus::UnderReview
                ? 'approve'
                : 'update',
            default => 'update',
        };
    }

    /* ---------------------------------------------------------------- */
    /* Recommendations */
    /* ---------------------------------------------------------------- */

    public function startRecommendation(): void
    {
        $this->authorize('create', Recommendation::class);

        $this->raising = true;
        $this->resetValidation();
        $this->failure = null;
    }

    public function cancelRecommendation(): void
    {
        $this->raising = false;
        $this->reset([
            'recommendationTitle', 'recommendationBody', 'recommendationAddresseeId',
            'recommendationAddresseeBody', 'recommendationCost', 'recommendationTimeline',
            'recommendationDueOn',
        ]);
        $this->resetValidation();
    }

    public function saveRecommendation(RaiseRecommendation $raise): void
    {
        $this->authorize('create', Recommendation::class);
        $this->failure = null;

        $this->validate([
            'recommendationTitle' => ['required', 'string', 'min:8', 'max:255'],
            'recommendationBody' => ['required', 'string', 'min:20', 'max:5000'],
            // IsWorkspaceMember, not bare `integer`: the Action refuses a
            // non-member too, but a form that posts one should say so in the
            // field rather than throw a domain exception at the user.
            'recommendationAddresseeId' => [$this->recommendationAddresseeBody === '' ? 'required' : 'nullable', 'integer', new IsWorkspaceMember],
            'recommendationAddresseeBody' => [$this->recommendationAddresseeId === '' ? 'required' : 'nullable', 'string', 'max:255'],
            'recommendationPriority' => ['required', 'string', 'in:'.implode(',', array_column(RecommendationPriority::cases(), 'value'))],
            // `numeric` paired with Money::FORM_RULE — `numeric` alone accepts
            // '5.' and '1e5', which the Money cast then rejects with a 500.
            'recommendationCost' => ['nullable', 'numeric', Money::FORM_RULE, 'min:0'],
            'recommendationTimeline' => ['nullable', 'string', 'max:255'],
            'recommendationDueOn' => ['nullable', 'date'],
        ], [
            'recommendationAddresseeId.required' => __('Address the recommendation to someone — a colleague or a named body. One addressed to nobody will not be implemented.'),
            'recommendationAddresseeBody.required' => __('Address the recommendation to someone — a colleague or a named body. One addressed to nobody will not be implemented.'),
            'recommendationCost.regex' => __('Enter an amount like 1250000 or 1250000.00.'),
        ], [
            'recommendationTitle' => __('title'),
            'recommendationBody' => __('recommendation'),
            'recommendationAddresseeId' => __('addressee'),
            'recommendationAddresseeBody' => __('addressee'),
            'recommendationDueOn' => __('due date'),
        ]);

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $raise($this->evaluation, $actor, [
                'title' => trim($this->recommendationTitle),
                'body' => trim($this->recommendationBody),
                'addressee_id' => $this->recommendationAddresseeId === '' ? null : (int) $this->recommendationAddresseeId,
                'addressee_body' => trim($this->recommendationAddresseeBody) ?: null,
                'priority' => $this->recommendationPriority,
                'estimated_cost' => $this->recommendationCost === '' ? null : $this->recommendationCost,
                'timeline' => trim($this->recommendationTimeline) ?: null,
                'due_on' => $this->recommendationDueOn ?: null,
            ]);
        } catch (EvaluationRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->cancelRecommendation();

        unset($this->recommendations);

        session()->flash('status', __('Recommendation raised. It is now on the follow-up register and will be chased if its date passes.'));
    }

    /* ---------------------------------------------------------------- */
    /* The report artifact */
    /* ---------------------------------------------------------------- */

    /**
     * The evaluation report as a PDF, from the white-label template in
     * resources/views/pdf. Approved findings only: a draft rendered as a
     * signed-looking artifact is a document that will be forwarded as one.
     *
     * Authorization is repeated here because this is a network-callable
     * method, and what it produces leaves the platform as a file.
     */
    public function downloadReport(): StreamedResponse
    {
        $this->authorize('view', $this->evaluation);

        abort_unless($this->canDownloadReport(), 403);

        $this->evaluation->loadMissing([
            'sections', 'criterionScores', 'teamMembers.user:id,name',
            'project:id,title,reference', 'approvedBy:id,name', 'tenant',
        ]);

        $pdf = Pdf::loadView('pdf.evaluation-report', [
            'evaluation' => $this->evaluation,
            'scoreMax' => $this->scoreMax(),
            'recommendations' => $this->recommendations(),
            'generatedAt' => Carbon::now(),
        ])->setPaper('a4');

        $filename = 'evaluation-'.$this->evaluation->ulid.'.pdf';

        return response()->streamDownload(
            fn () => print $pdf->output(),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /* ---------------------------------------------------------------- */

    private function refresh(string $message): void
    {
        // Re-queried through the model, not fresh(): fresh() is
        // newQueryWithoutScopes(), so it reloads the row with the TenantScope
        // OFF — an unscoped read in tenant-surface code. firstOrFail() under
        // the scope fails closed instead.
        $this->evaluation = Evaluation::query()
            ->with(['sections', 'criterionScores', 'teamMembers.user:id,name', 'project:id,ulid,title,reference'])
            ->whereKey($this->evaluation->getKey())
            ->firstOrFail();

        $this->hydrateForms();

        unset($this->timeline, $this->recommendations);

        session()->flash('status', $message);
    }

    public function render(): View
    {
        // Eager-loaded here rather than in mount(): route-model binding hands
        // over a bare model, and lazy loading is prevented outside production.
        $this->evaluation->loadMissing([
            'project:id,ulid,title,reference,physical_progress',
            'sections',
            'criterionScores',
            'teamMembers.user:id,name',
            'createdBy:id,name',
            'submittedBy:id,name',
            'approvedBy:id,name',
            'publishedBy:id,name',
        ]);

        return view('livewire.tenant.evaluation.evaluation-detail');
    }
}
