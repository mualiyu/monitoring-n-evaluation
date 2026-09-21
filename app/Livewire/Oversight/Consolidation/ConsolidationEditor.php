<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Consolidation;

use App\Actions\Consolidation\ApproveConsolidation;
use App\Actions\Consolidation\CompileConsolidatedFigures;
use App\Actions\Consolidation\PublishConsolidation;
use App\Actions\Consolidation\RecordConsolidationSection;
use App\Actions\Consolidation\ReturnConsolidation;
use App\Actions\Consolidation\SubmitConsolidationForReview;
use App\Enums\ConsolidationStatus;
use App\Enums\ExportFormat;
use App\Exceptions\Consolidation\ConsolidationRuleViolation;
use App\Exceptions\Consolidation\InvalidConsolidationTransition;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidationEvent;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Exporting\ReportExporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * ONE consolidation, end to end: compile the figures, write the narrative
 * around them, send it up, sign it off — which FREEZES it — and publish.
 *
 * One screen rather than four, because the officer writing the portfolio
 * chapter is reading the portfolio figures in the same minute, and the
 * director signing reads the chapter and the annex together. Splitting them
 * would mean four routes that each have to re-answer "may you see this".
 *
 * EVERY MUTATING METHOD AUTHORIZES AGAIN. Route middleware does not gate a
 * Livewire update POST by itself, and hiding a button is a courtesy rather
 * than a control. The domain rules — an impossible transition, figures before
 * review, a summary before review, and above all the separation of the
 * compiler from the approver — belong to
 * App\Actions\Consolidation\TransitionConsolidationStatus. Here they only
 * decide whether a button renders; when the Action refuses anyway, its own
 * words are shown verbatim rather than paraphrased into a shrug.
 *
 * ConsolidatedReport is GLOBAL (see the model), so this screen needs no
 * tenancy bypass at all. The cross-MDA read that produces the figures lives in
 * app/Actions/Oversight/AggregateForConsolidation, behind the authorization
 * that justifies it.
 */
#[Layout('layouts::oversight')]
class ConsolidationEditor extends Component
{
    public ConsolidatedReport $report;

    /**
     * The narrative being edited, keyed by the STABLE skeleton key the type
     * fixes — never by position, because a chapter's place in the outline is
     * presentation and its key is a contract.
     *
     * @var array<string, string>
     */
    public array $sections = [];

    /* Sending it back for rework. */
    public bool $returning = false;

    public string $returnReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    /** A queued export, or another outcome worth stating without alarm. */
    public ?string $notice = null;

    public function mount(ConsolidatedReport $consolidatedReport): void
    {
        $this->authorize('view', $consolidatedReport);

        $this->report = $consolidatedReport;

        $this->readNarrativeIntoForm();
    }

    /**
     * The editable copy of the narrative. Rebuilt whenever the record moves,
     * so a screen left open across a transition does not keep offering a draft
     * of text that has since been signed.
     *
     * NOT named hydrateSections(): Livewire reserves `hydrate{Property}` for
     * its own lifecycle hooks, so that name would be called on every request
     * with hook arguments — and, being private, would blow up as "method does
     * not exist" before the screen ever rendered.
     */
    private function readNarrativeIntoForm(): void
    {
        $this->report->loadMissing('sections');

        $this->sections = [];

        foreach ($this->report->type->sectionSkeleton() as $key => $heading) {
            $section = $this->report->sections->firstWhere('key', $key);

            // `??` already short-circuits a null $section, so a nullsafe
            // arrow here would be redundant rather than defensive.
            $this->sections[$key] = (string) ($section->body ?? '');
        }
    }

    /* ------------------------------------------------------------------ */
    /* What this actor may do */
    /* ------------------------------------------------------------------ */

    public function canCompile(): bool
    {
        return $this->user()->can('compile', $this->report);
    }

    public function canEditNarrative(): bool
    {
        return $this->user()->can('update', $this->report);
    }

    public function canSubmit(): bool
    {
        return $this->report->status === ConsolidationStatus::Compiling
            && $this->user()->can('submit', $this->report);
    }

    /**
     * The approve button appears only where this actor could actually
     * succeed. The two guards are the separation rule made visible: an officer
     * who compiled the figures, or who sent them up, must not be offered the
     * button that signs them off. The rule itself is enforced in the
     * chokepoint — this is the courtesy, not the control.
     */
    public function canApprove(): bool
    {
        $user = $this->user();

        return $this->report->status === ConsolidationStatus::InReview
            && $user->can('approve', $this->report)
            && $this->report->compiled_by_id !== $user->id
            && $this->report->submitted_by_id !== $user->id;
    }

    public function canReturn(): bool
    {
        return $this->report->status === ConsolidationStatus::InReview
            && $this->user()->can('return', $this->report);
    }

    public function canPublish(): bool
    {
        return $this->report->status === ConsolidationStatus::Approved
            && $this->user()->can('publish', $this->report);
    }

