<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Inspections;

use App\Actions\Inspections\RecordChecklistResponses;
use App\Actions\Inspections\SaveInspectionFieldNotes;
use App\Actions\Inspections\StartInspection;
use App\Actions\Inspections\SubmitInspectionReport;
use App\Enums\ChecklistResponseType;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Exceptions\Inspections\InvalidInspectionTransition;
use App\Models\InspectionChecklistTemplateItem;
use App\Models\SiteInspection;
use App\Models\SiteInspectionResponse;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The field form — the one screen in this platform that is used standing on a
 * building site, on a cheap Android, over 3G that comes and goes.
 *
 * Three consequences shape everything here:
 *
 *  1. THERE IS NO SAVE BUTTON. Every field autosaves through
 *     SaveInspectionFieldNotes, which writes the inspection row itself. An
 *     hour of observation held in browser memory until a button is pressed is
 *     an hour lost to a tunnel, a battery or a dropped call.
 *  2. GPS IS BEST-EFFORT AND DEGRADES GRACEFULLY. The browser may refuse, the
 *     device may have no fix, and a monitor under a concrete slab will get
 *     nothing. A form that blocks on a position is a form that cannot be
 *     filled at the one place it is meant to be filled.
 *  3. THE CHECKLIST SAVES PER ITEM, not as one payload, so a connection that
 *     dies halfway through loses one answer rather than forty.
 *
 * `require_photo_evidence` is NOT enforced here — it is enforced in the
 * chokepoint at submission. This screen warns about it early, which is a
 * courtesy; the control is in the Action, because a Livewire endpoint takes
 * any payload.
 */
#[Layout('layouts::tenant')]
class InspectionConduct extends Component
{
    public SiteInspection $inspection;

    /* ---- The Field Trip Report, as typed ---------------------------- */

    public string $objectives = '';

    public string $peopleMet = '';

    public string $methods = '';

    public string $findings = '';

    public string $comparisonWithPrevious = '';

    public string $conclusions = '';

    public string $recommendations = '';

    public string $physicalProgressObserved = '';

    public string $outcome = '';

    public string $team = '';

    /**
     * Bound to a checkbox group, so this is user input: Livewire writes it
     * directly and an unchecked middle item can leave a gappy array. Declared
     * as it actually arrives, and normalised to a list before it is stored.
     *
     * @var array<array-key, string>
     */
    public array $riskFlags = [];

    /* ---- Checklist answers, keyed by template item id ---------------- */

    /** @var array<int, bool|string|null> */
    public array $answers = [];

    /** @var array<int, string> */
    public array $notes = [];

    /* ---- Position ---------------------------------------------------- */

    public ?string $latitude = null;

    public ?string $longitude = null;

    public ?int $accuracy = null;

    /** What the browser said when geolocation failed — shown, not swallowed. */
    public ?string $positionError = null;

    /* ---- Feedback ---------------------------------------------------- */

    public ?string $savedAt = null;

    public ?string $failure = null;

    public function mount(SiteInspection $inspection): void
    {
        $this->authorize('conduct', $inspection);

        $this->inspection = $inspection;

        // Opening the form IS starting the visit — that is when the inspector
        // is on site, and it is what stamps conducted_at and starts the report
        // clock. Idempotent, so a refresh or a reconnect does not move it.
        if ($inspection->status === InspectionStatus::Scheduled) {
            /** @var User $actor */
            $actor = auth()->user();

            try {
                app(StartInspection::class)($inspection, $actor);
            } catch (InspectionRuleViolation|InvalidInspectionTransition $exception) {
                $this->failure = $exception->getMessage();
            }
        }

        $this->bindFrom($this->record());
    }

    private function bindFrom(SiteInspection $inspection): void
    {
        $this->objectives = (string) $inspection->objectives;
        $this->peopleMet = (string) $inspection->people_met;
        $this->methods = (string) $inspection->methods;
        $this->findings = (string) $inspection->findings;
        $this->comparisonWithPrevious = (string) $inspection->comparison_with_previous;
        $this->conclusions = (string) $inspection->conclusions;
        $this->recommendations = (string) $inspection->recommendations;
        $this->physicalProgressObserved = (string) $inspection->physical_progress_observed;
        $this->outcome = $inspection->outcome->value ?? '';
        $this->team = (string) $inspection->team;
        $this->riskFlags = $inspection->risk_flags ?? [];
        $this->latitude = $inspection->latitude;
        $this->longitude = $inspection->longitude;
        $this->accuracy = $inspection->gps_accuracy_metres;
        $this->savedAt = $inspection->autosaved_at?->toIso8601String();

        foreach ($this->existingResponses() as $response) {
            $this->answers[$response->inspection_checklist_template_item_id] = $response->answer();
            $this->notes[$response->inspection_checklist_template_item_id] = (string) $response->note;
        }
    }

