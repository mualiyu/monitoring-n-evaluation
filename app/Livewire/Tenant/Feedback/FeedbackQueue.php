<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Feedback;

use App\Actions\Feedback\ListFeedbackForTenant;
use App\Actions\Feedback\ModerateFeedback;
use App\Actions\Feedback\RespondToFeedback;
use App\Enums\FeedbackStatus;
use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The MDA's moderation desk: what the public said about THIS workspace's
 * projects, and what the workspace is going to do about it.
 *
 * The tenancy narrowing is not in this class at all — ListFeedbackForTenant
 * narrows through the `project` relation, whose TenantScope confines the
 * subquery to the resolved workspace. There is no tenant clause here and there
 * must never be one: `feedback` is a global table, and the day someone adds a
 * `where` here is the day another MDA's complaints become reachable by ULID.
 *
 * Two decisions the screen makes deliberately:
 *
 *  1. PUBLISHING IS ONE CLICK, REFUSING IS NOT. Publishing a citizen's comment
 *     needs no justification — it is the default promise of a transparency
 *     portal. Refusing to publish one is a decision about a member of the
 *     public and goes on the record with a stated reason, which is why it
 *     travels through a modal and FeedbackStatus::requiresReason().
 *  2. EVERY MUTATING METHOD RE-AUTHORIZES. Route middleware does not gate a
 *     Livewire update POST, and the Actions re-check as well — the check here
 *     exists so an unauthorized click fails before it reaches a write, not
 *     instead of the Action's own guard.
 */
#[Layout('layouts::tenant')]
class FeedbackQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: false)]
    public bool $flagged = false;

    /** The ULID a moderation decision is being confirmed for. */
    public string $moderating = '';

    /** The FeedbackStatus value that decision would move it to. */
    public string $moderatingTo = '';

    public string $reason = '';

    /** The ULID a response is being written on. */
    public string $responding = '';

    public string $responseBody = '';

    public bool $responsePublic = true;

    /** An Action's own refusal, shown verbatim rather than paraphrased. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Feedback::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFlagged(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'flagged']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->flagged;
    }

    /** @return LengthAwarePaginator<int, Feedback> */
    #[Computed]
    public function feedback(): LengthAwarePaginator
    {
        return (new ListFeedbackForTenant)($this->actor(), [
            'status' => $this->status === '' ? null : FeedbackStatus::tryFrom($this->status),
            'search' => $this->search === '' ? null : $this->search,
            'flagged' => $this->flagged,
        ]);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return (new ListFeedbackForTenant)->counts($this->actor());
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return FeedbackStatus::options();
    }

    /* ---------------------------------------------------------------- */
    /* Moderation */
    /* ---------------------------------------------------------------- */

    /**
     * Publish a comment. No reason, no modal: showing the public what the
     * public said is the portal doing its job.
     */
    public function publish(string $ulid, ModerateFeedback $moderate): void
    {
        $this->decide($ulid, FeedbackStatus::Published, $moderate, null, __('Published. It is live under the project on the public portal now.'));
    }

    /** Open the confirmation for a decision that needs a stated reason. */
    public function startModeration(string $ulid, string $to): void
    {
        $this->authorizeOn($ulid, 'moderate');

        $this->resetErrorBag();
        $this->failure = null;
        $this->reason = '';
        $this->moderating = $ulid;
        $this->moderatingTo = $to;

        $this->dispatch('open-modal', 'moderate-feedback');
    }

    public function cancelModeration(): void
    {
        $this->moderating = '';
        $this->moderatingTo = '';
        $this->reason = '';
        $this->resetErrorBag();
        $this->dispatch('close-modal', 'moderate-feedback');
    }

    public function confirmModeration(ModerateFeedback $moderate): void
    {
        $this->validate(
            ['reason' => ['required', 'string', 'min:10', 'max:1000']],
            [
                'reason.required' => __('Say why this will not be published. Refusing a citizen’s comment is a decision that goes on the record.'),
                'reason.min' => __('A sentence at least — this is read by anyone auditing how the state handles public feedback.'),
            ],
            ['reason' => __('reason')],
        );

        $to = FeedbackStatus::tryFrom($this->moderatingTo);

        if (! $to instanceof FeedbackStatus) {
            $this->failure = __('That is not a moderation state.');

            return;
        }

        $this->decide(
            $this->moderating,
            $to,
            $moderate,
            $this->reason,
            $to === FeedbackStatus::Spam
                ? __('Marked as spam. It stays on the record, with your reason.')
                : __('Recorded as not published, with your reason.'),
        );

        $this->moderating = '';
        $this->moderatingTo = '';
        $this->reason = '';
        $this->dispatch('close-modal', 'moderate-feedback');
    }

    /* ---------------------------------------------------------------- */
    /* Responses */
    /* ---------------------------------------------------------------- */

    public function startResponse(string $ulid): void
    {
        $this->authorizeOn($ulid, 'respond');

        $this->resetErrorBag();
        $this->failure = null;
        $this->responseBody = '';
        $this->responding = $ulid;
        // A public reply may only hang off published feedback; default the
        // toggle to what the Action would actually accept.
        $this->responsePublic = $this->resolve($ulid)?->isPublic() ?? false;

        $this->dispatch('open-modal', 'respond-feedback');
    }

    public function cancelResponse(): void
    {
        $this->responding = '';
        $this->responseBody = '';
        $this->resetErrorBag();
        $this->dispatch('close-modal', 'respond-feedback');
    }

    public function submitResponse(RespondToFeedback $respond): void
    {
        $this->validate(
            ['responseBody' => ['required', 'string', 'min:10', 'max:4000']],
            [],
            ['responseBody' => __('response')],
        );

        $feedback = $this->authorizeOn($this->responding, 'respond');

        if (! $feedback instanceof Feedback) {
            return;
        }

        $this->failure = null;

        try {
            $respond($feedback, $this->actor(), $this->responseBody, $this->responsePublic);
        } catch (FeedbackRuleViolation $violation) {
            $this->failure = $violation->getMessage();

            return;
        }

        $this->responding = '';
        $this->responseBody = '';
        $this->dispatch('close-modal', 'respond-feedback');
        unset($this->feedback, $this->counts);

        session()->flash('status', $this->responsePublic
            ? __('Reply published under the comment on the portal.')
            : __('Internal note added to the thread.'));
    }

    /* ---------------------------------------------------------------- */

    private function decide(
        string $ulid,
        FeedbackStatus $to,
        ModerateFeedback $moderate,
        ?string $reason,
        string $success,
    ): void {
        $feedback = $this->authorizeOn($ulid, 'moderate');

        if (! $feedback instanceof Feedback) {
            return;
        }

        $this->failure = null;

        try {
            $moderate($feedback, $to, $this->actor(), $reason);
        } catch (FeedbackRuleViolation $violation) {
            $this->failure = $violation->getMessage();

            return;
        }

        unset($this->feedback, $this->counts);

        session()->flash('status', $success);
    }

    /**
     * Re-read the target through the tenant-narrowed query rather than
     * trusting a ULID from the browser, then authorize the ability on THAT
     * record. Another MDA's ULID resolves to nothing here, which is a 404 and
     * not a 403 — confirming the record exists is itself a leak.
     */
    private function authorizeOn(string $ulid, string $ability): ?Feedback
    {
        $feedback = $this->resolve($ulid);

        abort_if($feedback === null, 404);

        $this->authorize($ability, $feedback);

        return $feedback;
    }

    private function resolve(string $ulid): ?Feedback
    {
        return (new ListFeedbackForTenant)->find($this->actor(), $ulid);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.tenant.feedback.feedback-queue');
    }
}
