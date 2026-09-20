<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Actions\Reporting\ApproveProgressReport;
use App\Actions\Reporting\ReturnProgressReport;
use App\Actions\Reporting\ReviewProgressReport;
use App\Enums\ProgressReportStatus;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Exceptions\Reporting\InvalidReportTransition;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\ProgressReport;
use App\Models\ProgressReportEvent;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The reviewer's and director's screen (progress-reporting.md §6): the return
 * on the left, the decision on the right, the chain underneath.
 *
 * The screen offers only what the actor may actually do — and every method
 * re-checks anyway, because a Livewire endpoint is a public endpoint and
 * hiding a button is a courtesy, not a control. The SEPARATION rules
 * (reviewer ≠ submitter, approver ≠ reviewer) are domain rules owned by
 * TransitionProgressReportStatus; here they only decide whether the button is
 * rendered, and when the Action refuses anyway its own words are shown.
 *
 * The chain timeline reads `progress_report_events` — the append-only history —
 * rather than the report's own *_by_id columns, because a report returned twice
 * has two return events and one `returned_at`.
 */
#[Layout('layouts::tenant')]
class ReportReview extends Component
{
    public ProgressReport $report;

    /** Reason for sending the return back. Required — the author must know what to fix. */
    public string $returnReason = '';

    public bool $returning = false;

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(ProgressReport $report): void
    {
        $this->authorize('view', $report);

        $this->report = $report;
    }

    /* ---------------------------------------------------------------- */
    /* What this actor may do */
    /* ---------------------------------------------------------------- */

    /**
     * A reviewer's button appears when the return is waiting for review, they
     * hold the permission, and they are not the person who filed it. That last
     * clause is the on-behalf guard made visible: an officer who typed a
     * contractor's return must not be offered the button that clears it.
     */
    public function canReview(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->report->status === ProgressReportStatus::Submitted
            && $user->can('review', $this->report)
            && $this->report->submitted_by_id !== $user->id;
    }

    public function canApprove(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->report->status === ProgressReportStatus::Reviewed
            && $user->can('approve', $this->report)
            && $this->report->submitted_by_id !== $user->id
            && ! $this->reviewerWouldApproveTheirOwnReview();
    }

    /** Whether the separation rule blocks THIS user from approving. */
    public function reviewerWouldApproveTheirOwnReview(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->report->reviewed_by_id === $user->id
            && app(SettingsRepository::class)->bool('reporting', 'require_separate_approver', true);
    }

    /** Returning is open to whoever owns the current step of the chain. */
    public function canReturn(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return match ($this->report->status) {
            ProgressReportStatus::Submitted => $user->can('review', $this->report),
            ProgressReportStatus::Reviewed => $user->can('approve', $this->report),
            default => false,
        };
    }

    public function canEdit(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->can('update', $this->report);
    }

    /**
     * Why the decision panel is empty, when it is. An action panel that simply
     * disappears reads as a bug; naming the reason is what turns a dead end
     * into an explanation.
     */
    public function blockedReason(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        if ($this->report->status === ProgressReportStatus::Approved) {
            return __('This return has been approved. Its figures are on the project record and the chain is closed.');
        }

        if ($this->report->status === ProgressReportStatus::Draft) {
            return __('This return is still a draft with its author. It reaches you when they file it.');
        }

        if ($this->report->status === ProgressReportStatus::Returned) {
            return __('This return has been sent back for correction. It reaches you again when the author refiles it.');
        }

        if ($this->report->submitted_by_id === $user->id) {
            return __('You filed this return, so you cannot also clear it. It needs a second pair of eyes.');
        }

        if ($this->report->status === ProgressReportStatus::Reviewed && $this->reviewerWouldApproveTheirOwnReview()) {
            return __('You reviewed this return, so approval needs someone else. Ask another director to sign it off.');
        }

        if (! $this->canReview() && ! $this->canApprove() && ! $this->canReturn()) {
            return __('You have read access to this return, but no step of the approval chain is yours.');
        }

        return null;
    }

    /* ---------------------------------------------------------------- */
    /* The decisions */
    /* ---------------------------------------------------------------- */

    public function review(): void
    {
        $this->authorize('review', $this->report);
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            app(ReviewProgressReport::class)($this->report, $actor);
        } catch (ReportRuleViolation|InvalidReportTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refreshReport(__('Return marked as reviewed. It is now with the approving director.'));
    }

    public function approve(): void
    {
        $this->authorize('approve', $this->report);
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            app(ApproveProgressReport::class)($this->report, $actor);
        } catch (ReportRuleViolation|InvalidReportTransition|ProjectRuleViolation $exception) {
            // ProjectRuleViolation is caught deliberately: approval propagates
            // through the projects chokepoint, and that Action has guards of
            // its own (a certified project, a figure out of range). Its refusal
            // is the honest thing to show.
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refreshReport(__('Return approved. The project record now carries these figures.'));
    }

    public function startReturn(): void
    {
        abort_unless($this->canReturn(), 403);

        $this->returning = true;
    }

    public function cancelReturn(): void
    {
        $this->returning = false;
        $this->returnReason = '';
        $this->resetValidation();
    }

    public function confirmReturn(): void
    {
        abort_unless($this->canReturn(), 403);

        $this->validate([
            'returnReason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'returnReason.required' => __('Say what needs fixing. An author told only “returned” cannot act on it.'),
            'returnReason.min' => __('Give the author enough to work with — a few words at least.'),
        ], [
            'returnReason' => __('reason'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            app(ReturnProgressReport::class)($this->report, $actor, $this->returnReason);
        } catch (ReportRuleViolation|InvalidReportTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->returning = false;
        $this->returnReason = '';

        $this->refreshReport(__('Return sent back to its author with your reason.'));
    }

    private function refreshReport(string $message): void
    {
        // Re-queried through the model, not fresh(): fresh() is
        // newQueryWithoutScopes(), so it reloads the row with the TenantScope
        // OFF — an unscoped read in tenant-surface code. firstOrFail() under
        // the scope fails closed instead. (Enforced by the discipline sweep.)
        $this->report = ProgressReport::query()
            ->with(['project', 'reportingPeriod', 'submittedBy', 'reviewedBy', 'approvedBy'])
            ->whereKey($this->report->getKey())
            ->firstOrFail();

        unset($this->chain);

        session()->flash('status', $message);
    }

    /* ---------------------------------------------------------------- */
    /* Reads */
    /* ---------------------------------------------------------------- */

    /**
     * The append-only chain history — who did what, when, and why. Read from
     * the ledger rather than the report's own columns: those hold the CURRENT
     * state and cannot express a return that happened twice.
     *
     * @return Collection<int, ProgressReportEvent>
     */
    #[Computed]
    public function chain(): Collection
    {
        return ProgressReportEvent::query()
            ->where('progress_report_id', $this->report->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    public function render(): View
    {
        // Eager-loaded here rather than in mount(): route-model binding hands
        // over a bare model, and lazy loading is prevented outside production.
        $this->report->loadMissing([
            'project:id,ulid,title,reference,physical_progress,expenditure_to_date',
            'reportingPeriod:id,code,label,due_at',
            'contractor:id,name',
            'createdBy:id,name',
            'submittedBy:id,name',
            'reviewedBy:id,name',
            'approvedBy:id,name',
        ]);

        return view('livewire.tenant.reporting.report-review');
    }
}
