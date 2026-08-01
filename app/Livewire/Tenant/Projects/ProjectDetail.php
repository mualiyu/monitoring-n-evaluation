<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Actions\Iam\ListTenantMembers;
use App\Actions\Projects\AssignProjectMember;
use App\Actions\Projects\AwardContract;
use App\Actions\Projects\TransitionProjectStatus;
use App\Actions\Projects\UnassignProjectMember;
use App\Enums\ContractType;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Exceptions\Projects\InvalidStatusTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

    // Contract award
    public string $contractorId = '';

    public string $contractNumber = '';

    public string $contractType = 'works';

    public string $contractSum = '';

    public string $scopeOfWorks = '';

    public string $awardDate = '';

    public string $expectedCompletionDate = '';

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
        $this->awardDate = now()->toDateString();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
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
    /* Contracts */
    /* ------------------------------------------------------------------ */

    public function awardContract(AwardContract $award): void
    {
        $this->authorize('award', $this->project);

        $this->validate([
            'contractorId' => ['required', Rule::exists('contractors', 'id')],
            'contractNumber' => ['required', 'string', 'max:60'],
            'contractType' => ['required', Rule::enum(ContractType::class)],
            'contractSum' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'scopeOfWorks' => ['required', 'string', 'min:20', 'max:10000'],
            'awardDate' => ['required', 'date'],
            'expectedCompletionDate' => ['nullable', 'date', 'after_or_equal:awardDate'],
        ], [], [
            'contractorId' => __('contractor'),
            'contractNumber' => __('contract number'),
            'contractSum' => __('contract sum'),
            'scopeOfWorks' => __('scope of works'),
            'awardDate' => __('award date'),
            'expectedCompletionDate' => __('expected completion date'),
        ]);

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();
        $contractor = Contractor::query()->findOrFail($this->contractorId);

        try {
            $award($this->project, $contractor, $actor, [
                'contract_number' => trim($this->contractNumber),
                'type' => $this->contractType,
                'sum' => $this->contractSum,
                'scope_of_works' => trim($this->scopeOfWorks),
                'award_date' => $this->awardDate,
                'expected_completion_date' => $this->expectedCompletionDate === '' ? null : $this->expectedCompletionDate,
            ]);
        } catch (ProjectRuleViolation|AuthorizationException $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->reset(['contractorId', 'contractNumber', 'contractSum', 'scopeOfWorks', 'expectedCompletionDate']);
        $this->project->refresh();

        unset($this->contracts, $this->availableTransitions, $this->statusEvents);

        $this->dispatch('close-modal', 'award-contract');
        session()->flash('status', __('Contract recorded and the project moved to Awarded.'));
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

    /** @return Collection<int, Contractor> */
    #[Computed]
    public function contractors(): Collection
    {
        return Contractor::query()
            ->where('is_blacklisted', false)
            ->orderBy('name')
            ->get(['id', 'name', 'rc_number']);
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.project-detail');
    }
}
