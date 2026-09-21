<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Indicators;

use App\Actions\Indicators\TransitionIndicatorReadingStatus;
use App\Actions\Oversight\ListReadingsAwaitingValidation;
use App\Enums\IndicatorReadingStatus;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Exceptions\Indicators\InvalidReadingTransition;
use App\Models\IndicatorReading;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Data Quality Reviewer's queue: every figure any MDA has submitted and
 * nobody has yet checked.
 *
 * The manual gives data quality to a third party — the Bureau of Statistics,
 * whose test all M&E data "should pass" (digest §1, §5) — and this screen is
 * that role's whole working day. Recording a number and clearing it are
 * separate acts by separate people; the Action refuses the overlap even when
 * the reviewer holds every permission on the platform.
 *
 * The cross-MDA read lives in app/Actions/Oversight/ListReadingsAwaitingValidation,
 * which re-checks `oversight.validation.review` in the GLOBAL permission team
 * BEFORE any tenancy bypass. This component never calls withoutTenancy()
 * itself: the bypass belongs with the authorization check that justifies it.
 */
#[Layout('layouts::oversight')]
class ValidationQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $sector = '';

    /** Blank = the queue (submitted only); a value shows a cleared state too. */
    #[Url(except: '')]
    public string $status = '';

    /** The reading a rejection is being written for, and its reason. */
    public string $rejecting = '';

    public string $rejectionReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        // The same permission the Action re-checks. Failing here as well keeps
        // an unauthorized reviewer off the screen instead of showing them an
        // empty page that throws the moment it loads.
        abort_unless($user->holdsGlobalPermission('oversight.validation.review'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'sector', 'status']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantId !== '' || $this->sector !== '' || $this->status !== '';
    }

    /** @return LengthAwarePaginator<int, IndicatorReading> */
    #[Computed]
    public function readings(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListReadingsAwaitingValidation)($user, [
            'tenant' => $this->tenantId === '' ? null : $this->tenants()->firstWhere('id', (int) $this->tenantId),
            'sector' => $this->sector === '' ? null : $this->sectors()->firstWhere('id', (int) $this->sector),
            'search' => $this->search === '' ? null : $this->search,
            'status' => $this->status === '' ? null : IndicatorReadingStatus::tryFrom($this->status),
        ]);
    }

    /** @return array{waiting: int, mdas: int} */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $waiting = (new ListReadingsAwaitingValidation)->count($user);

        return [
            'waiting' => $waiting,
            'mdas' => $this->tenants()->count(),
        ];
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(IndicatorReadingStatus::cases())
            ->mapWithKeys(fn (IndicatorReadingStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /* ---------------------------------------------------------------- */
    /* Decisions */
    /* ---------------------------------------------------------------- */

    /**
     * Clears a figure for use. Every guard that matters — the chain table, the
     * authority, and above all "the person who recorded or filed this cannot
     * be the person clearing it" — is asserted inside
     * TransitionIndicatorReadingStatus. This method only stops a doomed round
     * trip and shows the refusal in the reviewer's own words.
     */
    public function validateReading(string $ulid, TransitionIndicatorReadingStatus $transition): void
    {
        $this->decide($ulid, IndicatorReadingStatus::Validated, $transition, null,
            __('Figure validated. It may now be quoted in reports and published.'));
    }

    public function publishReading(string $ulid, TransitionIndicatorReadingStatus $transition): void
    {
        $this->decide($ulid, IndicatorReadingStatus::Published, $transition, null,
            __('Figure published. It is now quotable outside the platform.'));
    }

    public function startRejection(string $ulid): void
    {
        $this->resetErrorBag();
        $this->failure = null;
        $this->rejectionReason = '';
        $this->rejecting = $ulid;

        $this->dispatch('open-modal', 'reject-reading');
    }

    public function cancelRejection(): void
    {
        $this->rejecting = '';
        $this->rejectionReason = '';
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'reject-reading');
    }

    /**
     * Sends a figure back to the MDA that measured it. The reason is not a
     * formality: it is the only thing standing between "the sample frame was
     * the wrong one" and an unexplained rejection, and it is what the recorder
     * reads when the figure lands back in their drafts.
     */
    public function confirmRejection(TransitionIndicatorReadingStatus $transition): void
    {
        $this->validate([
            'rejectionReason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'rejectionReason.required' => __('Say what is wrong with the figure. A rejection with no reason cannot be acted on.'),
            'rejectionReason.min' => __('Give the person who measured it something to work with — a sentence at least.'),
        ], [
            'rejectionReason' => __('reason'),
        ]);

        $this->decide(
            $this->rejecting,
            IndicatorReadingStatus::Draft,
            $transition,
            $this->rejectionReason,
            __('Figure sent back to the entity that recorded it, with your reason.'),
        );

        $this->rejecting = '';
        $this->rejectionReason = '';
        $this->dispatch('close-modal', 'reject-reading');
    }

    private function decide(
        string $ulid,
        IndicatorReadingStatus $to,
        TransitionIndicatorReadingStatus $transition,
        ?string $reason,
        string $success,
    ): void {
        $reading = $this->queuedReading($ulid);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $transition($reading, $to, $actor, $reason);
        } catch (IndicatorRuleViolation|InvalidReadingTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        unset($this->readings, $this->stats);

        session()->flash('status', $success);
    }

    /**
     * The reading a decision names, loaded across MDAs — the only way an
     * oversight reviewer can reach it, since no tenant is bound on this
     * surface. Authority for the decision itself is asserted again inside the
     * transition Action, against a Policy that reads the permission from the
     * GLOBAL team.
     */
    private function queuedReading(string $ulid): IndicatorReading
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.validation.review'), 403);

        return app(CurrentTenant::class)->bypass(
            fn (): IndicatorReading => IndicatorReading::query()
                ->with('indicator:id,ulid,name,unit')
                ->where('ulid', $ulid)
                ->firstOrFail(),
        );
    }

    public function render(): View
    {
        return view('livewire.oversight.indicators.validation-queue');
    }
}
