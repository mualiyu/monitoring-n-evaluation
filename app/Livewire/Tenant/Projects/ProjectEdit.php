<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Actions\Iam\ListTenantMembers;
use App\Actions\Projects\UpdateProjectDetails;
use App\Enums\MeasurementFrequency;
use App\Enums\ProjectType;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\Sector;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Money;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Edit the project RECORD (design §5, `/projects/{project}/edit`) — the screen
 * the detail header and the index row menu have always linked to and which,
 * until now, did not exist: both links 404'd.
 *
 * Scope is exactly what UpdateProjectDetails owns: identity, scope, budget
 * figures and schedule. Everything with its own lifecycle keeps its own
 * Action and its own place — status moves through TransitionProjectStatus on
 * the detail page, contracts through AwardContract, sites through the location
 * Actions, funding through SetProjectFundingSources, team through the
 * assignment Actions. A form that quietly wrote any of those would be a second
 * writer for a guarded field.
 *
 * The certification freeze (§2.3) is presented, not reimplemented: from
 * `certified` onward the fields a completion certificate attests to are shown
 * read-only, while the accountable officer and the reporting cadence stay
 * editable. The Action is still the authority — it throws if a frozen field
 * moves — and its refusal is surfaced verbatim rather than translated here.
 */
#[Layout('layouts::tenant')]
class ProjectEdit extends Component
{
    public Project $project;

    // Identity & scope
    public string $reference = '';

    public string $title = '';

    public string $sector_id = '';

    public string $type = '';

    public string $description = '';

    public string $goal = '';

    public string $objectives = '';

    public string $manager_id = '';

    public string $supervising_agency_name = '';

    // Budget
    public string $budget_allocation = '';

    public string $budget_code = '';

    // Schedule & reporting
    public string $start_date = '';

    public string $expected_end_date = '';

    public string $revised_end_date = '';