    public function canExport(): bool
    {
        return $this->user()->can('export', $this->report);
    }

    /**
     * Why the decision panel is empty, when it is. A panel that simply
     * disappears reads as a bug; naming the reason turns a dead end into an
     * explanation an officer can act on.
     */
    public function blockedReason(): ?string
    {
        $user = $this->user();

        if ($this->report->status === ConsolidationStatus::Published) {
            return __('This consolidation has been published. A figure quoted outside the platform is corrected by issuing the next consolidation, never by editing the one people already hold.');
        }

        if ($this->report->status === ConsolidationStatus::InReview
            && $this->report->compiled_by_id === $user->id
            && $user->can('approve', $this->report)) {
            return __('You compiled these figures, so you cannot sign them off. Separation of compilation from approval is what makes a state report an assurance rather than an assertion.');
        }

        if ($this->report->status === ConsolidationStatus::InReview
            && $this->report->submitted_by_id === $user->id
            && $user->can('approve', $this->report)) {
            return __('You sent this consolidation up the chain, so it needs a second pair of eyes to approve it.');
        }

        if (! $this->canCompile() && ! $this->canSubmit() && ! $this->canApprove()
            && ! $this->canReturn() && ! $this->canPublish()) {
            return __('You may read this consolidation, but no step of its chain is yours.');
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Reads */
    /* ------------------------------------------------------------------ */

    /**
     * The append-only chain, oldest first — who compiled, who sent it up, who
     * signed. Read from the ledger rather than from the record's own columns,
     * because a consolidation returned twice has two events and one
     * `submitted_at`.
     *
     * @return Collection<int, ConsolidationEvent>
     */
    #[Computed]
    public function timeline(): Collection
    {
        return ConsolidationEvent::query()
            ->where('consolidated_report_id', $this->report->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * THE figures this consolidation states — frozen once signed, live before
     * then. Read through the model's accessor rather than off the columns, so
     * that this screen and the PDF can never disagree about an approved
     * report.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function figures(): array
    {
        return $this->report->figures();
    }

    /**
     * The per-entity annex, same rule.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function entities(): array
    {
        return $this->report->entityFigures();
    }

    /**
     * Artifacts generated FROM this consolidation. Shown here as well as in
     * the register because "what was handed out, and which version of the
     * figures it carried" is a question asked of the report, not of the
     * register.
     *
     * @return Collection<int, ReportExport>
     */
    #[Computed]
    public function artifacts(): Collection
    {
        return ReportExport::query()
            ->where('consolidated_report_id', $this->report->id)
            ->with('generatedBy:id,name')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /** Entities that answered, as a share of those expected to. */
    public function coverageRate(): ?float
    {
        return $this->report->coverageRate();
    }

    /* ------------------------------------------------------------------ */
    /* Compiling */
    /* ------------------------------------------------------------------ */

    /**
     * Run (or re-run) the cross-MDA roll-up.
     *
     * Inline rather than queued: the compile is a handful of grouped queries
     * and the officer is standing in front of the result. The queued path
     * (App\Jobs\Consolidation\CompileConsolidation) exists for the scheduled
     * and console callers, and is idempotent with this one.
     */
    public function compile(CompileConsolidatedFigures $compile): void
    {
        $this->authorize('compile', $this->report);
        $this->failure = null;
        $this->notice = null;

        try {
            $compile($this->report, $this->user());
        } catch (ConsolidationRuleViolation|InvalidConsolidationTransition|AuthorizationException $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Figures compiled from every entity’s returns for this window.'));
    }

    /* ------------------------------------------------------------------ */
    /* The narrative */
    /* ------------------------------------------------------------------ */

    /** Write one chapter. */
    public function saveSection(RecordConsolidationSection $record, string $key): void
    {
        $this->authorize('update', $this->report);
        $this->failure = null;

        if (! array_key_exists($key, $this->sections)) {
            return;
        }

        $this->validate(
            ["sections.{$key}" => ['nullable', 'string', 'max:100000']],
            attributes: ["sections.{$key}" => __('chapter')],
        );

        try {
            $record($this->report, $key, $this->sections[$key], $this->user());
        } catch (ConsolidationRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Chapter saved.'));
    }

    /** Write every chapter in one pass — the "save the lot" button. */
    public function saveNarrative(RecordConsolidationSection $record): void
    {
        $this->authorize('update', $this->report);
        $this->failure = null;

        $this->validate(
            ['sections.*' => ['nullable', 'string', 'max:100000']],
            attributes: ['sections.*' => __('chapter')],
        );

        try {
            foreach ($this->sections as $key => $body) {
                $record($this->report, $key, $body, $this->user());
            }
        } catch (ConsolidationRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Narrative saved.'));
    }

    /* ------------------------------------------------------------------ */
    /* The chain */
    /* ------------------------------------------------------------------ */

    public function submitForReview(SubmitConsolidationForReview $submit): void
    {
        $this->authorize('submit', $this->report);
        $this->failure = null;

        try {
            $submit($this->report, $this->user());
        } catch (ConsolidationRuleViolation|InvalidConsolidationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Sent up the chain. You cannot approve a consolidation you sent up yourself.'));
    }

    public function startReturn(): void
    {
        $this->authorize('return', $this->report);

        $this->returning = true;
        $this->returnReason = '';
        $this->resetValidation();
    }

    public function cancelReturn(): void
    {
        $this->returning = false;
        $this->returnReason = '';
        $this->resetValidation();
    }

    public function confirmReturn(ReturnConsolidation $return): void
    {
        $this->authorize('return', $this->report);
        $this->failure = null;

        $this->validate([
            'returnReason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'returnReason.required' => __('Say what has to be fixed. A secretariat told only “returned” is guessing at what a Commissioner objected to.'),
            'returnReason.min' => __('Give the secretariat enough to work with — a sentence at least.'),
        ], ['returnReason' => __('reason')]);

        $this->returning = false;

        try {
            $return($this->report, $this->user(), $this->returnReason);
        } catch (ConsolidationRuleViolation|InvalidConsolidationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->returnReason = '';

        $this->refresh(__('Returned to the secretariat with your reason. The figures are editable again.'));
    }

    /**
     * Sign it off — and freeze it. Everything the signature covers is copied
     * onto the row at this instant, and every screen and template reads the
     * snapshot from here on.
     */
    public function approve(ApproveConsolidation $approve): void
    {
        $this->authorize('approve', $this->report);
        $this->failure = null;

        try {
            $approve($this->report, $this->user());
        } catch (ConsolidationRuleViolation|InvalidConsolidationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Approved. The figures are frozen as they stood at this moment — a later edit to an entity’s return cannot move them.'));
    }

    public function publish(PublishConsolidation $publish): void
    {
        $this->authorize('publish', $this->report);
        $this->failure = null;

        try {
            $publish($this->report, $this->user());
        } catch (ConsolidationRuleViolation|InvalidConsolidationTransition $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->refresh(__('Published. This consolidation is now readable outside the secretariat.'));
    }

    /* ------------------------------------------------------------------ */
    /* Artifacts */
    /* ------------------------------------------------------------------ */

    /**
     * Generate the document (or its spreadsheet annex) and hand it over.
     *
     * The file never reaches the browser from here: ReportExporter stores it
     * on the private disk and registers who asked for it, and the download
     * goes through the signed, policy-checked route — the same three gates the
     * document vault applies.
     */
    public function export(ReportExporter $exporter, string $format): void
    {
        $this->authorize('export', $this->report);
        $this->failure = null;
        $this->notice = null;

        $chosen = ExportFormat::tryFrom($format);

        if ($chosen === null) {
            $this->failure = __('That is not a format this platform writes.');

            return;
        }

        try {
            $export = $exporter->consolidation($this->report, $this->user(), $chosen);
        } catch (AuthorizationException $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        unset($this->artifacts);

        if (! $export->isDownloadable()) {
            $this->notice = __('The artifact is being generated. It will appear in the export register when it is ready.');

            return;
        }

        $this->redirect($exporter->downloadUrl($export));
    }

    /** Re-download an artifact already in the register. */
    public function download(ReportExporter $exporter, string $ulid): void
    {
        $export = ReportExport::query()
            ->where('consolidated_report_id', $this->report->id)
            ->where('ulid', $ulid)
            ->firstOrFail();

        $this->authorize('download', $export);

        $this->redirect($exporter->downloadUrl($export));
    }

    /* ------------------------------------------------------------------ */

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Re-read the record and rebuild the forms.
     *
     * Re-queried through the model rather than with fresh(): fresh() is
     * newQueryWithoutScopes(), an unscoped read wearing innocuous clothing.
     * Nothing here is tenant-owned, but the habit is the point — the next
     * person to copy this screen may be copying it onto a model that is.
     */
    private function refresh(string $message): void
    {
        $this->report = ConsolidatedReport::query()
            ->with(['reportingPeriod', 'sections', 'entries.subject:id,name,slug'])
            ->whereKey($this->report->getKey())
            ->firstOrFail();

        $this->readNarrativeIntoForm();

        unset($this->timeline, $this->figures, $this->entities, $this->artifacts);

        session()->flash('status', $message);
    }

    public function render(): View
    {
        // Eager-loaded here rather than in mount(): route-model binding hands
        // over a bare model, and lazy loading is prevented outside production.
        $this->report->loadMissing([
            'reportingPeriod',
            'sections',
            'entries.subject:id,name,slug',
            'createdBy:id,name',
            'compiledBy:id,name',
            'submittedBy:id,name',
            'approvedBy:id,name',
            'publishedBy:id,name',
        ]);

        return view('livewire.oversight.consolidation.consolidation-editor');
    }
}
