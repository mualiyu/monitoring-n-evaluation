<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Workplans;

use App\Actions\Workplans\AddWorkplanActivity;
use App\Actions\Workplans\ApproveWorkplan;
use App\Actions\Workplans\CloseWorkplan;
use App\Actions\Workplans\RecordActivityProgress;
use App\Actions\Workplans\RejectWorkplan;
use App\Actions\Workplans\RemoveWorkplanActivity;
use App\Actions\Workplans\SubmitWorkplanForApproval;
use App\Actions\Workplans\TransitionWorkplanStatus;
use App\Actions\Workplans\UpdateWorkplanActivity;
use App\Enums\ActivityScheduleGranularity;
use App\Enums\WorkplanStatus;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Models\WorkplanEvent;
use App\Rules\BelongsToCurrentTenant;
use App\Rules\IsWorkspaceMember;
use App\Support\Money;
use App\Support\WorkplanProgress;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The work-plan builder: the plan's header, its activity rows with inline add
 * and edit, the running budget total, the output-indicator warning, and the
 * approval controls.
 *
 * Every mutating method authorizes AGAIN — route middleware does not protect a
 * direct POST to the Livewire update endpoint — and every one of them delegates
 * to an Action. This component holds no business rule: the approval freeze,
 * the separation of duties and the schedule guards all live in
 * app/Actions/Workplans and are proven by their own tests.
 *
 * The warning about activities with no output indicator is the manual's rule
 * made visible (ondo-manual-digest §6). It is a warning, not a block: it names
 * how many lines break the rule, on the screen where they can be fixed.
 */
#[Layout('layouts::tenant')]
class WorkplanBuilder extends Component
{
    public Workplan $workplan;

    /** The activity being edited inline; null while adding a new one. */
    public ?string $editingUlid = null;

    public bool $showActivityForm = false;

    // ---- activity form -----------------------------------------------------
    public string $title = '';

    public string $description = '';

    public string $projectId = '';

    public string $indicatorId = '';

    public string $activityOwnerId = '';

    public string $responsibleUnit = '';

    public string $granularity = 'month';

    public string $plannedStart = '';

    public string $plannedEnd = '';

    public string $budgetLine = '';

    public string $budgetAmount = '0.00';

    public string $weight = '1';

    public string $dependsOnId = '';

    // ---- progress form -----------------------------------------------------
    public ?string $progressUlid = null;

    public string $progressPercent = '0';

    public string $progressExpenditure = '0.00';

    // ---- decision form -----------------------------------------------------
    public string $decisionReason = '';