    public string $reporting_frequency = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);

        $this->project = $project;

        $this->reference = $project->reference ?? '';
        $this->title = $project->title ?? '';
        $this->sector_id = (string) ($project->sector_id ?? '');
        // `type` is a non-nullable enum cast, so there is nothing to coalesce.
        $this->type = $project->type->value;
        $this->description = $project->description ?? '';
        $this->goal = $project->goal ?? '';
        $this->objectives = $project->objectives ?? '';
        $this->manager_id = (string) ($project->manager_id ?? '');
        $this->supervising_agency_name = $project->supervising_agency_name ?? '';

        $this->budget_allocation = $project->budget_allocation?->toDecimalString() ?? '';
        $this->budget_code = $project->budget_code ?? '';

        $this->start_date = $project->start_date?->format('Y-m-d') ?? '';
        $this->expected_end_date = $project->expected_end_date?->format('Y-m-d') ?? '';
        $this->revised_end_date = $project->revised_end_date?->format('Y-m-d') ?? '';
        $this->reporting_frequency = $project->reporting_frequency ?? '';
    }

    /** Whether the certificate-attested fields are locked (§2.3). */
    #[Computed]
    public function frozen(): bool
    {
        return $this->project->isFrozen();
    }

    /**
     * The fields this form may write given the project's current state — the
     * single source of truth for both the rules and the payload, so validation
     * can never require something the form will not submit.
     *
     * On a frozen project the certificate-attested fields drop out entirely
     * rather than being submitted unchanged. Submitting them was the obvious
     * approach and it is wrong: money and dates round-trip through the form as
     * strings, and `decimal(18,2)` money or a date re-cast from 'Y-m-d' can
     * come back dirty to Eloquent while representing the very same value. The
     * Action would then refuse an edit the officer is entitled to make — the
     * accountable manager, the reporting cadence — because of a field nobody
     * touched. UpdateProjectDetails stays the backstop for a tampered payload.
     *
     * @return list<string>
     */
    private function editableFields(): array
    {
        $fields = [
            'reference', 'title', 'sector_id', 'type', 'description', 'goal', 'objectives',
            'manager_id', 'supervising_agency_name', 'budget_allocation', 'budget_code',
            'start_date', 'expected_end_date', 'revised_end_date', 'reporting_frequency',
        ];

        return $this->project->isFrozen()
            ? array_values(array_diff($fields, Project::FROZEN_FIELDS))
            : $fields;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return array_intersect_key($this->allRules(), array_flip($this->editableFields()));
    }

    /** @return array<string, mixed> */
    private function allRules(): array
    {
        return [
            'title' => ['required', 'string', 'min:6', 'max:255'],
            'reference' => [
                'required', 'string', 'max:40',
                // Uniqueness through the model, so the TenantScope decides the
                // scope and a clash in another MDA can never be reported here.
                // withTrashed() because the unique index is on the TABLE and
                // archiving only soft-deletes — an archived project still owns
                // its reference. The current project is excluded, or saving an
                // untouched form would collide with itself.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $existing = Project::query()
                        ->withTrashed()
                        ->where('reference', $value)
                        ->whereKeyNot($this->project->getKey())
                        ->first();

                    if ($existing === null) {
                        return;
                    }

                    $fail($existing->trashed()
                        ? __('This reference belongs to an archived project in this workspace. Choose another, or restore that project.')
                        : __('Another project in this workspace already uses this reference.'));
                },
            ],
            'sector_id' => ['required', Rule::exists('sectors', 'id')->where('is_active', true)],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            // Not `exists:users,id` — that accepts every account on the
            // platform. The option list is the constraint, so what the form
            // offers and what it accepts cannot drift. UpdateProjectDetails
            // re-checks membership independently for callers that skip this form.
            'manager_id' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $isMember = $this->managers()
                        ->contains(fn (User $member): bool => (string) $member->id === (string) $value);

                    if (! $isMember) {
                        $fail(__('Choose a project manager from this workspace’s active members.'));
                    }
                },
            ],
            'supervising_agency_name' => ['nullable', 'string', 'max:255'],
            'budget_allocation' => ['nullable', 'numeric', Money::FORM_RULE, 'min:0', 'max:9999999999999.99'],
            'budget_code' => ['nullable', 'string', 'max:60'],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'revised_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reporting_frequency' => ['nullable', Rule::enum(MeasurementFrequency::class)],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'sector_id' => __('sector'),
            'manager_id' => __('project manager'),
            'expected_end_date' => __('expected completion date'),
            'revised_end_date' => __('revised completion date'),
            'budget_allocation' => __('budget allocation'),
            'reporting_frequency' => __('reporting frequency'),
        ];
    }

    public function save(UpdateProjectDetails $updateProjectDetails): mixed
    {
        $this->authorize('update', $this->project);

        // Cleared BEFORE validating: a validation failure throws, so clearing
        // afterwards would leave a previous Action refusal on screen next to
        // fresh field errors.
        $this->failure = null;

        $this->validate();

        /** @var User $actor */
        $actor = auth()->user();

        /*
            Hand the Action a PRISTINE model.

            Populating this form reads the Money casts — mount() needs their
            decimal strings — and reading a class cast makes Eloquent
            re-serialize it into the attribute array on the next dirty check.
            An untouched budget_allocation therefore shows up as "changed", and
            because it is a frozen field the certification freeze refuses an
            edit nobody made to it (the observed dirty set was budget_allocation,
            contract_value_total and expenditure_to_date, all identical to what
            was stored).

            A re-fetch carries no cast cache, so the only dirty attributes are
            the ones this form actually submitted. The guard in
            UpdateProjectDetails is left exactly as strict as it was: narrowing
            it to "fields the caller passed" would let a pre-dirtied model slip
            a frozen change past it, which is the opposite of what is wanted.

            Queried through the model rather than with fresh(): fresh() is
            newQueryWithoutScopes(), so it would re-load the row with the
            TenantScope OFF — an unscoped cross-tenant read sitting in
            tenant-surface code, which rules/tenancy.md reserves for the
            sanctioned scope bypass inside oversight code. firstOrFail() under
            the scope fails closed instead.
        */
        $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

        try {
            $updateProjectDetails($project, $actor, $this->attributes());
            $this->project = $project;
        } catch (ProjectRuleViolation $exception) {
            // Includes the frozen-record refusal: the Action is the authority
            // on the freeze, so its wording is shown rather than second-guessed.
            $this->failure = $exception->getMessage();

            return null;
        }

        session()->flash('status', __('Project “:title” updated.', ['title' => $this->project->title]));

        return $this->redirect(url('/projects/'.$this->project->ulid), navigate: true);
    }

    /**
     * The payload, narrowed to what this form may write in the project's
     * current state (see editableFields()).
     *
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return array_intersect_key($this->allAttributes(), array_flip($this->editableFields()));
    }

    /** @return array<string, mixed> */
    private function allAttributes(): array
    {
        return [
            'reference' => trim($this->reference),
            'title' => trim($this->title),
            'description' => $this->nullIfBlank($this->description),
            'goal' => $this->nullIfBlank($this->goal),
            'objectives' => $this->nullIfBlank($this->objectives),
            'sector_id' => (int) $this->sector_id,
            'type' => $this->type,
            'supervising_agency_name' => $this->nullIfBlank($this->supervising_agency_name),
            'budget_allocation' => $this->nullIfBlank($this->budget_allocation),
            'budget_code' => $this->nullIfBlank($this->budget_code),
            'start_date' => $this->nullIfBlank($this->start_date),
            'expected_end_date' => $this->nullIfBlank($this->expected_end_date),
            'revised_end_date' => $this->nullIfBlank($this->revised_end_date),
            'reporting_frequency' => $this->nullIfBlank($this->reporting_frequency),
            'manager_id' => $this->manager_id !== '' ? (int) $this->manager_id : null,
        ];
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Workspace members who can be handed a project. Membership is read through
     * the Iam action, never by querying the gate table here — that boundary is
     * enforced by the tenancy discipline sweep.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function managers(): Collection
    {
        return (new ListTenantMembers)()
            ->filter(fn (TenantMembership $membership): bool => $membership->isActive())
            ->map(fn (TenantMembership $membership): ?User => $membership->user)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.project-edit');
    }
}
