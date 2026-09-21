<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Publishing;

use App\Actions\Oversight\ListPublishingCandidatesAcrossTenants;
use App\Actions\Projects\UnpublishProject;
use App\Actions\Publishing\PublishProjectToPortal;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Exceptions\Publishing\PublishingRuleViolation;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Publishing\PublicProjectPayload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The state-wide publishing gate: what the public can see, and what is waiting
 * on a decision.
 *
 * Three things this screen does that a plain list would not:
 *
 *  1. PREVIEW THE EXACT PAYLOAD. "Publish" is only a meaningful decision if
 *     the person taking it can see precisely what becomes public — so the
 *     preview renders PublicProjectPayload, the same whitelist the portal
 *     renders, field by field. Not a mock-up of it: the thing itself.
 *  2. REQUIRE A REASON TO WITHDRAW. Publishing is editorial; unpublishing is
 *     usually an incident, and "why did the state take this down" is the only
 *     question that follows.
 *  3. RE-READ EVERY TARGET. A publish or withdraw never acts on a ULID the
 *     browser sent — it re-resolves the project through the candidate query
 *     (which applies both the eligibility rule and the oversight permission)
 *     and acts on that. A stale tab cannot publish a project that has since
 *     been cancelled.
 *
 * The cross-MDA read lives in app/Actions/Oversight/, next to the permission
 * check that justifies it; this component never bypasses tenancy itself.
 */
#[Layout('layouts::oversight')]
class PublishingQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' = both, 'published', 'unpublished'. */
    #[Url(except: '')]
    public string $state = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    /** ULIDs ticked for a bulk decision. */
    /** @var list<string> */
    public array $selected = [];

    /** ULIDs a withdrawal is being confirmed for. */
    /** @var list<string> */
    public array $withdrawing = [];

    public string $reason = '';

    public ?string $previewUlid = null;

    public function mount(): void
    {
        $this->authorizeSurface();
    }

    /**
     * Deciding what the public sees is state publishing authority, held in the
     * GLOBAL permission team. The oversight role middleware lets
     * ExecutiveViewer and DataQualityReviewer onto this surface; neither of
     * them publishes anything.
     */
    private function authorizeSurface(): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('projects.publish'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'state', 'tenantId']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->state !== '' || $this->tenantId !== '';
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'search' => $this->search === '' ? null : $this->search,
            'state' => $this->state,
            'tenant' => $this->tenantId !== ''
                ? $this->tenants()->firstWhere('id', (int) $this->tenantId)
                : null,
        ];
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListPublishingCandidatesAcrossTenants)($user, $this->filters());
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /**
     * The exact payload a publication would expose — built by the same class
     * the portal renders from, so the preview cannot drift from reality.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function preview(): ?array
    {
        if ($this->previewUlid === null) {
            return null;
        }

        $project = $this->resolve($this->previewUlid);

        return $project instanceof Project
            ? PublicProjectPayload::for($project)->toArray()
            : null;
    }

    public function showPreview(string $ulid): void
    {
        $this->authorizeSurface();
        $this->previewUlid = $ulid;
        $this->dispatch('open-modal', 'publish-preview');
    }

    public function closePreview(): void
    {
        $this->previewUlid = null;
        $this->dispatch('close-modal', 'publish-preview');
    }

    /**
     * Publish one project, or everything ticked. No reason required: the act
     * is already on the audit trail with its actor, and the portal itself is
     * the evidence.
     */
    public function publish(?string $ulid = null): void
    {
        $this->authorizeSurface();

        /** @var User $user */
        $user = auth()->user();

        $targets = $ulid === null ? $this->selected : [$ulid];
        $published = 0;

        foreach ($targets as $target) {
            $project = $this->resolve($target);

            if (! $project instanceof Project || $project->published_at !== null) {
                continue;
            }

            try {
                (new PublishProjectToPortal)($project, $user);
                $published++;
            } catch (PublishingRuleViolation|ProjectRuleViolation $violation) {
                $this->addError('publishing', $violation->getMessage());
            }
        }

        $this->selected = [];
        $this->resetPage();
        unset($this->projects);

        if ($published > 0) {
            session()->flash('status', trans_choice(
                '{1} Project published — it is live on the public portal now.'
                    .'|[2,*] :count projects published — they are live on the public portal now.',
                $published,
                ['count' => $published],
            ));
        }
    }

    /** Open the withdrawal modal for one project, or for the whole selection. */
    public function confirmWithdraw(?string $ulid = null): void
    {
        $this->authorizeSurface();

        $this->withdrawing = $ulid === null ? $this->selected : [$ulid];
        $this->reason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'confirm-withdraw');
    }

    public function withdraw(): void
    {
        $this->authorizeSurface();

        $this->validate(
            ['reason' => ['required', 'string', 'min:10', 'max:1000']],
            [],
            ['reason' => __('reason')],
        );

        /** @var User $user */
        $user = auth()->user();

        $withdrawn = 0;

        foreach ($this->withdrawing as $target) {
            $project = $this->resolve($target);

            if (! $project instanceof Project || $project->published_at === null) {
                continue;
            }

            (new UnpublishProject)($project, $user, $this->reason);
            $withdrawn++;
        }

        $this->withdrawing = [];
        $this->selected = [];
        $this->reason = '';
        $this->dispatch('close-modal', 'confirm-withdraw');
        $this->resetPage();
        unset($this->projects);

        if ($withdrawn > 0) {
            session()->flash('status', trans_choice(
                '{1} Project withdrawn from the public portal.'
                    .'|[2,*] :count projects withdrawn from the public portal.',
                $withdrawn,
                ['count' => $withdrawn],
            ));
        }
    }

    /**
     * Re-read a target through the candidate query rather than trusting the
     * ULID the browser sent. The Action re-checks the oversight permission on
     * the way in, so a component property can never widen what is reachable.
     */
    private function resolve(string $ulid): ?Project
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListPublishingCandidatesAcrossTenants)->find($user, $ulid);
    }

    public function render(): View
    {
        return view('livewire.oversight.publishing.publishing-queue');
    }
}