    public function mount(Workplan $workplan): void
    {
        $this->authorize('view', $workplan);

        $this->workplan = $workplan;
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int, WorkplanActivity> */
    #[Computed]
    public function activities(): Collection
    {
        return $this->workplan->activities()
            ->with(['owner:id,name', 'project:id,ulid,title,reference', 'indicator:id,name', 'dependsOn:id,title'])
            ->get();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function summary(): array
    {
        return WorkplanProgress::summarise($this->activities());
    }

    /** @return array{elapsed: float, progress: float|null, slippage: float|null} */
    #[Computed]
    public function health(): array
    {
        // setRelation, so scheduleHealth() reuses the activities this screen
        // already loaded rather than querying them a second time.
        $this->workplan->setRelation('activities', $this->activities());

        return WorkplanProgress::scheduleHealth($this->workplan);
    }

    /** @return Collection<int, WorkplanEvent> */
    #[Computed]
    public function timeline(): Collection
    {
        return $this->workplan->events()
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Projects of this workspace, for the optional project link. Id-keyed, so
     * the select submits the id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        return Project::query()->orderBy('title')->pluck('title', 'id')->all();
    }

    /**
     * ACTIVE OUTPUT INDICATORS of this workspace — the manual's rule is about
     * output indicators specifically (one per work-plan activity), and an
     * indicator nobody has activated cannot take readings, so offering it here
     * would produce a link that reports nothing.
     *
     * Read-only use of the results-framework module's model.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function indicatorOptions(): array
    {
        return Indicator::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    #[Computed]
    public function memberOptions(): array
    {
        return app(CurrentTenant::class)->getOrFail()
            ->users()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->pluck('users.name', 'users.id')
            ->all();
    }

    /** Sibling activities this one may wait on. @return array<int, string> */
    #[Computed]
    public function dependencyOptions(): array
    {
        return $this->activities()
            ->when($this->editingUlid !== null, fn (Collection $rows) => $rows->reject(
                fn (WorkplanActivity $row): bool => $row->ulid === $this->editingUlid
            ))
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function granularityOptions(): array
    {
        $options = [];

        foreach (ActivityScheduleGranularity::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /*
    |--------------------------------------------------------------------------
    | Activity form
    |--------------------------------------------------------------------------
    */

    public function startAdding(): void
    {
        $this->authorize('update', $this->workplan);

        $this->resetActivityForm();
        $this->plannedStart = $this->workplan->period_start->toDateString();
        $this->plannedEnd = $this->workplan->period_start->addMonth()->toDateString();
        $this->showActivityForm = true;
    }

    public function editActivity(string $ulid): void
    {
        $this->authorize('update', $this->workplan);

        $activity = $this->findActivity($ulid);

        $this->editingUlid = $activity->ulid;
        $this->title = $activity->title;
        $this->description = (string) $activity->description;
        $this->projectId = (string) ($activity->project_id ?? '');
        $this->indicatorId = (string) ($activity->indicator_id ?? '');
        $this->activityOwnerId = (string) ($activity->owner_id ?? '');
        $this->responsibleUnit = (string) $activity->responsible_unit;
        $this->granularity = $activity->schedule_granularity->value;
        $this->plannedStart = $activity->planned_start->toDateString();
        $this->plannedEnd = $activity->planned_end->toDateString();
        $this->budgetLine = (string) $activity->budget_line;
        $this->budgetAmount = $activity->budget_amount->toDecimalString();
        $this->weight = (string) $activity->weight;
        $this->dependsOnId = (string) ($activity->depends_on_id ?? '');
        $this->showActivityForm = true;
    }

    public function cancelActivityForm(): void
    {
        $this->resetActivityForm();
    }

    private function resetActivityForm(): void
    {
        $this->reset([
            'editingUlid', 'showActivityForm', 'title', 'description', 'projectId',
            'indicatorId', 'activityOwnerId', 'responsibleUnit', 'granularity',
            'plannedStart', 'plannedEnd', 'budgetLine', 'budgetAmount', 'weight',
            'dependsOnId',
        ]);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    protected function activityRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Tenant-owned lookups are re-validated against THIS tenant, and
            // through the MODEL rather than Rule::exists(): exists() runs on
            // the query builder, never sees the TenantScope, and would confirm
            // another MDA's id back to the form that posted it.
            'projectId' => ['nullable', 'integer', new BelongsToCurrentTenant(Project::class)],
            'indicatorId' => ['nullable', 'integer', new BelongsToCurrentTenant(Indicator::class)],
            'activityOwnerId' => ['nullable', 'integer', new IsWorkspaceMember],
            'responsibleUnit' => ['nullable', 'string', 'max:120'],
            'granularity' => ['required', Rule::enum(ActivityScheduleGranularity::class)],
            'plannedStart' => ['required', 'date'],
            'plannedEnd' => ['required', 'date', 'after_or_equal:plannedStart'],
            'budgetLine' => ['nullable', 'string', 'max:60'],
            // numeric AND the money regex: numeric alone accepts '5.' and
            // '1e5', which 500 in the cast (App\Support\Money::FORM_RULE).
            'budgetAmount' => ['required', 'numeric', Money::FORM_RULE, 'min:0', 'max:99999999999999'],
            'weight' => ['required', 'integer', 'min:1', 'max:1000'],
            'dependsOnId' => ['nullable', 'integer', new BelongsToCurrentTenant(
                WorkplanActivity::class,
                fn (Builder $query) => $query->where('workplan_id', $this->workplan->id),
            )],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'projectId' => __('project'),
            'indicatorId' => __('output indicator'),
            'activityOwnerId' => __('owner'),
            'responsibleUnit' => __('responsible unit'),
            'plannedStart' => __('planned start'),
            'plannedEnd' => __('planned end'),
            'budgetLine' => __('budget line'),
            'budgetAmount' => __('budget amount'),
            'dependsOnId' => __('dependency'),
            'progressPercent' => __('progress'),
            'progressExpenditure' => __('expenditure to date'),
            'decisionReason' => __('reason'),
        ];
    }

    public function saveActivity(AddWorkplanActivity $add, UpdateWorkplanActivity $update): void
    {
        $this->authorize('update', $this->workplan);

        $data = $this->validate($this->activityRules());

        /** @var User $actor */
        $actor = auth()->user();

        $attributes = [
            'title' => $data['title'],
            'description' => $data['description'] === '' ? null : $data['description'],
            'project_id' => $this->nullableInt($data['projectId']),
            'indicator_id' => $this->nullableInt($data['indicatorId']),
            'owner_id' => $this->nullableInt($data['activityOwnerId']),
            'responsible_unit' => $data['responsibleUnit'] === '' ? null : $data['responsibleUnit'],
            'schedule_granularity' => $data['granularity'],
            'planned_start' => $data['plannedStart'],
            'planned_end' => $data['plannedEnd'],
            'budget_line' => $data['budgetLine'] === '' ? null : $data['budgetLine'],
            'budget_amount' => $data['budgetAmount'],
            'weight' => (int) $data['weight'],
            'depends_on_id' => $this->nullableInt($data['dependsOnId']),
        ];

        try {
            if ($this->editingUlid !== null) {
                $update($this->findActivity($this->editingUlid), $actor, $attributes);
            } else {
                $add($this->workplan, $actor, $attributes);
            }
        } catch (DomainException $e) {
            $this->addError('title', $e->getMessage());

            return;
        }

        unset($this->activities, $this->summary, $this->health, $this->dependencyOptions);
        $this->resetActivityForm();
        session()->flash('status', __('Activity saved.'));
    }

    public function removeActivity(string $ulid, RemoveWorkplanActivity $remove): void
    {
        $this->authorize('update', $this->workplan);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $remove($this->findActivity($ulid), $actor);
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        unset($this->activities, $this->summary, $this->health, $this->dependencyOptions);
        session()->flash('status', __('Activity removed.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Progress
    |--------------------------------------------------------------------------
    */

    public function startRecording(string $ulid): void
    {
        $activity = $this->findActivity($ulid);

        $this->authorize('recordProgress', $activity);

        $this->progressUlid = $activity->ulid;
        $this->progressPercent = (string) $activity->progress_percent;
        $this->progressExpenditure = $activity->expenditure_to_date->toDecimalString();
        $this->resetValidation();
    }

    public function cancelProgress(): void
    {
        $this->reset(['progressUlid', 'progressPercent', 'progressExpenditure']);
        $this->resetValidation();
    }

    public function saveProgress(RecordActivityProgress $record): void
    {
        $activity = $this->findActivity((string) $this->progressUlid);

        $this->authorize('recordProgress', $activity);

        $data = $this->validate([
            'progressPercent' => ['required', 'integer', 'min:0', 'max:100'],
            'progressExpenditure' => ['required', 'numeric', Money::FORM_RULE, 'min:0', 'max:99999999999999'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $record($activity, $actor, [
                'progress_percent' => (int) $data['progressPercent'],
                'expenditure_to_date' => $data['progressExpenditure'],
            ]);
        } catch (DomainException $e) {
            $this->addError('progressPercent', $e->getMessage());

            return;
        }

        unset($this->activities, $this->summary, $this->health);
        $this->cancelProgress();
        session()->flash('status', __('Progress recorded.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Approval chain — every control delegates to the one chokepoint
    |--------------------------------------------------------------------------
    */

    public function submit(SubmitWorkplanForApproval $submit): void
    {
        $this->authorize('submit', $this->workplan);

        $this->runTransition(fn (User $actor) => $submit($this->workplan, $actor), __('Work plan submitted for approval.'));
    }

    public function approve(ApproveWorkplan $approve): void
    {
        $this->authorize('approve', $this->workplan);

        $this->runTransition(fn (User $actor) => $approve($this->workplan, $actor), __('Work plan approved.'));
    }

    public function reject(RejectWorkplan $reject): void
    {
        $this->authorize('reject', $this->workplan);

        $this->validate(['decisionReason' => ['required', 'string', 'min:10', 'max:2000']]);

        $this->runTransition(
            fn (User $actor) => $reject($this->workplan, $actor, $this->decisionReason),
            __('Work plan sent back to its owner.'),
        );

        $this->reset('decisionReason');
    }

    public function activate(TransitionWorkplanStatus $transition): void
    {
        $this->authorize('activate', $this->workplan);

        $this->runTransition(
            fn (User $actor) => $transition($this->workplan, WorkplanStatus::Active, $actor),
            __('Work plan is now active.'),
        );
    }

    public function close(CloseWorkplan $close): void
    {
        $this->authorize('close', $this->workplan);

        $this->runTransition(fn (User $actor) => $close($this->workplan, $actor), __('Work plan closed.'));
    }

    /**
     * The one place a chain move's failure is turned into a message. Domain
     * refusals (the freeze, separation of duties, an empty plan) are told to
     * the user; anything else is a bug and keeps bubbling.
     *
     * @param  callable(User): mixed  $move
     */
    private function runTransition(callable $move, string $success): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $move($actor);
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        // Re-query through the model, never ->fresh(): fresh() is
        // newQueryWithoutScopes(), i.e. an unscoped cross-tenant read.
        $this->workplan = Workplan::query()->whereKey($this->workplan->getKey())->firstOrFail();

        unset($this->activities, $this->summary, $this->health, $this->timeline);
        session()->flash('status', $success);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Always through the plan's own relation: a ULID from another plan — or
     * another MDA — resolves to nothing and 404s rather than being acted on.
     */
    private function findActivity(string $ulid): WorkplanActivity
    {
        return $this->workplan->activities()->where('ulid', $ulid)->firstOrFail();
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    public function render(): View
    {
        return view('livewire.tenant.workplans.workplan-builder');
    }
}