    /**
     * The record, re-read rather than trusted from the property.
     *
     * NOT `->fresh()`: that is newQueryWithoutScopes(), an unscoped
     * cross-tenant read wearing innocuous clothing. Re-querying through the
     * model keeps the TenantScope on and fails closed.
     */
    #[Computed]
    public function record(): SiteInspection
    {
        return SiteInspection::query()
            ->with([
                'project:id,ulid,title,reference,physical_progress',
                'location:id,site_name',
                'template:id,name',
                'leadInspector:id,name',
            ])
            ->whereKey($this->inspection->getKey())
            ->firstOrFail();
    }

    /**
     * The instrument's items, in order. Global reference data, so no tenancy
     * question arises — but the answers recorded against them are tenant-owned.
     *
     * @return Collection<int, InspectionChecklistTemplateItem>
     */
    #[Computed]
    public function items(): Collection
    {
        $templateId = $this->record()->inspection_checklist_template_id;

        if ($templateId === null) {
            /** @var Collection<int, InspectionChecklistTemplateItem> $empty */
            $empty = new Collection;

            return $empty;
        }

        return InspectionChecklistTemplateItem::query()
            ->where('inspection_checklist_template_id', $templateId)
            ->orderBy('position')
            ->get();
    }

    /** @return Collection<int, SiteInspectionResponse> */
    private function existingResponses(): Collection
    {
        return SiteInspectionResponse::query()
            ->where('site_inspection_id', $this->inspection->id)
            ->get();
    }

    /** The visit before this one — what the manual's section 5 compares against. */
    #[Computed]
    public function previousVisit(): ?SiteInspection
    {
        return $this->record()->previousOnProject();
    }

    /** @return array<string, string> */
    #[Computed]
    public function outcomeOptions(): array
    {
        return collect(InspectionOutcome::cases())
            ->mapWithKeys(fn (InspectionOutcome $case) => [$case->value => $case->label()])
            ->all();
    }

    /**
     * The named risks an inspector ticks. A fixed vocabulary, because "what
     * went wrong on state projects this quarter" is only answerable if two
     * inspectors describing the same problem pick the same word.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function riskFlagOptions(): array
    {
        return [
            'behind_schedule' => __('Behind schedule'),
            'specification_deviation' => __('Deviation from specification'),
            'structural_defect' => __('Structural or material defect'),
            'safety' => __('Site safety'),
            'abandoned_site' => __('Site abandoned or idle'),
            'community_dispute' => __('Community or land dispute'),
            'environmental' => __('Environmental concern'),
        ];
    }

    #[Computed]
    public function photoCount(): int
    {
        return $this->record()->getMedia('inspection_photos')->count();
    }

    /** Whether the instance demands photographic evidence before filing. */
    #[Computed]
    public function requiresPhoto(): bool
    {
        return app(SettingsRepository::class)->bool('inspections', 'require_photo_evidence', true);
    }

