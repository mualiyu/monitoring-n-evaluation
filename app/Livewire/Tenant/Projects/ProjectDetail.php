<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Actions\Iam\ListTenantMembers;
use App\Actions\Projects\AssignProjectMember;
use App\Actions\Projects\TransitionProjectStatus;
use App\Actions\Projects\UnassignProjectMember;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Exceptions\Projects\InvalidStatusTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Project record (design §5): overview · contracts · team · indicators ·
 * documents, plus the status machine.
 *
 * Every mutation delegates to an Action, which re-authorizes and enforces the
 * domain rule independently — the checks here decide what to *render*, never
 * what is *allowed*. Both halves are tested.
 *
 * Contracts are READ here and written on their own screens
 * (`/projects/{project}/contracts/create`, `…/contracts/{contract}`): an award
 * and a variation each need more of the record than a modal can ask for
 * without lying about what is optional, and the variation path needs the
 * contract it amends in front of the officer while they fill it in.
 */
#[Layout('layouts::tenant')]
class ProjectDetail extends Component
{
    public Project $project;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public const TABS = ['overview', 'contracts', 'team', 'indicators', 'documents'];

    // Status transition
    public ?string $pendingStatus = null;

    public string $transitionReason = '';

    // Team
    public string $assigneeId = '';

    public string $assigneeRole = 'field_monitor';

    public ?string $failure = null;

    /**
     * Route-model bound on `ulid` (Project::getRouteKeyName), so a project from
     * another workspace 404s at the binder — the TenantScope is applied before
     * this component is ever constructed.
     */
    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;

