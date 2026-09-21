<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Issues;

use App\Actions\Iam\ListTenantMembers;
use App\Actions\Issues\AssignIssue;
use App\Actions\Issues\RecordCorrectiveAction;
use App\Actions\Issues\TransitionIssueStatus;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Exceptions\Issues\InvalidIssueTransition;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One challenge: what it is, who owns it, what is being done, and the
 * append-only history of how it got here.
 *
 * The screen offers only what the actor may actually do — and every method
 * re-authorizes anyway, because a Livewire endpoint is a public endpoint and
 * hiding a button is a courtesy, not a control.
 *
 * The timeline reads `issue_events`, the append-only ledger, rather than the
 * issue's own *_by_id columns: an issue resolved, reopened and resolved again
 * has two resolve events and one `resolved_at`, and the second resolution is
 * exactly the one somebody will ask about.
 *
 * Escalation is deliberately absent from the controls. It is the threshold
 * engine's rung and no human's (TransitionIssueStatus::assertActor), so there
 * is no button to render.
 *
 * Livewire resolves a #[Computed] method as a property, with caching; these
 * annotations are what let static analysis see that. They mirror the methods
 * below — keep them in step.
 *
 * @property-read list<IssueStatus> $availableTransitions
 * @property-read EloquentCollection<int, IssueEvent> $timeline
 * @property-read array<int, string> $ownerOptions
 */
#[Layout('layouts::tenant')]
class IssueDetail extends Component
{
    public Issue $issue;

    /* Corrective-action form */
    public string $correctiveAction = '';

    public string $dueDate = '';

    public string $severity = '';

    /* Owner */
    public string $ownerId = '';

    /* The transition being confirmed, and its reason. */
    public ?string $pendingStatus = null;

