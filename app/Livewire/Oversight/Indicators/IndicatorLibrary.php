<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Indicators;

use App\Actions\Indicators\RetireIndicatorDefinition;
use App\Actions\Indicators\SaveIndicatorDefinition;
use App\Enums\IndicatorTier;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\IndicatorDefinition;
use App\Models\Sector;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The state indicator library — the manual's "predetermined indicator list"
 * (digest §4), which is what makes the secretariat's annual consolidation
 * produce comparable numbers instead of a pile of incompatible ones.
 *
 * No tenancy bypass anywhere on this screen, and none is needed:
 * indicator_definitions is GLOBAL reference data with no tenant_id at all,
 * exactly like sectors. The discipline sweep asserts it stays that way.
 *
 * Reading is open to every oversight role; writing is `indicators.library.manage`
 * in the GLOBAL team — one MDA rewording a measure every other ministry
 * reports against is the failure this list exists to prevent.
 */
#[Layout('layouts::oversight')]
class IndicatorLibrary extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(as: 'state', except: '')]
    public string $state = 'active';

    /* -------- definition form -------- */
    public string $editing = '';

    public string $code = '';

    public string $name = '';

    public string $definition = '';

    public string $focus = '';

    public string $sectorId = '';

    public string $unit = '';

    public string $frequency = '';

    public string $targetType = '';

    public string $tier = '';

    public string $dataSource = '';

    public string $meansOfVerification = '';

    public string $responsibleCollector = '';

    public string $smartStatement = '';

    public function mount(): void
    {
        $this->authorize('viewAny', IndicatorDefinition::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'sector']);
        $this->state = 'active';
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->sector !== '' || $this->state !== 'active';
    }

    /**
     * The library itself needs no bypass — it carries no tenant_id. The
     * `indicators` COUNT does: it asks how many MDAs have instantiated each
     * entry, which is a cross-MDA question, and Indicator's fail-closed scope
     * would otherwise throw here where no tenant is bound. That is exactly the
     * shape a bypass is for, and why it is confined to oversight code.
     *
     * @return LengthAwarePaginator<int, IndicatorDefinition>
     */
    #[Computed]
    public function definitions(): LengthAwarePaginator
    {
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->definitionQuery());
    }

    /** @return LengthAwarePaginator<int, IndicatorDefinition> */
    private function definitionQuery(): LengthAwarePaginator
    {
        return IndicatorDefinition::query()
            ->with(['sector:id,name'])
            ->withCount('indicators')
            ->when($this->search !== '', fn (Builder $q) => $q->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', $this->term())
                    ->orWhere('code', 'like', $this->term())
                    ->orWhere('definition', 'like', $this->term()),
            ))
            ->when($this->sector !== '', fn (Builder $q) => $q->where('sector_id', (int) $this->sector))
            ->when($this->state === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->state === 'retired', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('code')
            ->paginate(25);
    }

    private function term(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
    }

    /**
     * How much of the library is actually in use — the question the Q4
     * indicator retreat opens with.
     *
     * @return array{total: int, active: int, retired: int, unused: int}
     */
    #[Computed]
    public function stats(): array
    {
        $active = IndicatorDefinition::query()->active()->count();
        $total = IndicatorDefinition::query()->count();

        return [
            'total' => $total,
            'active' => $active,
            'retired' => $total - $active,
            // A definition no MDA has instantiated — the first question the
            // Q4 indicator retreat asks. A cross-MDA existence test, so it
            // carries the same explicit bypass as the count above.
            'unused' => app(CurrentTenant::class)->bypass(
                fn (): int => IndicatorDefinition::query()->active()->doesntHave('indicators')->count(),
            ),
        ];
    }

    /** @return array<int, string> */
    #[Computed]
    public function sectorOptions(): array
    {
        return Sector::query()->active()->orderBy('name')->pluck('name', 'id')->all();
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

    /** @return array<string, string> */
    #[Computed]
    public function tierOptions(): array
    {
        return collect(IndicatorTier::cases())
            ->mapWithKeys(fn (IndicatorTier $case) => [$case->value => $case->label()])
            ->all();
    }

    /* ---------------------------------------------------------------- */
    /* Write */
    /* ---------------------------------------------------------------- */

    public function startDefinition(): void
    {
        $this->authorize('create', IndicatorDefinition::class);

        $this->resetErrorBag();
        $this->reset([
            'editing', 'code', 'name', 'definition', 'focus', 'sectorId',
            'dataSource', 'meansOfVerification', 'responsibleCollector', 'smartStatement',
        ]);
        $this->unit = IndicatorUnit::Number->value;
        $this->frequency = MeasurementFrequency::Quarterly->value;
        $this->targetType = TargetType::Continuous->value;
        $this->tier = IndicatorTier::Output->value;

        $this->dispatch('open-modal', 'library-entry');
    }

    public function editDefinition(int $id): void
    {
        $definition = IndicatorDefinition::query()->findOrFail($id);

        $this->authorize('update', $definition);

        $this->resetErrorBag();
        $this->editing = (string) $definition->id;
        $this->code = $definition->code;
        $this->name = $definition->name;
        $this->definition = (string) $definition->definition;
        $this->focus = (string) $definition->focus;
        $this->sectorId = (string) $definition->sector_id;
        $this->unit = $definition->unit->value;
        $this->frequency = $definition->default_measurement_frequency->value;
        $this->targetType = $definition->default_target_type->value;
        $this->tier = (string) $definition->default_tier?->value;
        $this->dataSource = (string) $definition->data_source;
        $this->meansOfVerification = (string) $definition->means_of_verification;
        $this->responsibleCollector = (string) $definition->responsible_collector_text;
        $this->smartStatement = (string) $definition->smart_statement;

        $this->dispatch('open-modal', 'library-entry');
    }

    public function saveDefinition(SaveIndicatorDefinition $save): void
    {
        $existing = $this->editing === '' ? null : IndicatorDefinition::query()->findOrFail((int) $this->editing);

        $existing === null
            ? $this->authorize('create', IndicatorDefinition::class)
            : $this->authorize('update', $existing);

        $validated = $this->validate([
            // The code is the identity MDAs quote, so it is unique and, once
            // set, never rewritten — a code that moved would repoint years of
            // figures at a different measure.
            'code' => [
                Rule::excludeIf($existing !== null),
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('indicator_definitions', 'code'),
            ],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'definition' => ['nullable', 'string', 'max:2000'],
            'focus' => ['nullable', 'string', 'max:255'],
            'sectorId' => ['nullable', 'integer', 'exists:sectors,id'],
            'unit' => ['required', 'string', 'in:'.implode(',', array_column(IndicatorUnit::cases(), 'value'))],
            'frequency' => ['required', 'string', 'in:'.implode(',', array_column(MeasurementFrequency::cases(), 'value'))],
            'targetType' => ['required', 'string', 'in:'.implode(',', array_column(TargetType::cases(), 'value'))],
            'tier' => ['nullable', 'string', 'in:'.implode(',', array_column(IndicatorTier::cases(), 'value'))],
            'dataSource' => ['nullable', 'string', 'max:1000'],
            'meansOfVerification' => ['nullable', 'string', 'max:1000'],
            'responsibleCollector' => ['nullable', 'string', 'max:255'],
            'smartStatement' => ['nullable', 'string', 'max:2000'],
        ], [
            'code.regex' => __('A library code is letters, digits, dots, dashes and underscores — no spaces.'),
            'code.unique' => __('That code already names a library indicator. Codes are quoted in MDA returns, so they are never reused.'),
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        $save([
            'code' => $validated['code'] ?? $this->code,
            'name' => $validated['name'],
            'definition' => $validated['definition'] ?: null,
            'focus' => $validated['focus'] ?: null,
            'sector_id' => $validated['sectorId'] ?: null,
            'unit' => IndicatorUnit::from($validated['unit']),
            'default_measurement_frequency' => MeasurementFrequency::from($validated['frequency']),
            'default_target_type' => TargetType::from($validated['targetType']),
            'default_tier' => $validated['tier'] ? IndicatorTier::from($validated['tier']) : null,
            'data_source' => $validated['dataSource'] ?: null,
            'means_of_verification' => $validated['meansOfVerification'] ?: null,
            'responsible_collector_text' => $validated['responsibleCollector'] ?: null,
            'smart_statement' => $validated['smartStatement'] ?: null,
        ], $actor, $existing);

        unset($this->definitions, $this->stats);
        $this->dispatch('close-modal', 'library-entry');

        session()->flash('status', __('Library indicator saved. Every MDA picks from this list.'));
    }

    /**
     * Retirement, never deletion. A retired entry stays readable so a figure
     * published under it keeps its definition; it simply cannot be
     * instantiated into a new framework.
     */
    public function toggleRetired(int $id, RetireIndicatorDefinition $retire): void
    {
        $definition = IndicatorDefinition::query()->findOrFail($id);

        $this->authorize('update', $definition);

        /** @var User $actor */
        $actor = auth()->user();

        $retire($definition, $actor, $definition->is_active);

        unset($this->definitions, $this->stats);

        session()->flash('status', $definition->is_active
            ? __('Library indicator returned to circulation.')
            : __('Library indicator retired. Existing figures keep their definition; nothing new can be measured against it.'));
    }

    public function render(): View
    {
        return view('livewire.oversight.indicators.indicator-library');
    }
}