        if (! in_array($this->tab, $this->visibleTabs, true)) {
            $this->tab = 'overview';
        }
    }

    /**
     * The tabs THIS user may open.
     *
     * Contract sums are not open to the field roles: `contracts.view` is an
     * MDA-staff and oversight permission, so a consultant who delivers a
     * project still does not get to read what the state is paying for it.
     * Hiding the tab is only half of it — ContractPolicy refuses the contract
     * screens to the same people, and the `contracts` computed below is the
     * only other way this data reaches a page.
     *
     * @return list<string>
     */
    #[Computed]
    public function visibleTabs(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return array_values(array_filter(self::TABS, fn (string $tab): bool => match ($tab) {
            'contracts' => $user->can('viewAny', Contract::class),
            'documents' => $user->can('documents.view') || $user->holdsGlobalPermission('documents.view'),
            default => true,
        }));
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, $this->visibleTabs, true)) {
            $this->tab = $tab;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Status machine */
    /* ------------------------------------------------------------------ */

    /**
     * Transitions this user may actually perform: the enum decides what is
     * reachable, the policy decides who may do it. A button nobody can press
     * is worse than no button.
     *
     * @return list<array{status: ProjectStatus, needsReason: bool}>
     */
    #[Computed]
    public function availableTransitions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return collect($this->project->status->allowedTransitions())
            ->filter(fn (ProjectStatus $target): bool => $user->can($this->abilityFor($target), $this->project))
            ->map(fn (ProjectStatus $target): array => [
                'status' => $target,
                'needsReason' => in_array($target, [ProjectStatus::Suspended, ProjectStatus::Cancelled], true),
            ])
            ->values()
            ->all();
    }

    private function abilityFor(ProjectStatus $target): string
    {
        return match ($target) {
            ProjectStatus::Awarded => 'award',
            ProjectStatus::Certified => 'certify',
            ProjectStatus::Closed => 'close',
            ProjectStatus::Suspended => 'suspend',
            ProjectStatus::Cancelled => 'cancel',
            default => 'updateStatus',
        };
    }

    public function startTransition(string $status): void
    {
        $target = ProjectStatus::tryFrom($status);

        if (! $target instanceof ProjectStatus) {
            return;
        }

        $this->authorize($this->abilityFor($target), $this->project);

        $this->resetErrorBag();
        $this->transitionReason = '';
        $this->pendingStatus = $target->value;

        $this->dispatch('open-modal', 'confirm-transition');
    }

    public function confirmTransition(TransitionProjectStatus $transition): void
    {
        $target = ProjectStatus::tryFrom((string) $this->pendingStatus);

        if (! $target instanceof ProjectStatus) {
            return;
        }

        $this->authorize($this->abilityFor($target), $this->project);

        $needsReason = in_array($target, [ProjectStatus::Suspended, ProjectStatus::Cancelled], true);

        $this->validate(
            ['transitionReason' => $needsReason ? ['required', 'string', 'min:10', 'max:1000'] : ['nullable', 'string', 'max:1000']],
            [],
            ['transitionReason' => __('reason')],
        );

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $this->project = $transition(
                $this->project,
                $target,
                $actor,
                $this->transitionReason === '' ? null : $this->transitionReason,
            );
        } catch (InvalidStatusTransition|ProjectRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'confirm-transition');

            return;
        }

        unset($this->availableTransitions, $this->statusEvents);

        $this->pendingStatus = null;
        $this->transitionReason = '';
        $this->dispatch('close-modal', 'confirm-transition');

        session()->flash('status', __('Project status is now :status.', ['status' => $this->project->status->label()]));
    }

    /* ------------------------------------------------------------------ */
    /* Team */
    /* ------------------------------------------------------------------ */

    public function assignMember(AssignProjectMember $assign): void
    {
        $this->authorize('assign', $this->project);

        $this->validate([
            'assigneeId' => ['required', Rule::exists('users', 'id')],
            'assigneeRole' => ['required', Rule::enum(ProjectRole::class)],
        ], [], ['assigneeId' => __('team member'), 'assigneeRole' => __('role')]);

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();
        $member = User::query()->findOrFail($this->assigneeId);

        try {
            $assign($this->project, $member, ProjectRole::from($this->assigneeRole), $actor);
        } catch (ProjectRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->reset(['assigneeId']);
        unset($this->assignments, $this->assignableUsers);

        session()->flash('status', __(':name added to the project team.', ['name' => $member->name]));
    }

    public function unassignMember(int $assignmentId, UnassignProjectMember $unassign): void
    {
        $this->authorize('assign', $this->project);

        // Scoped to THIS project as well as the tenant: an assignment id from
        // another project must not be removable through this screen.
        $assignment = ProjectAssignment::query()
            ->where('project_id', $this->project->id)
            ->findOrFail($assignmentId);

        /** @var User $actor */
        $actor = auth()->user();

        $unassign($assignment, $actor);

        unset($this->assignments, $this->assignableUsers);

        session()->flash('status', __('Team member removed.'));
    }

    /* ------------------------------------------------------------------ */
    /* Computed reads — every one eager-loaded */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, ProjectStatusEvent> */
    #[Computed]
    public function statusEvents(): Collection
    {
        return $this->project->statusEvents()
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, ProjectLocation> */
    #[Computed]
    public function locations(): Collection
    {
        return $this->project->locations()
            ->with(['lga:id,name', 'ward:id,name'])
            ->orderByDesc('is_primary')
            ->get();
    }

    /** @return Collection<int, Contract> */
    #[Computed]
    public function contracts(): Collection
    {
        return $this->project->contracts()
            ->with(['contractor:id,name,is_blacklisted', 'variations'])
            ->whereNull('varies_contract_id')
            ->orderByDesc('award_date')
            ->get();
    }

    /** @return Collection<int, ProjectAssignment> */
    #[Computed]
    public function assignments(): Collection
    {
        return $this->project->assignments()
            ->with('user:id,name,email')
            ->whereNull('unassigned_at')
            ->get();
    }

    /** @return Collection<int, Indicator> */
    #[Computed]
    public function indicators(): Collection
    {
        return $this->project->indicators()->orderBy('name')->get();
    }

    /** @return Collection<int, ProjectFundingSource> */
    #[Computed]
    public function fundingAllocations(): Collection
    {
        return $this->project->fundingAllocations()->with('fundingSource:id,name,type')->get();
    }

    /**
     * Members of this workspace who are not already on the team. Read through
     * the Iam action — the membership gate table is off limits to screens.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignableUsers(): Collection
    {
        $alreadyAssigned = $this->assignments()->pluck('user_id')->all();

        return (new ListTenantMembers)()
            ->filter(fn (TenantMembership $membership): bool => $membership->isActive())
            ->map(fn (TenantMembership $membership): ?User => $membership->user)
            ->filter(fn (?User $user): bool => $user !== null && ! in_array($user->id, $alreadyAssigned, true))
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /* Links */
    /* ------------------------------------------------------------------ */

    #[Computed]
    public function projectsUrl(): string
    {
        return $this->tenantRoute('tenant.projects.index');
    }

    #[Computed]
    public function editUrl(): string
    {
        return $this->tenantRoute('tenant.projects.edit', ['project' => $this->project]);
    }

    #[Computed]
    public function contractCreateUrl(): string
    {
        return $this->tenantRoute('tenant.projects.contracts.create', ['project' => $this->project]);
    }

    public function contractUrl(Contract $contract): string
    {
        return $this->tenantRoute('tenant.projects.contracts.show', [
            'project' => $this->project,
            'contract' => $contract,
        ]);
    }

    /**
     * A named-route URL on THIS workspace's subdomain.
     *
     * route() is the rule — it fails loudly on a missing route or the wrong
     * binding key, where a hand-built string 404s silently in front of a user
     * (this project shipped exactly that twice). The tenant routes carry a
     * `{tenant}` domain parameter that ResolveTenant fills through
     * URL::defaults on a real request and that nothing fills inside
     * Livewire::test(), which never crosses HTTP; passing the bound tenant
     * explicitly makes both paths generate the same URL.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function tenantRoute(string $name, array $parameters = []): string
    {
        return route($name, [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            ...$parameters,
        ]);
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.project-detail');
    }
}
