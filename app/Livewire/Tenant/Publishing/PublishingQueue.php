<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Publishing;

use App\Actions\Projects\UnpublishProject;
use App\Actions\Publishing\ListPublishingCandidates;
use App\Actions\Publishing\PublishProjectToPortal;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Exceptions\Publishing\PublishingRuleViolation;
use App\Models\Project;
use App\Models\User;
use App\Support\Publishing\PublicProjectPayload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The MDA's own publishing gate: which of THIS workspace's projects the public
 * can see.
 *
 * `projects.publish` is seeded to MdaAdmin as well as to the state roles, and
 * without this screen that permission would be unreachable — an MDA would hold
 * the authority to open its own work to the public and no way to use it.
 *
 * The difference from the oversight twin is one line of code and the whole
 * security model: there is no tenancy bypass here. ListPublishingCandidates
 * runs under TenantScope, so the queue contains this workspace's projects and
 * cannot be made to contain anyone else's.
 */
#[Layout('layouts::tenant')]
class PublishingQueue extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' = both, 'published', 'unpublished'. */
    #[Url(except: '')]
    public string $state = '';

    /** @var list<string> */
    public array $selected = [];

    /** @var list<string> */
    public array $withdrawing = [];

    public string $reason = '';

    public ?string $previewUlid = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'state']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->state !== '';
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListPublishingCandidates)($user, [
            'search' => $this->search === '' ? null : $this->search,
            'state' => $this->state,
        ]);
    }

    /**
     * The exact payload a publication would expose, from the same whitelist
     * the portal renders. A preview that is a mock-up of the real thing is
     * how a field nobody meant to publish gets published.
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
        $this->authorize('viewAny', Project::class);
        $this->previewUlid = $ulid;
        $this->dispatch('open-modal', 'publish-preview');
    }

    public function closePreview(): void
    {
        $this->previewUlid = null;
        $this->dispatch('close-modal', 'publish-preview');
    }

    public function publish(?string $ulid = null): void
    {
        /** @var User $user */
        $user = auth()->user();

        $targets = $ulid === null ? $this->selected : [$ulid];
        $published = 0;

        foreach ($targets as $target) {
            $project = $this->resolve($target);

            if (! $project instanceof Project || $project->published_at !== null) {
                continue;
            }

            // Authorization is per project and it happens inside
            // PublishProject — route middleware does not gate a Livewire
            // update POST, and a class-level check would not answer "this
            // project" anyway.
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

    public function confirmWithdraw(?string $ulid = null): void
    {
        $this->authorize('viewAny', Project::class);

        $this->withdrawing = $ulid === null ? $this->selected : [$ulid];
        $this->reason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'confirm-withdraw');
    }

    public function withdraw(): void
    {
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
     * Re-read the target through the candidate query rather than trusting a
     * ULID from the browser. TenantScope is what makes another MDA's ULID
     * resolve to nothing here.
     */
    private function resolve(string $ulid): ?Project
    {
        return ListPublishingCandidates::query()->where('ulid', $ulid)->first();
    }

    public function render(): View
    {
        return view('livewire.tenant.publishing.publishing-queue');
    }
}
