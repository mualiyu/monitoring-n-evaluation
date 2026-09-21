<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Evaluation;

use App\Actions\Evaluation\CommissionEvaluation;
use App\Enums\EvaluationType;
use App\Enums\Role;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Commissioning an evaluation — the terms of reference turned into a record.
 *
 * ONE FORM, three cards, rather than a wizard: a commission is short (what,
 * why, who, when) and a four-step wizard for eleven fields is ceremony. The
 * project-creation wizard exists because a project has funding splits and
 * sites; this does not.
 *
 * The TEAM IS SET HERE, at commissioning, because the lead is the
 * separation-of-duties key: TransitionEvaluationStatus refuses an approval by
 * the lead, and a commission with no lead would make that guard vacuous until
 * somebody remembered to fill the roster in.
 */
#[Layout('layouts::tenant')]
class EvaluationCreate extends Component
{
    /** Pre-selected project, e.g. from a project's "commission an evaluation" action. */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    public string $scope = 'project';

    public string $type = 'mid_term';

    public string $title = '';

    public string $subjectName = '';

    public string $purpose = '';

    public string $evaluationQuestions = '';

    public string $methodologySummary = '';

    public string $sponsor = '';

    public string $budget = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $reportDueOn = '';

    /** The lead evaluator, when they hold an account here. */
    public string $leadUserId = '';

    /** The lead evaluator, when they are a contracted external. */
    public string $leadExternalName = '';

    public string $leadOrganisation = '';

    /** Team members holding accounts, by id. */
    /** @var list<string> */
    public array $memberIds = [];

