<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Projects;

use App\Actions\Projects\TransitionProjectStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Projects\InvalidStatusTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Read-only project record on the state surface (design §5).
 *
 * "Read-only" with one deliberate exception: oversight may suspend, close or
 * cancel — the interventions the state is actually empowered to make — through
 * the SAME TransitionProjectStatus Action the MDA uses. It cannot edit scope,
 * money, contracts or team; those belong to the entity that owns delivery.
 *
 * No tenant is bound on this surface, so the record is fetched inside an
 * explicit bypass — sanctioned in the Oversight namespaces, and nowhere else.
 */
#[Layout('layouts::oversight')]
class ProjectView extends Component
{
    public Project $project;

    public ?string $pendingStatus = null;

    public string $transitionReason = '';

    public ?string $failure = null;

    /** Interventions the state surface may make. Everything else is the MDA's. */
    private const OVERSIGHT_TRANSITIONS = [
        ProjectStatus::Suspended,
        ProjectStatus::Closed,
        ProjectStatus::Cancelled,
    ];

    /**
     * The route parameter is `{ulid}`, deliberately NOT `{project}`.
     *
     * Livewire's ImplicitRouteBinding matches route parameters against the
     * types of public properties and mount arguments; a parameter named
     * `project` would find the `Project` property below, treat it as a
     * route-model binding and resolve it itself — outside the bypass, with no
     * tenant bound, straight into the fail-closed TenantScope. That happens in
     * SubstituteBindings, i.e. BEFORE this surface's role middleware, so the
     * page 500s for everyone instead of 403ing the people it should.
     *
     * Naming the parameter after the column it carries keeps the binder out of
     * it and leaves the resolution here, next to the permission check.
     */
    public function mount(string $ulid): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('oversight.portfolio.view'), 403);

        $this->project = app(CurrentTenant::class)->bypass(
            fn (): Project => Project::query()
                ->with(['tenant:id,name,slug', 'sector:id,name'])
                ->where('ulid', $ulid)
                ->firstOrFail()
        );
    }

    /** @return list<array{status: ProjectStatus, needsReason: bool}> */
    #[Computed]
    public function availableTransitions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return collect($this->project->status->allowedTransitions())
            ->filter(fn (ProjectStatus $target): bool => in_array($target, self::OVERSIGHT_TRANSITIONS, true))
            ->filter(fn (ProjectStatus $target): bool => $user->can($this->abilityFor($target), $this->project))
            ->map(fn (ProjectStatus $target): array => [
                'status' => $target,
                'needsReason' => $this->needsReason($target),
            ])
            ->values()
            ->all();
    }

    private function abilityFor(ProjectStatus $target): string
    {
        return match ($target) {
            ProjectStatus::Closed => 'close',
            ProjectStatus::Cancelled => 'cancel',
            default => 'suspend',
        };
    }

    /**
     * Whether THIS actor closing to $target would be exercising the early
     * closure override (TransitionProjectStatus::assertClosable).
     *
     * Closure normally waits for the post-completion review window to end. Real
     * programmes do close early — a facility handed to a federal agency, a
     * cancelled successor phase — and the domain rule allows exactly one way
     * out: a state-level administrator, with a reason, on the record. That
     * escape hatch existed in the Action and was reachable from nowhere; the
     * state surface is where the authority that holds it actually works.
     *
     * False once the window has passed: there is nothing left to override, and
     * claiming otherwise would demand a reason for an ordinary closure.
     */
    public function overridesEarlyClosure(ProjectStatus $target): bool
    {
        if ($target !== ProjectStatus::Closed) {
            return false;
        }

        $dueAt = $this->project->post_completion_review_due_at;

        if ($dueAt !== null && $dueAt->isPast()) {
            return false;
        }

        /** @var User $user */
        $user = auth()->user();

        return $user->holdsGlobalRole(Role::SuperAdmin, Role::StateAdmin);
    }

    /**
     * Suspension and cancellation always need one. Closure needs one only when
     * it is an override — the reason IS the override, so it cannot be optional
     * there and cannot be demanded when the window has already run out.
     */
    public function needsReason(ProjectStatus $target): bool
    {
        return $target !== ProjectStatus::Closed || $this->overridesEarlyClosure($target);
    }

    /** Modal-side view of needsReason() for whatever is pending. */
    #[Computed]
    public function pendingNeedsReason(): bool
    {
        $target = ProjectStatus::tryFrom((string) $this->pendingStatus);

        return $target instanceof ProjectStatus && $this->needsReason($target);
    }

    /** Whether the pending move is the early-closure override, for the modal copy. */
    #[Computed]
    public function pendingIsEarlyClosure(): bool
    {
        $target = ProjectStatus::tryFrom((string) $this->pendingStatus);

        return $target instanceof ProjectStatus && $this->overridesEarlyClosure($target);
    }

    public function startTransition(string $status): void
    {
        $target = ProjectStatus::tryFrom($status);

        if (! $target instanceof ProjectStatus || ! in_array($target, self::OVERSIGHT_TRANSITIONS, true)) {
            return;
        }

        $this->authorize($this->abilityFor($target), $this->project);

        $this->resetErrorBag();
        $this->transitionReason = '';
        $this->pendingStatus = $target->value;

        unset($this->pendingNeedsReason, $this->pendingIsEarlyClosure);

        $this->dispatch('open-modal', 'confirm-oversight-transition');
    }

    public function confirmTransition(TransitionProjectStatus $transition): void
    {
        $target = ProjectStatus::tryFrom((string) $this->pendingStatus);

        if (! $target instanceof ProjectStatus || ! in_array($target, self::OVERSIGHT_TRANSITIONS, true)) {
            return;
        }

        $this->authorize($this->abilityFor($target), $this->project);

        $override = $this->overridesEarlyClosure($target);

        $this->validate(
            ['transitionReason' => $this->needsReason($target)
                ? ['required', 'string', 'min:10', 'max:1000']
                : ['nullable', 'string', 'max:1000']],
            [],
            ['transitionReason' => __('reason')],
        );

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $this->project = app(CurrentTenant::class)->bypass(fn (): Project => $transition(
                $this->project,
                $target,
                $actor,
                $this->transitionReason === '' ? null : $this->transitionReason,
                // The Action re-checks the global role itself: this flag only
                // says "the override was asked for", never that it was granted.
                $override,
            ));
        } catch (InvalidStatusTransition|ProjectRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'confirm-oversight-transition');

            return;
        }

        unset($this->availableTransitions, $this->statusEvents, $this->pendingNeedsReason, $this->pendingIsEarlyClosure);

        $this->pendingStatus = null;
        $this->transitionReason = '';
        $this->dispatch('close-modal', 'confirm-oversight-transition');

        session()->flash('status', __('Project status is now :status.', ['status' => $this->project->status->label()]));
    }

    /** @return Collection<int, ProjectStatusEvent> */
    #[Computed]
    public function statusEvents(): Collection
    {
        return app(CurrentTenant::class)->bypass(fn (): Collection => $this->project->statusEvents()
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get());
    }

    /** @return Collection<int, Contract> */
    #[Computed]
    public function contracts(): Collection
    {
        return app(CurrentTenant::class)->bypass(fn (): Collection => $this->project->contracts()
            ->with(['contractor:id,name,is_blacklisted', 'variations'])
            ->whereNull('varies_contract_id')
            ->orderByDesc('award_date')
            ->get());
    }

    /** @return Collection<int, ProjectLocation> */
    #[Computed]
    public function locations(): Collection
    {
        return app(CurrentTenant::class)->bypass(fn (): Collection => $this->project->locations()
            ->with(['lga:id,name', 'ward:id,name'])
            ->orderByDesc('is_primary')
            ->get());
    }

    public function render(): View
    {
        return view('livewire.oversight.projects.project-view');
    }
}
