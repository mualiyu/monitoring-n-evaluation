<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Feedback;

use App\Actions\Feedback\ModerateFeedback;
use App\Actions\Feedback\RespondToFeedback;
use App\Actions\Oversight\ListFeedbackAcrossTenants;
use App\Enums\FeedbackStatus;
use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Models\Feedback;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The secretariat's moderation desk — every MDA's public correspondence on one
 * screen, plus the pile no MDA can see.
 *
 * Feedback submitted without a project attached appears ONLY here. Someone who
 * wrote about "the road by the market" without picking a project from a list
 * still gets read, by the one office that can work out whose road it is. The
 * `unattached` filter exists so that pile can be worked deliberately rather
 * than discovered by scrolling.
 *
 * The cross-MDA read and its tenancy bypass live in
 * App\Actions\Oversight\ListFeedbackAcrossTenants, next to the authorization
 * check that justifies it. This component bypasses nothing itself.
 *
 * The surface gate is `feedback.view` in the GLOBAL permission team: the
 * oversight role middleware admits ExecutiveViewer and DataQualityReviewer,
 * and while they may read the queue, moderating and answering are separately
 * permissioned and re-checked per record by FeedbackPolicy.
 */
#[Layout('layouts::oversight')]
class FeedbackQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: false)]
    public bool $flagged = false;

    /** Comments nobody owns — the state's own pile. */
    #[Url(except: false)]
    public bool $unattached = false;

    public string $moderating = '';

    public string $moderatingTo = '';

    public string $reason = '';

    public string $responding = '';

    public string $responseBody = '';

    public bool $responsePublic = true;

    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorizeSurface();
    }

    /**
     * Reading the state's public correspondence is oversight authority, held
     * in the GLOBAL permission team — an MdaAdmin's workspace role grants
     * nothing here.
     */
    private function authorizeSurface(): void
    {
        abort_unless($this->actor()->holdsGlobalPermission('feedback.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedFlagged(): void
    {
        $this->resetPage();
    }

    public function updatedUnattached(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'tenantId', 'flagged', 'unattached']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->tenantId !== ''
            || $this->flagged || $this->unattached;
    }

    /** @return LengthAwarePaginator<int, Feedback> */
    #[Computed]
    public function feedback(): LengthAwarePaginator
    {
        return (new ListFeedbackAcrossTenants)($this->actor(), [
            'status' => $this->status === '' ? null : FeedbackStatus::tryFrom($this->status),
            'search' => $this->search === '' ? null : $this->search,
            'flagged' => $this->flagged,
            'unattached' => $this->unattached,
            'tenant' => $this->tenantId === ''
                ? null
                : $this->tenants()->firstWhere('id', (int) $this->tenantId),
        ]);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return (new ListFeedbackAcrossTenants)->counts($this->actor());
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']);
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

    public function publish(string $ulid, ModerateFeedback $moderate): void
    {
        $this->decide($ulid, FeedbackStatus::Published, $moderate, null, __('Published. It is live under the project on the public portal now.'));
    }

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

    private function authorizeOn(string $ulid, string $ability): Feedback
    {
        $this->authorizeSurface();

        $feedback = $this->resolve($ulid);

        abort_if($feedback === null, 404);

        $this->authorize($ability, $feedback);

        return $feedback;
    }

    private function resolve(string $ulid): ?Feedback
    {
        return (new ListFeedbackAcrossTenants)->find($this->actor(), $ulid);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.feedback.feedback-queue');
    }
}
