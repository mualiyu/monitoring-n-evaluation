<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Issues;

use App\Actions\Issues\RaiseIssueFromException;
use App\Actions\Issues\TransitionExceptionStatus;
use App\Enums\ExceptionStatus;
use App\Enums\IssueCategory;
use App\Exceptions\Issues\InvalidExceptionTransition;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Models\ExceptionReport;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One deviation: the measurement, the tolerance it tripped, and what the MDA
 * is doing about it.
 *
 * THE MEASUREMENT IS THE SCREEN. Everything else — the status controls, the
 * link to an issue — hangs off the question "is this real?", and that question
 * is only answerable because the record stored every figure that went into the
 * judgement rather than re-deriving them from a project whose numbers have
 * since moved.
 *
 * Turning a deviation into work somebody owns is the one mutation here that
 * creates a record elsewhere, and it goes through RaiseIssueFromException —
 * the single path that may write `issue_id`.
 */
#[Layout('layouts::tenant')]
class ExceptionDetail extends Component
{
    public ExceptionReport $exceptionReport;

    /** The transition being confirmed, and its note. */
    public ?string $pendingStatus = null;

    public string $reason = '';

    /* Raise-an-issue form */
    public bool $raisingIssue = false;

    public string $issueTitle = '';

    public string $issueDescription = '';

    public string $issueCategory = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(ExceptionReport $exceptionReport): void
    {
        $this->authorize('view', $exceptionReport);

        $this->exceptionReport = $exceptionReport;
    }

    /* ---------------------------------------------------------------- */
    /* What this actor may do */
    /* ---------------------------------------------------------------- */

    /**
     * @return list<ExceptionStatus>
     */
    #[Computed]
    public function availableTransitions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $available = [];

        foreach ($this->exceptionReport->status->allowedTransitions() as $target) {
            $ability = $target === ExceptionStatus::Resolved ? 'resolve' : 'acknowledge';

            if ($user->can($ability, $this->exceptionReport)) {
                $available[] = $target;
            }
        }

        return $available;
    }

    public function canRaiseIssue(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->exceptionReport->issue_id === null && $user->can('create', Issue::class);
    }

    public function reasonRequired(): bool
    {
        return $this->pendingStatus === ExceptionStatus::Resolved->value;
    }

    /**
     * Why the action panel is empty, when it is — a panel that simply
     * disappears reads as a bug.
     */
    public function blockedReason(): ?string
    {
        if ($this->exceptionReport->status === ExceptionStatus::Resolved) {
            return __('This deviation has been resolved. The record stays on the project for the audit trail.');
        }

        if ($this->availableTransitions === []) {
            return __('You have read access to this report, but answering for the deviation is not yours to do.');
        }

        return null;
    }

    /* ---------------------------------------------------------------- */
    /* The chain */
    /* ---------------------------------------------------------------- */

    public function startTransition(string $status): void
    {
        $target = ExceptionStatus::from($status);

        abort_unless(in_array($target, $this->availableTransitions, true), 403);

        $this->resetErrorBag();
        $this->failure = null;
        $this->reason = '';
        $this->pendingStatus = $status;

        $this->dispatch('open-modal', 'exception-transition');
    }

    public function cancelTransition(): void
    {
        $this->pendingStatus = null;
        $this->reason = '';
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'exception-transition');
    }

    public function confirmTransition(TransitionExceptionStatus $transition): void
    {
        $target = ExceptionStatus::from((string) $this->pendingStatus);

        // Re-derived from the record, not trusted from the payload.
        abort_unless(in_array($target, $this->availableTransitions, true), 403);

        if ($this->reasonRequired()) {
            $this->validate([
                'reason' => ['required', 'string', 'min:10', 'max:2000'],
            ], [
                'reason.required' => __('Say why the deviation no longer stands. A report closed with no account of why is indistinguishable from one closed to clear a board.'),
                'reason.min' => __('Give the reader something to work with — a few words at least.'),
            ], [
                'reason' => __('note'),
            ]);
        }

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $transition($this->exceptionReport, $target, $actor, $this->reason === '' ? null : $this->reason);
        } catch (IssueRuleViolation|InvalidExceptionTransition $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'exception-transition');

            return;
        }

        $this->pendingStatus = null;
        $this->reason = '';

        $this->dispatch('close-modal', 'exception-transition');
        $this->refreshReport(__('Exception report updated.'));
    }

    /* ---------------------------------------------------------------- */
    /* Turning it into work */
    /* ---------------------------------------------------------------- */

    public function startIssue(): void
    {
        abort_unless($this->canRaiseIssue(), 403);

        $this->resetErrorBag();
        $this->failure = null;
        $this->issueTitle = $this->exceptionReport->trigger->label().' — '.$this->exceptionReport->project->title;
        $this->issueDescription = $this->exceptionReport->narrative;
        $this->issueCategory = '';
        $this->raisingIssue = true;
    }

    public function cancelIssue(): void
    {
        $this->raisingIssue = false;
        $this->resetErrorBag();
    }

    public function confirmIssue(RaiseIssueFromException $raise): void
    {
        abort_unless($this->canRaiseIssue(), 403);

        $validated = $this->validate([
            'issueTitle' => ['required', 'string', 'min:5', 'max:255'],
            'issueDescription' => ['required', 'string', 'min:10', 'max:5000'],
            'issueCategory' => ['required', Rule::enum(IssueCategory::class)],
        ], [
            'issueCategory.required' => __('Say what kind of obstruction this is — the category is what makes the register answerable across projects.'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $raise($this->exceptionReport, $actor, [
                'title' => $validated['issueTitle'],
                'description' => $validated['issueDescription'],
                'category' => IssueCategory::from($validated['issueCategory']),
            ]);
        } catch (IssueRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->raisingIssue = false;

        $this->refreshReport(__('Issue raised from this deviation and linked to it.'));
    }

    private function refreshReport(string $message): void
    {
        // Re-queried through the model, not fresh() — see the note in
        // IssueDetail::refreshIssue().
        $this->exceptionReport = ExceptionReport::query()
            ->with(['project', 'issue'])
            ->whereKey($this->exceptionReport->getKey())
            ->firstOrFail();

        unset($this->availableTransitions);

        session()->flash('status', $message);
    }

    /** @return array<string, string> */
    public function categoryOptions(): array
    {
        return IssueCategory::options();
    }

    public function render(): View
    {
        $this->exceptionReport->loadMissing([
            'project:id,ulid,title,reference,physical_progress,status',
            'issue:id,ulid,title,status,severity',
            'raisedBy:id,name',
            'acknowledgedBy:id,name',
            'resolvedBy:id,name',
        ]);

        return view('livewire.tenant.issues.exception-detail');
    }
}