    /** Required checklist items still unanswered — shown live, so nothing is a surprise at submission. */
    #[Computed]
    public function unansweredRequired(): int
    {
        return $this->items()
            ->filter(fn (InspectionChecklistTemplateItem $item): bool => $item->is_required
                && ($this->answers[$item->id] ?? null) === null)
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /* Autosave */
    /* ------------------------------------------------------------------ */

    /**
     * Fires on every field change. No explicit "save draft" button to forget,
     * and no unsaved-work warning to ignore.
     */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'answers.') || str_starts_with($property, 'notes.')) {
            $this->saveAnswer((int) explode('.', $property)[1]);

            return;
        }

        $this->saveNotes();
    }

    public function saveNotes(): void
    {
        $record = $this->record();

        if (! $record->isEditable()) {
            return;
        }

        $this->authorize('conduct', $record);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $saved = app(SaveInspectionFieldNotes::class)($record, $actor, $this->fieldAttributes());
        } catch (InspectionRuleViolation $exception) {
            // A malformed figure must not cost the inspector the paragraph
            // they just typed, so the autosave reports it and keeps the form.
            $this->failure = $exception->getMessage();

            return;
        }

        $this->savedAt = $saved->autosaved_at?->toIso8601String();
        unset($this->record);
    }

    /**
     * One checklist item, saved on its own. Per item rather than per form, so
     * a connection that dies halfway through a forty-question instrument
     * loses one answer.
     */
    public function saveAnswer(int $itemId): void
    {
        $record = $this->record();

        if (! $record->isEditable()) {
            return;
        }

        $this->authorize('conduct', $record);

        $this->failure = null;
        $this->resetErrorBag('answers.'.$itemId);

        try {
            /** @var User $actor */
            $actor = auth()->user();

            app(RecordChecklistResponses::class)($record, $actor, [
                $itemId => [
                    'value' => $this->answers[$itemId] ?? null,
                    'note' => $this->notes[$itemId] ?? null,
                ],
            ]);
        } catch (InspectionRuleViolation $exception) {
            // The commonest case by far is "this answer is a finding and needs
            // a note". Shown against the item itself, where the inspector is
            // already looking, rather than at the top of a long form.
            $this->addError('answers.'.$itemId, $exception->getMessage());

            return;
        }

        $this->savedAt = now()->toIso8601String();
        unset($this->unansweredRequired);
    }

    /**
     * A position reported by the browser's geolocation API. Validated here and
     * re-validated in the Action — a Livewire endpoint takes any payload, and
     * "the inspector was at these coordinates" is a claim that ends up on a
     * government record.
     */
    public function capturePosition(float $latitude, float $longitude, ?float $accuracy = null): void
    {
        $record = $this->record();

        $this->authorize('conduct', $record);

        if (! $record->isEditable()) {
            return;
        }

        $this->positionError = null;
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $saved = app(SaveInspectionFieldNotes::class)($record, $actor, [], [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy === null ? null : (int) round($accuracy),
            ]);
        } catch (InspectionRuleViolation $exception) {
            $this->positionError = $exception->getMessage();

            return;
        }

        $this->latitude = $saved->latitude;
        $this->longitude = $saved->longitude;
        $this->accuracy = $saved->gps_accuracy_metres;
        $this->savedAt = $saved->autosaved_at?->toIso8601String();

        unset($this->record);
    }

    /** The browser refused or could not get a fix. Recorded, not swallowed. */
    public function positionUnavailable(string $reason = ''): void
    {
        $this->positionError = $reason !== ''
            ? $reason
            : __('Your device could not provide a position. The report can still be filed — say in the findings where you were.');
    }

    /* ------------------------------------------------------------------ */
    /* Filing */
    /* ------------------------------------------------------------------ */

    public function submit(SubmitInspectionReport $submit): void
    {
        $record = $this->record();

        $this->authorize('conduct', $record);

        $validated = $this->validate([
            'outcome' => ['required', 'string', 'in:'.implode(',', array_column(InspectionOutcome::cases(), 'value'))],
            'findings' => ['required', 'string', 'min:10', 'max:10000'],
            'peopleMet' => ['nullable', 'string', 'max:5000'],
            'methods' => ['nullable', 'string', 'max:5000'],
            'comparisonWithPrevious' => ['nullable', 'string', 'max:5000'],
            'conclusions' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
        ], [
            'outcome.required' => __('Give the site a verdict. An inspection with no outcome is a visit, not an inspection.'),
            'findings.required' => __('Record what you observed. An empty findings section is a trip, not a report.'),
            'findings.min' => __('A few words at least — this is the section everyone reads.'),
        ]);

        // The typed fields go with the status write, in one transaction, so a
        // submission cannot half-land.
        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $submit($record, $actor, InspectionOutcome::from($validated['outcome']), [
                'people_met' => $this->nullIfBlank($this->peopleMet),
                'methods' => $this->nullIfBlank($this->methods),
                'findings' => trim($this->findings),
                'comparison_with_previous' => $this->nullIfBlank($this->comparisonWithPrevious),
                'conclusions' => $this->nullIfBlank($this->conclusions),
                'recommendations' => $this->nullIfBlank($this->recommendations),
                'risk_flags' => $this->riskFlags === [] ? null : array_values($this->riskFlags),
            ]);
        } catch (InspectionRuleViolation|InvalidInspectionTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        session()->flash('status', __('Report filed. It is now with the M&E office for sign-off — you cannot sign off your own visit.'));

        $this->redirectRoute('tenant.inspections.show', [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            'inspection' => $record,
        ], navigate: true);
    }

    /** @return array<string, mixed> */
    private function fieldAttributes(): array
    {
        return [
            'team' => $this->nullIfBlank($this->team),
            'objectives' => $this->nullIfBlank($this->objectives),
            'people_met' => $this->nullIfBlank($this->peopleMet),
            'methods' => $this->nullIfBlank($this->methods),
            'findings' => $this->nullIfBlank($this->findings),
            'comparison_with_previous' => $this->nullIfBlank($this->comparisonWithPrevious),
            'conclusions' => $this->nullIfBlank($this->conclusions),
            'recommendations' => $this->nullIfBlank($this->recommendations),
            'physical_progress_observed' => trim($this->physicalProgressObserved),
            // tryFrom, not from: this runs on the AUTOSAVE path, before any
            // validation, and a Livewire endpoint takes any payload — a value
            // that is not a verdict is no verdict, not a 500 that costs the
            // inspector the paragraph they just typed. submit() validates the
            // same field with `in:` and refuses it properly there.
            'outcome' => InspectionOutcome::tryFrom($this->outcome),
            'risk_flags' => $this->riskFlags === [] ? null : array_values($this->riskFlags),
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    public function render(): View
    {
        return view('livewire.tenant.inspections.inspection-conduct', [
            'responseTypes' => ChecklistResponseType::class,
        ]);
    }
}
