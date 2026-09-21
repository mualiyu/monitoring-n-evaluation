<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Indicators;

use App\Actions\Indicators\RecordIndicatorReading;
use App\Actions\Indicators\SetIndicatorTarget;
use App\Actions\Indicators\TransitionIndicatorReadingStatus;
use App\Actions\Projects\ActivateIndicator;
use App\Enums\IndicatorReadingStatus;
use App\Enums\MeasurementFrequency;
use App\Enums\ReadingSourceType;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Exceptions\Indicators\InvalidReadingTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\User;
use App\Support\IndicatorAchievement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One indicator: its definition sheet, its targets, its figures and the form
 * that captures the next one.
 *
 * The definition sheet is the manual's indicator matrix row (digest §2) shown
 * whole — definition, focus, unit, frequency, data source, means of
 * verification, who collects it, the SMART statement and the baseline. An
 * officer disputing a figure reads those fields before the number, and a
 * screen that hides them behind an "edit" button makes that impossible.
 *
 * Capture stops at DRAFT and submission is a separate click, because the two
 * are separate acts with separate consequences. Validation does not appear
 * here at all: it is the Data Quality Reviewer's, on the oversight surface,
 * which is the whole point of the role.
 */
#[Layout('layouts::tenant')]
class IndicatorDetail extends Component
{
    use WithPagination;

    public Indicator $indicator;

    /* -------- reading capture -------- */
    public string $periodStart = '';

    public string $periodEnd = '';

    public string $actualValue = '';

    public string $sourceType = '';

    public string $collectionMethod = '';

    public string $notes = '';

    /** The draft being corrected, by ULID — empty for a new figure. */
    public string $editingReading = '';

    /* -------- target capture -------- */
    public string $targetPeriodType = '';

    public string $targetStart = '';

    public string $targetEnd = '';

    public string $targetValue = '';

