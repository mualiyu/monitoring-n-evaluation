<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Indicators;

use App\Actions\Indicators\CreateIndicator;
use App\Actions\Indicators\CreateResultFramework;
use App\Actions\Indicators\DeleteResultFramework;
use App\Actions\Indicators\InstantiateIndicatorFromDefinition;
use App\Actions\Indicators\UpdateResultFramework;
use App\Enums\FrameworkLevel;
use App\Enums\IndicatorTier;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\Project;
use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The logframe builder for one project: impact → outcome → output, with the
 * indicators that measure each statement hanging off it.
 *
 * The tree is exactly three levels deep and the enum guarantees it
 * (FrameworkLevel::allowedChildLevels), which is why this screen renders three
 * explicit tiers rather than recursing: a logframe that grows a fourth level
 * has stopped being a results chain and become a task list, and the manual is
 * unambiguous that inputs and activities belong in the work plan instead.
 *
 * Indicators arrive two ways. From the STATE LIBRARY is the intended route —
 * comparable figures across MDAs is the entire reason the secretariat keeps a
 * predetermined list (manual digest §4). Defined LOCALLY is the escape hatch
 * for a measure the library has no entry for yet, and the null
 * `indicator_definition_id` it leaves behind is the query that tells the Q4
 * indicator retreat what to consider promoting.
 *
 * Every mutating method authorizes again. Route middleware does not protect
 * Livewire update POSTs by itself.
 */
#[Layout('layouts::tenant')]
class FrameworkBuilder extends Component
{
    public Project $project;

    /* -------- statement form -------- */
    public string $statementParent = '';

    public string $statementLevel = '';

    public string $statementCode = '';

    public string $statementText = '';

    public string $statementDescription = '';

    public string $statementAssumptions = '';

    public string $editingStatement = '';

    /* -------- indicator form -------- */
    public string $indicatorFramework = '';

    /** library | local */
    public string $indicatorSource = 'library';

    public string $definitionId = '';

    public string $indicatorTier = '';

    public string $indicatorName = '';

    public string $indicatorUnit = '';

    public string $indicatorFrequency = '';

    public string $indicatorTargetType = '';

    public string $indicatorDefinitionText = '';

    public string $indicatorDataSource = '';

    public string $indicatorVerification = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', ResultFramework::class);