    /** Additional external evaluators, one name per line. */
    public string $externalMembers = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('create', Evaluation::class);
    }

    /**
     * Projects this user may commission an evaluation of. Keyed by ULID rather
     * than by primary key: a bookmark or a pasted link carries this value, and
     * an auto-increment id in a URL both enumerates another MDA's volumes and
     * invites a guess at a row that is not yours.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return Project::query()
            ->visibleTo($user)
            ->orderBy('title')
            ->pluck('title', 'ulid')
            ->all();
    }

    /**
     * Colleagues who could be named on the team. An id-keyed map, so the
     * select submits the id (see the note in the select component).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function staffOptions(): array
    {
        return User::query()
            ->role([Role::MdaAdmin->value, Role::MeOfficer->value, Role::FieldMonitor->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return EvaluationType::options();
    }

    /**
     * One line on what the chosen format is for, shown under the select —
     * because "ex-post" and "impact" are terms of art and a commissioner
     * choosing between them from a bare list will choose wrongly.
     */
    #[Computed]
    public function typeDescription(): string
    {
        return (EvaluationType::tryFrom($this->type) ?? EvaluationType::MidTerm)->description();
    }

    /** @return array<string, string> */
    #[Computed]
    public function scopeOptions(): array
    {
        return [
            'project' => __('A single project'),
            'programme' => __('A programme (several projects)'),
            'entity' => __('This entity as a whole'),
            'sector' => __('A sector'),
            'thematic' => __('A theme across projects'),
        ];
    }

    public function evaluatesAProject(): bool
    {
        return $this->scope === 'project';
    }

    public function save(CommissionEvaluation $commission): void
    {
        // Re-authorized on every mutating call: route middleware does not
        // protect a Livewire update POST by itself, and a client can invoke
        // this long after the screen was opened.
        $this->authorize('create', Evaluation::class);

        $validated = $this->validate($this->rules(), $this->messages(), $this->attributes());

        $this->failure = null;

        $project = $this->evaluatesAProject() && $this->projectUlid !== ''
            // Resolved through the model, so the TenantScope confines it: a
            // ULID from another workspace is "not found" rather than a row
            // filtered out somewhere later.
            ? Project::query()->where('ulid', $this->projectUlid)->first()
            : null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $evaluation = $commission($actor, [
                'project_id' => $project?->id,
                'scope' => $this->scope,
                'subject_name' => $this->evaluatesAProject() ? null : trim($this->subjectName),
                'type' => $this->type,
                'title' => trim($validated['title']),
                'purpose' => trim($validated['purpose']),
                'evaluation_questions' => trim($this->evaluationQuestions) ?: null,
                'methodology_summary' => trim($this->methodologySummary) ?: null,
                'sponsor' => trim($validated['sponsor']),
                'budget' => $this->budget === '' ? null : $this->budget,
                'starts_on' => $this->startsOn ?: null,
                'ends_on' => $this->endsOn ?: null,
                'report_due_on' => $this->reportDueOn ?: null,
            ], $this->team());
        } catch (EvaluationRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        session()->flash('status', __('Evaluation commissioned. The report skeleton and the scorecard are ready for the team.'));

        $this->redirect(route('tenant.evaluations.show', [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            'evaluation' => $evaluation,
        ]), navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'in:'.implode(',', Evaluation::SCOPES)],
            'type' => ['required', 'string', 'in:'.implode(',', array_column(EvaluationType::cases(), 'value'))],
            'title' => ['required', 'string', 'min:8', 'max:255'],
            // The subject is required in exactly one direction: a project
            // evaluation names a project, anything else names what it covers.
            'projectUlid' => [$this->evaluatesAProject() ? 'required' : 'nullable', 'string', 'max:40'],
            'subjectName' => [$this->evaluatesAProject() ? 'nullable' : 'required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'min:20', 'max:5000'],
            'evaluationQuestions' => ['nullable', 'string', 'max:5000'],
            'methodologySummary' => ['nullable', 'string', 'max:5000'],
            'sponsor' => ['required', 'string', 'max:255'],
            // `numeric` paired with Money::FORM_RULE: `numeric` alone accepts
            // '5.' and '1e5', which the Money cast then rejects with a 500.
            'budget' => ['nullable', 'numeric', Money::FORM_RULE, 'min:0'],
            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'reportDueOn' => ['nullable', 'date'],
            // A lead is required, as an account holder or as a named external.
            'leadUserId' => [$this->leadExternalName === '' ? 'required' : 'nullable', 'integer'],
            'leadExternalName' => [$this->leadUserId === '' ? 'required' : 'nullable', 'string', 'max:255'],
            'leadOrganisation' => ['nullable', 'string', 'max:255'],
            'memberIds' => ['array'],
            'memberIds.*' => ['integer'],
            'externalMembers' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'projectUlid.required' => __('Choose the project this evaluation covers.'),
            'subjectName.required' => __('Name the programme, sector or theme this evaluation covers.'),
            'purpose.min' => __('Say what this evaluation is for. A purpose nobody can read is a commission nobody can scope.'),
            'leadUserId.required' => __('Name the evaluation lead — either a colleague or an external evaluator. The lead signs for the findings and, for that reason, cannot approve them.'),
            'leadExternalName.required' => __('Name the evaluation lead — either a colleague or an external evaluator. The lead signs for the findings and, for that reason, cannot approve them.'),
            'endsOn.after_or_equal' => __('An evaluation cannot end before it starts.'),
            'budget.regex' => __('Enter an amount like 4500000 or 4500000.00.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'projectUlid' => __('project'),
            'subjectName' => __('subject'),
            'evaluationQuestions' => __('evaluation questions'),
            'methodologySummary' => __('methodology'),
            'reportDueOn' => __('report deadline'),
            'leadUserId' => __('evaluation lead'),
            'leadExternalName' => __('evaluation lead'),
        ];
    }

    /**
     * The roster as AssignEvaluationTeam expects it: exactly one lead, then
     * the members — colleagues by id, externals by name, one per line.
     *
     * @return list<array<string, mixed>>
     */
    private function team(): array
    {
        $team = [];

        if ($this->leadUserId !== '') {
            $team[] = ['user_id' => (int) $this->leadUserId, 'role' => 'lead'];
        } elseif (trim($this->leadExternalName) !== '') {
            $team[] = [
                'external_name' => trim($this->leadExternalName),
                'external_organisation' => trim($this->leadOrganisation) ?: null,
                'role' => 'lead',
            ];
        }

        foreach ($this->memberIds as $memberId) {
            if ((string) $memberId === $this->leadUserId) {
                continue; // the lead is already on the roster
            }

            $team[] = ['user_id' => (int) $memberId, 'role' => 'member'];
        }

        foreach (preg_split('/\r?\n/', $this->externalMembers) ?: [] as $line) {
            $name = trim($line);

            if ($name !== '') {
                $team[] = ['external_name' => $name, 'role' => 'member'];
            }
        }

        return $team;
    }

    public function render(): View
    {
        return view('livewire.tenant.evaluation.evaluation-create');
    }
}