    public string $targetNotes = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Indicator $indicator): void
    {
        $this->authorize('view', $indicator);

        $this->indicator = $indicator;
        $this->sourceType = ReadingSourceType::Primary->value;
        $this->targetPeriodType = $indicator->measurement_frequency->value;
    }

    /* ---------------------------------------------------------------- */
    /* Reads */
    /* ---------------------------------------------------------------- */

    /** @return LengthAwarePaginator<int, IndicatorReading> */
    #[Computed]
    public function readings(): LengthAwarePaginator
    {
        return $this->indicator->readings()
            ->with(['recordedBy:id,name', 'submittedBy:id,name', 'validatedBy:id,name', 'rejectedBy:id,name'])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Collection<int, IndicatorTarget> */
    #[Computed]
    public function targets(): Collection
    {
        return $this->indicator->targets()
            ->orderByDesc('period_end')
            ->get();
    }

    #[Computed]
    public function achievement(): IndicatorAchievement
    {
        // loadMissing, not the bare accessor: preventLazyLoading is on outside
        // production, and the relations are the two `ofMany` sub-selects.
        $this->indicator->loadMissing(['latestTarget', 'latestCountableReading']);

        return $this->indicator->achievement();
    }

    /** @return array<string, string> */
    #[Computed]
    public function sourceTypeOptions(): array
    {
        return collect(ReadingSourceType::cases())
            ->mapWithKeys(fn (ReadingSourceType $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function periodTypeOptions(): array
    {
        return collect(MeasurementFrequency::cases())
            ->mapWithKeys(fn (MeasurementFrequency $case) => [$case->value => $case->label()])
            ->all();
    }

    /* ---------------------------------------------------------------- */
    /* Activation */
    /* ---------------------------------------------------------------- */

    /**
     * Opens the indicator for measurement. The baseline gate lives in the
     * Action, which re-asserts authority, the complete-baseline rule and the
     * already-active rule — this method only stops a doomed round trip and
     * surfaces the refusal in the officer's own words.
     */
    public function activate(ActivateIndicator $activate): void
    {
        $this->authorize('activate', $this->indicator);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $activate($this->indicator, $actor);
        } catch (ProjectRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        unset($this->achievement);

        session()->flash('status', __('Indicator activated. Figures may now be recorded against it.'));
    }

    /* ---------------------------------------------------------------- */
    /* Targets */
    /* ---------------------------------------------------------------- */

    public function startTarget(): void
    {
        $this->authorize('update', $this->indicator);

        $this->resetErrorBag();
        $this->failure = null;
        $this->reset(['targetStart', 'targetEnd', 'targetValue', 'targetNotes']);
        $this->targetPeriodType = $this->indicator->measurement_frequency->value;

        $this->dispatch('open-modal', 'set-target');
    }

    public function saveTarget(SetIndicatorTarget $setTarget): void
    {
        $this->authorize('update', $this->indicator);

        $validated = $this->validate([
            'targetPeriodType' => ['required', 'string', 'in:'.implode(',', array_column(MeasurementFrequency::cases(), 'value'))],
            'targetStart' => ['required', 'date'],
            'targetEnd' => ['required', 'date', 'after_or_equal:targetStart'],
            // A target is a measurement, so it is validated as one: `numeric`
            // alone accepts '5.' and '1e5', which are not figures anybody
            // typed on purpose and which land in a decimal column as noise.
            'targetValue' => ['required', 'numeric', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'],
            'targetNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'targetValue.regex' => __('A target is a plain figure with up to four decimal places.'),
            'targetEnd.after_or_equal' => __('A measurement period ends after it starts.'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $setTarget(
                $this->indicator,
                MeasurementFrequency::from($validated['targetPeriodType']),
                $validated['targetStart'],
                $validated['targetEnd'],
                $validated['targetValue'],
                $actor,
                $validated['targetNotes'] ?: null,
            );
        } catch (IndicatorRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'set-target');

            return;
        }

        unset($this->targets, $this->achievement);
        $this->dispatch('close-modal', 'set-target');

        session()->flash('status', __('Target recorded. The change is on the audit trail with its before and after.'));
    }

    /* ---------------------------------------------------------------- */
    /* Readings */
    /* ---------------------------------------------------------------- */

    public function startReading(): void
    {
        $this->authorize('create', IndicatorReading::class);

        $this->resetErrorBag();
        $this->failure = null;
        $this->reset(['periodStart', 'periodEnd', 'actualValue', 'collectionMethod', 'notes', 'editingReading']);
        $this->sourceType = ReadingSourceType::Primary->value;

        $this->dispatch('open-modal', 'record-reading');
    }

    public function editReading(string $ulid): void
    {
        $reading = $this->readingByUlid($ulid);

        $this->authorize('update', $reading);

        $this->resetErrorBag();
        $this->failure = null;
        $this->editingReading = $reading->ulid;
        $this->periodStart = $reading->period_start->toDateString();
        $this->periodEnd = $reading->period_end->toDateString();
        $this->actualValue = (string) $reading->actual_value;
        $this->sourceType = $reading->source_type->value;
        $this->collectionMethod = (string) $reading->collection_method;
        $this->notes = (string) $reading->notes;

        $this->dispatch('open-modal', 'record-reading');
    }

    public function saveReading(RecordIndicatorReading $record): void
    {
        $existing = $this->editingReading === '' ? null : $this->readingByUlid($this->editingReading);

        $existing === null
            ? $this->authorize('create', IndicatorReading::class)
            : $this->authorize('update', $existing);

        $validated = $this->validate([
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'actualValue' => ['required', 'numeric', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'],
            'sourceType' => ['required', 'string', 'in:'.implode(',', array_column(ReadingSourceType::cases(), 'value'))],
            'collectionMethod' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'actualValue.regex' => __('A measurement is a plain figure with up to four decimal places.'),
            'periodEnd.after_or_equal' => __('A measurement period ends after it starts.'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $record($this->indicator, [
                'period_start' => $validated['periodStart'],
                'period_end' => $validated['periodEnd'],
                'actual_value' => $validated['actualValue'],
                'source_type' => ReadingSourceType::from($validated['sourceType']),
                'collection_method' => $validated['collectionMethod'] ?: null,
                'notes' => $validated['notes'] ?: null,
            ], $actor, $existing);
        } catch (IndicatorRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'record-reading');

            return;
        }

        $this->reset(['periodStart', 'periodEnd', 'actualValue', 'collectionMethod', 'notes', 'editingReading']);
        unset($this->readings, $this->achievement);
        $this->dispatch('close-modal', 'record-reading');

        session()->flash('status', __('Figure saved as a draft. Submit it when you are ready for data-quality review.'));
    }

    /**
     * Files the figure for review. Everything that matters — the chain table,
     * the authority, the separation of measurement from assurance — is
     * asserted inside TransitionIndicatorReadingStatus; this only stops a
     * doomed round trip and shows the refusal.
     */
    public function submitReading(string $ulid, TransitionIndicatorReadingStatus $transition): void
    {
        $reading = $this->readingByUlid($ulid);

        $this->authorize('submit', $reading);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $transition($reading, IndicatorReadingStatus::Submitted, $actor);
        } catch (IndicatorRuleViolation|InvalidReadingTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        unset($this->readings, $this->achievement);

        session()->flash('status', __('Figure submitted for data-quality review.'));
    }

    /**
     * The reading a method names, resolved through the indicator's own
     * relation. A ULID outside this indicator (or outside this workspace) is a
     * 404, not a refusal that confirms the row exists somewhere.
     */
    private function readingByUlid(string $ulid): IndicatorReading
    {
        return $this->indicator->readings()->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.tenant.indicators.indicator-detail');
    }
}