    public string $reason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Issue $issue): void
    {
        $this->authorize('view', $issue);

        $this->issue = $issue;
        $this->syncForm();
    }

    private function syncForm(): void
    {
        $this->correctiveAction = (string) $this->issue->corrective_action;
        $this->dueDate = $this->issue->due_date?->toDateString() ?? '';
        $this->severity = $this->issue->severity->value;
        $this->ownerId = (string) ($this->issue->owner_id ?? '');
    }

    /* ---------------------------------------------------------------- */
    /* What this actor may do */
    /* ---------------------------------------------------------------- */

    public function canUpdate(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->issue->status->isEditable() && $user->can('update', $this->issue);
    }

    /**
     * The lifecycle moves offered, each one already checked against the
     * lifecycle table AND this actor's authority — so a button that is
     * rendered is a button that will work.
     *
     * @return list<IssueStatus>
     */
    #[Computed]
    public function availableTransitions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $available = [];

        foreach ($this->issue->status->allowedTransitions() as $target) {
            // Escalation is the engine's; never offered to a person.
            if ($target === IssueStatus::Escalated) {
                continue;
            }

            $ability = match ($target) {
                IssueStatus::Resolved => 'resolve',
                IssueStatus::Closed => 'close',
                default => 'update',
            };

            if ($user->can($ability, $this->issue)) {
                $available[] = $target;
            }
        }

        return $available;
    }

    /** Whether the move being confirmed must carry a reason. */
    public function reasonRequired(): bool
    {
        if ($this->pendingStatus === null) {
            return false;
        }

        $target = IssueStatus::from($this->pendingStatus);

        return $target === IssueStatus::Resolved
            || ($target === IssueStatus::Closed && $this->issue->status !== IssueStatus::Resolved);
    }

    /**
     * Why the action panel is empty, when it is. A panel that simply
     * disappears reads as a bug; naming the reason turns a dead end into an
     * explanation.
     */
    public function blockedReason(): ?string
    {
        if ($this->issue->status === IssueStatus::Closed) {
            return __('This issue is closed. The register has stopped chasing it and its record is final.');
        }

        if ($this->availableTransitions === []) {
            return __('You have read access to this issue, but no step of its lifecycle is yours to take.');
        }

        return null;
    }

    /* ---------------------------------------------------------------- */
    /* Corrective action + owner */
    /* ---------------------------------------------------------------- */

    public function saveCorrectiveAction(RecordCorrectiveAction $record): void
    {
        $this->authorize('update', $this->issue);

        $validated = $this->validate([
            'correctiveAction' => ['nullable', 'string', 'max:2000'],
            'dueDate' => ['nullable', 'date'],
            'severity' => ['required', Rule::enum(IssueSeverity::class)],
        ], [
            'dueDate.date' => __('Give the deadline as a date.'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $record($this->issue, $actor, [
                'corrective_action' => $validated['correctiveAction'],
                'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
                'severity' => IssueSeverity::from($validated['severity']),
            ]);
        } catch (IssueRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refreshIssue(__('Corrective action recorded.'));
    }

    public function saveOwner(AssignIssue $assign): void
    {
        $this->authorize('update', $this->issue);

        $validated = $this->validate([
            'ownerId' => ['nullable', 'integer', Rule::in($this->ownerIds())],
        ], [
            'ownerId.in' => __('An issue can only be assigned to someone who works in this entity.'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $owner = $validated['ownerId'] === '' || $validated['ownerId'] === null
                ? null
                : User::query()->findOrFail((int) $validated['ownerId']);

            $assign($this->issue, $owner, $actor);
        } catch (IssueRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refreshIssue(__('Owner updated. They have been told it is theirs.'));
    }

    /* ---------------------------------------------------------------- */
    /* The lifecycle */
    /* ---------------------------------------------------------------- */

    public function startTransition(string $status): void
    {
        $target = IssueStatus::from($status);

        abort_unless(in_array($target, $this->availableTransitions, true), 403);

        $this->resetErrorBag();
        $this->failure = null;
        $this->reason = '';
        $this->pendingStatus = $status;

        $this->dispatch('open-modal', 'issue-transition');
    }

    public function cancelTransition(): void
    {
        $this->pendingStatus = null;
        $this->reason = '';
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'issue-transition');
    }

    public function confirmTransition(TransitionIssueStatus $transition): void
    {
        $target = IssueStatus::from((string) $this->pendingStatus);

        // Re-derived from the record, not trusted from the payload: the list
        // of available moves is computed against the CURRENT status and this
        // actor's authority, so a stale button cannot take a move the record
        // has since made impossible.
        abort_unless(in_array($target, $this->availableTransitions, true), 403);

        if ($this->reasonRequired()) {
            $this->validate([
                'reason' => ['required', 'string', 'min:10', 'max:2000'],
            ], [
                'reason.required' => $target === IssueStatus::Resolved
                    ? __('Say what was actually done about it. A register of issues that were "resolved" with no account of how is one nobody can learn from.')
                    : __('Say why this is being closed without being resolved — raised in error, duplicate, overtaken by events.'),
                'reason.min' => __('Give the reader something to work with — a few words at least.'),
            ], [
                'reason' => __('reason'),
            ]);
        }

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $transition($this->issue, $target, $actor, $this->reason === '' ? null : $this->reason);
        } catch (IssueRuleViolation|InvalidIssueTransition $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'issue-transition');

            return;
        }

        $this->pendingStatus = null;
        $this->reason = '';

        $this->dispatch('close-modal', 'issue-transition');
        $this->refreshIssue(__('Issue updated. The change is on its timeline.'));
    }

    private function refreshIssue(string $message): void
    {
        // Re-queried through the model, not fresh(): fresh() is
        // newQueryWithoutScopes(), so it reloads the row with the TenantScope
        // OFF — an unscoped read in tenant-surface code. firstOrFail() under
        // the scope fails closed instead.
        $this->issue = Issue::query()
            ->with(['project', 'owner', 'raisedBy'])
            ->whereKey($this->issue->getKey())
            ->firstOrFail();

        $this->syncForm();
        unset($this->timeline, $this->availableTransitions);

        session()->flash('status', $message);
    }

    /* ---------------------------------------------------------------- */
    /* Reads */
    /* ---------------------------------------------------------------- */

    /**
     * The append-only history — who did what, when, and why.
     *
     * @return EloquentCollection<int, IssueEvent>
     */
    #[Computed]
    public function timeline(): EloquentCollection
    {
        return IssueEvent::query()
            ->where('issue_id', $this->issue->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, string> */
    #[Computed]
    public function ownerOptions(): array
    {
        return $this->members()->pluck('name', 'id')->all();
    }

    /** @return list<int> */
    private function ownerIds(): array
    {
        return $this->members()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return Collection<int, User> */
    private function members(): Collection
    {
        return (new ListTenantMembers)()
            ->filter(fn (TenantMembership $membership): bool => $membership->isActive())
            ->map(fn (TenantMembership $membership): ?User => $membership->user)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /** @return array<string, string> */
    public function severityOptions(): array
    {
        return IssueSeverity::options();
    }

    public function render(): View
    {
        // Eager-loaded here rather than in mount(): route-model binding hands
        // over a bare model, and lazy loading is prevented outside production.
        $this->issue->loadMissing([
            'project:id,ulid,title,reference,physical_progress,status',
            'owner:id,name',
            'raisedBy:id,name',
            'resolvedBy:id,name',
            'closedBy:id,name',
        ]);

        return view('livewire.tenant.issues.issue-detail');
    }
}