        $this->project = $project;
    }

    /* ---------------------------------------------------------------- */
    /* Reads */
    /* ---------------------------------------------------------------- */

    /**
     * The whole framework in ONE query, nested in PHP.
     *
     * Three levels means three round trips if the tree is eager-loaded the
     * obvious way (`children.children`), and every one of them is the same
     * table. Loading the project's statements once and grouping by parent is
     * both fewer queries and the only way the sort order stays consistent
     * across levels.
     *
     * @return list<array{statement: ResultFramework, children: list<array{statement: ResultFramework, children: list<array{statement: ResultFramework, children: list<mixed>}>}>}>
     */
    #[Computed]
    public function tree(): array
    {
        /** @var Collection<int, ResultFramework> $statements */
        $statements = ResultFramework::query()
            ->forProject($this->project)
            ->with(['indicators' => fn ($query) => $query
                ->with(['latestTarget', 'latestCountableReading', 'libraryDefinition:id,code'])
                ->orderBy('tier')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $byParent = $statements->groupBy(fn (ResultFramework $statement) => $statement->parent_id ?? 0);

        return $this->branch($byParent, 0);
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, Collection<int, ResultFramework>>  $byParent
     * @return list<array{statement: ResultFramework, children: list<mixed>}>
     */
    private function branch(\Illuminate\Support\Collection $byParent, int $parentId): array
    {
        return ($byParent->get($parentId) ?? collect())
            ->map(fn (ResultFramework $statement): array => [
                'statement' => $statement,
                'children' => $this->branch($byParent, $statement->id),
            ])
            ->values()
            ->all();
    }

    /**
     * The state library, keyed by id — an id-keyed map, so the select submits
     * the id (see resources/views/components/ui/form/select.blade.php).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function definitionOptions(): array
    {
        return IndicatorDefinition::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (IndicatorDefinition $definition) => [
                $definition->id => $definition->code.' — '.$definition->name,
            ])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function unitOptions(): array
    {
        return collect(IndicatorUnit::cases())
            ->mapWithKeys(fn (IndicatorUnit $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function frequencyOptions(): array
    {
        return collect(MeasurementFrequency::cases())
            ->mapWithKeys(fn (MeasurementFrequency $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function targetTypeOptions(): array
    {
        return collect(TargetType::cases())
            ->mapWithKeys(fn (TargetType $case) => [$case->value => $case->label()])
            ->all();
    }

    /**
     * Only the tiers that can measure the statement being added to — offering
     * an output tier on an impact statement would be offering a refusal.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tierOptions(): array
    {
        $framework = $this->indicatorFramework === '' ? null : $this->statementByUlid($this->indicatorFramework);

        return collect(IndicatorTier::cases())
            ->filter(fn (IndicatorTier $tier) => $framework === null || $tier->fitsLevel($framework->level))
            ->mapWithKeys(fn (IndicatorTier $tier) => [$tier->value => $tier->label()])
            ->all();
    }

    /* ---------------------------------------------------------------- */
    /* Statements */
    /* ---------------------------------------------------------------- */

    public function startStatement(string $parentUlid = '', string $level = ''): void
    {
        $this->authorize('create', ResultFramework::class);

        $this->resetErrorBag();
        $this->failure = null;
        $this->reset(['statementCode', 'statementText', 'statementDescription', 'statementAssumptions', 'editingStatement']);

        $this->statementParent = $parentUlid;
        $this->statementLevel = $level !== '' ? $level : $this->levelUnder($parentUlid)->value;

        $this->dispatch('open-modal', 'result-statement');
    }

    /**
     * What a new statement under this parent must be. The enum owns the
     * answer, so the screen never offers a level the Action would refuse.
     */
    private function levelUnder(string $parentUlid): FrameworkLevel
    {
        if ($parentUlid === '') {
            return FrameworkLevel::Impact;
        }

        $parent = $this->statementByUlid($parentUlid);

        return $parent->level->allowedChildLevels()[0] ?? $parent->level;
    }

    public function editStatement(string $ulid): void
    {
        $statement = $this->statementByUlid($ulid);

        $this->authorize('update', $statement);

        $this->resetErrorBag();
        $this->failure = null;
        $this->editingStatement = $statement->ulid;
        $this->statementParent = '';
        $this->statementLevel = $statement->level->value;
        $this->statementCode = (string) $statement->code;
        $this->statementText = $statement->statement;
        $this->statementDescription = (string) $statement->description;
        $this->statementAssumptions = (string) $statement->assumptions;

        $this->dispatch('open-modal', 'result-statement');
    }

    public function saveStatement(CreateResultFramework $create, UpdateResultFramework $update): void
    {
        $validated = $this->validate([
            'statementLevel' => ['required', 'string', 'in:'.implode(',', array_column(FrameworkLevel::cases(), 'value'))],
            'statementText' => ['required', 'string', 'min:5', 'max:255'],
            'statementCode' => ['nullable', 'string', 'max:40'],
            'statementDescription' => ['nullable', 'string', 'max:2000'],
            'statementAssumptions' => ['nullable', 'string', 'max:2000'],
        ], [
            'statementText.min' => __('A result statement says what changes. A few words at least.'),
        ]);

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();

        try {
            if ($this->editingStatement !== '') {
                $statement = $this->statementByUlid($this->editingStatement);
                $this->authorize('update', $statement);

                $update(
                    $statement,
                    $actor,
                    $validated['statementText'],
                    $validated['statementCode'] ?: null,
                    $validated['statementDescription'] ?: null,
                    $validated['statementAssumptions'] ?: null,
                );
            } else {
                $this->authorize('create', ResultFramework::class);

                $create(
                    FrameworkLevel::from($validated['statementLevel']),
                    $validated['statementText'],
                    $actor,
                    $this->project,
                    $this->statementParent === '' ? null : $this->statementByUlid($this->statementParent),
                    $validated['statementCode'] ?: null,
                    $validated['statementDescription'] ?: null,
                    $validated['statementAssumptions'] ?: null,
                );
            }
        } catch (IndicatorRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'result-statement');

            return;
        }

        unset($this->tree);
        $this->dispatch('close-modal', 'result-statement');

        session()->flash('status', __('Results framework updated.'));
    }

    public function deleteStatement(string $ulid, DeleteResultFramework $delete): void
    {
        $statement = $this->statementByUlid($ulid);

        $this->authorize('delete', $statement);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $delete($statement, $actor);
        } catch (IndicatorRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        unset($this->tree);

        session()->flash('status', __('Result statement removed.'));
    }

    /* ---------------------------------------------------------------- */
    /* Indicators */
    /* ---------------------------------------------------------------- */

    public function startIndicator(string $frameworkUlid): void
    {
        $this->authorize('create', Indicator::class);

        $statement = $this->statementByUlid($frameworkUlid);

        $this->resetErrorBag();
        $this->failure = null;
        $this->reset([
            'definitionId', 'indicatorName', 'indicatorDefinitionText',
            'indicatorDataSource', 'indicatorVerification',
        ]);

        $this->indicatorFramework = $statement->ulid;
        $this->indicatorSource = 'library';
        $this->indicatorTier = $statement->level->defaultTier()->value;
        $this->indicatorUnit = IndicatorUnit::Number->value;
        $this->indicatorFrequency = MeasurementFrequency::Quarterly->value;
        $this->indicatorTargetType = TargetType::Continuous->value;

        $this->dispatch('open-modal', 'attach-indicator');
    }

    public function saveIndicator(
        InstantiateIndicatorFromDefinition $instantiate,
        CreateIndicator $createIndicator,
    ): void {
        $this->authorize('create', Indicator::class);

        $statement = $this->statementByUlid($this->indicatorFramework);

        $this->authorize('update', $statement);

        $rules = [
            'indicatorSource' => ['required', 'in:library,local'],
            'indicatorTier' => ['required', 'string', 'in:'.implode(',', array_column(IndicatorTier::cases(), 'value'))],
        ];

        $rules += $this->indicatorSource === 'library'
            ? ['definitionId' => ['required', 'integer', 'exists:indicator_definitions,id']]
            : [
                'indicatorName' => ['required', 'string', 'min:3', 'max:255'],
                'indicatorUnit' => ['required', 'string', 'in:'.implode(',', array_column(IndicatorUnit::cases(), 'value'))],
                'indicatorFrequency' => ['required', 'string', 'in:'.implode(',', array_column(MeasurementFrequency::cases(), 'value'))],
                'indicatorTargetType' => ['required', 'string', 'in:'.implode(',', array_column(TargetType::cases(), 'value'))],
                'indicatorDefinitionText' => ['nullable', 'string', 'max:2000'],
                'indicatorDataSource' => ['nullable', 'string', 'max:1000'],
                'indicatorVerification' => ['nullable', 'string', 'max:1000'],
            ];

        $validated = $this->validate($rules);

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();

        try {
            if ($this->indicatorSource === 'library') {
                /** @var IndicatorDefinition $definition */
                $definition = IndicatorDefinition::query()->findOrFail($validated['definitionId']);

                $instantiate($definition, $statement, $actor, IndicatorTier::from($validated['indicatorTier']));
            } else {
                $createIndicator([
                    'name' => $validated['indicatorName'],
                    'definition' => $validated['indicatorDefinitionText'] ?: null,
                    'unit' => IndicatorUnit::from($validated['indicatorUnit']),
                    'measurement_frequency' => MeasurementFrequency::from($validated['indicatorFrequency']),
                    'target_type' => TargetType::from($validated['indicatorTargetType']),
                    'data_source' => $validated['indicatorDataSource'] ?: null,
                    'means_of_verification' => $validated['indicatorVerification'] ?: null,
                    'tier' => IndicatorTier::from($validated['indicatorTier']),
                ], $actor, $statement);
            }
        } catch (IndicatorRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'attach-indicator');

            return;
        }

        unset($this->tree);
        $this->dispatch('close-modal', 'attach-indicator');

        session()->flash('status', __('Indicator added. It stays inactive until this entity agrees a baseline for it.'));
    }

    /**
     * A statement named by a method, resolved through the project's own
     * framework. A ULID from another project (or another MDA) is a 404, not a
     * refusal that confirms the row exists somewhere.
     */
    private function statementByUlid(string $ulid): ResultFramework
    {
        return ResultFramework::query()
            ->forProject($this->project)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.tenant.indicators.framework-builder');
    }
}
