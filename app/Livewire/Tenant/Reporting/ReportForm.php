<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Actions\Reporting\SaveProgressReportDraft;
use App\Actions\Reporting\StartProgressReport;
use App\Actions\Reporting\SubmitProgressReport;
use App\Exceptions\Reporting\InvalidReportTransition;
use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\User;
use App\Support\Money;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The reporting wizard (progress-reporting.md §6): window & project → work done,
 * progress and spend → challenges and mitigation → review and file.
 *
 * AUTOSAVE IS THE POINT. A field consultant on a bad connection typing three
 * paragraphs about a culvert must not lose them, so the draft row IS the
 * autosave target: step 1 calls StartProgressReport, which creates (or
 * re-opens) the one live return for that window, and every later change goes
 * through SaveProgressReportDraft. There is no session-held shadow copy and no
 * second storage shape — the reviewer reads exactly the row the author typed
 * into.
 *
 * The component orchestrates and validates SHAPE. Every domain rule — the
 * window being open, the narrative being present, an unexplained downward
 * revision, the whole approval chain — belongs to the Actions and is enforced
 * there even if this form is bypassed entirely. Where an Action refuses, its
 * message is surfaced verbatim rather than paraphrased: the officer needs the
 * real reason, not the form's guess at it.
 */
#[Layout('layouts::tenant')]
class ReportForm extends Component
{
    public const LAST_STEP = 4;

    public int $step = 1;

    /** Set once the draft exists; from then on this screen edits a real row. */
    public ?string $reportUlid = null;

    /** Deep-link targets from the reporting desk's "File it" button. */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    // Step 2 — the figures
    public string $narrative_work_done = '';

    public string $physical_progress_claimed = '';

    public string $period_expenditure = '';

    public string $progress_decrease_reason = '';

    // Step 3 — the story around them
    public string $narrative_challenges = '';

    public string $narrative_mitigation = '';

    public string $narrative_next_period = '';

    public ?string $savedAt = null;

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(?ProgressReport $report = null): void
    {
        $this->authorize('create', ProgressReport::class);

        // The /reports/{report}/edit entry point: resume an existing draft.
        if ($report !== null && $report->exists) {
            $this->authorize('update', $report);

            $this->bindTo($report);
            $this->step = 2;

            return;
        }

        // Deep link from the desk with both halves already chosen — skip the
        // picker rather than making the officer re-answer a question they
        // answered by clicking "File it".
        if ($this->projectUlid !== '' && $this->periodId !== '') {
            $this->start();
        }
    }

    private function bindTo(ProgressReport $report): void
    {
        $this->reportUlid = $report->ulid;
        $this->projectUlid = $report->loadMissing('project')->project->ulid;
        $this->periodId = (string) $report->reporting_period_id;

        $this->narrative_work_done = $report->narrative_work_done;
        $this->narrative_challenges = (string) $report->narrative_challenges;
        $this->narrative_mitigation = (string) $report->narrative_mitigation;
        $this->narrative_next_period = (string) $report->narrative_next_period;
        $this->physical_progress_claimed = $report->physical_progress_claimed;
        $this->period_expenditure = $report->period_expenditure->toDecimalString();
        $this->progress_decrease_reason = (string) $report->progress_decrease_reason;
        $this->savedAt = $report->autosaved_at?->toIso8601String();
    }

    /**
     * The live draft this screen is editing, if step 1 has been passed.
     * Re-read each time rather than held as a serialized model: a Livewire
     * component's public state crosses the wire, and a tenant-owned model does
     * not belong there.
     */
    #[Computed]
    public function report(): ?ProgressReport
    {
        if ($this->reportUlid === null) {
            return null;
        }

        return ProgressReport::query()
            ->with(['project', 'reportingPeriod'])
            ->where('ulid', $this->reportUlid)
            ->first();
    }

    /**
     * Step 1 → 2: open (or re-open) the one live return for this window.
     */
    public function start(): void
    {
        $this->validate([
            'projectUlid' => ['required', 'string'],
            'periodId' => ['required'],
        ], attributes: [
            'projectUlid' => __('project'),
            'periodId' => __('reporting window'),
        ]);

        $project = $this->projects()->firstWhere('ulid', $this->projectUlid);
        $period = $this->periods()->firstWhere('id', (int) $this->periodId);

        if ($project === null || $period === null) {
            // The option lists ARE the constraint — validating against exactly
            // what the form renders means what it offers and what it accepts
            // cannot drift, and a consultant cannot name a project they are not
            // assigned to by editing the payload.
            $this->addError('projectUlid', __('Choose a project and a window from the lists.'));

            return;
        }

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $report = app(StartProgressReport::class)($project, $period, $actor);
        } catch (ReportRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        $this->bindTo($report);
        $this->step = 2;
    }

    /**
     * Autosave. Fires on every field change from step 2 onward — no explicit
     * "save draft" button to forget, and no unsaved-work warning to ignore.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['projectUlid', 'periodId'], true)) {
            return;
        }

        $this->saveDraft();
    }

    public function saveDraft(): void
    {
        $report = $this->report();

        if ($report === null || ! $report->isEditable()) {
            return;
        }

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $saved = app(SaveProgressReportDraft::class)($report, $actor, $this->draftAttributes());
        } catch (ReportRuleViolation $exception) {
            // A malformed figure must not cost the officer the paragraph they
            // just typed, so the autosave reports it and keeps the form.
            $this->failure = $exception->getMessage();

            return;
        }

        $this->savedAt = $saved->autosaved_at?->toIso8601String();
        unset($this->report);
    }

    /** @return array<string, mixed> */
    private function draftAttributes(): array
    {
        return [
            'narrative_work_done' => trim($this->narrative_work_done),
            'narrative_challenges' => $this->nullIfBlank($this->narrative_challenges),
            'narrative_mitigation' => $this->nullIfBlank($this->narrative_mitigation),
            'narrative_next_period' => $this->nullIfBlank($this->narrative_next_period),
            'physical_progress_claimed' => trim($this->physical_progress_claimed),
            'progress_decrease_reason' => $this->nullIfBlank($this->progress_decrease_reason),
            'period_expenditure' => trim($this->period_expenditure) === '' ? '0.00' : trim($this->period_expenditure),
        ];
    }

    /** @return array<string, mixed> */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            2 => [
                'narrative_work_done' => ['required', 'string', 'min:20', 'max:5000'],
                'physical_progress_claimed' => ['required', 'numeric', 'between:0,100'],
                'period_expenditure' => ['required', 'numeric', Money::FORM_RULE, 'min:0', 'max:9999999999999.99'],
                // Mirrors the Action's guard so the officer learns about it on
                // the step that caused it, not three screens later. The Action
                // still enforces it for every other caller.
                'progress_decrease_reason' => [
                    $this->claimIsBelowRecordedProgress() ? 'required' : 'nullable',
                    'string', 'max:2000',
                ],
            ],
            3 => [
                'narrative_challenges' => ['nullable', 'string', 'max:5000'],
                'narrative_mitigation' => ['nullable', 'string', 'max:5000'],
                'narrative_next_period' => ['nullable', 'string', 'max:5000'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'narrative_work_done' => __('account of work done'),
            'physical_progress_claimed' => __('physical progress'),
            'period_expenditure' => __('expenditure this period'),
            'progress_decrease_reason' => __('reason for the reduction'),
            'narrative_challenges' => __('challenges'),
            'narrative_mitigation' => __('mitigation'),
            'narrative_next_period' => __('plan for next period'),
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'progress_decrease_reason.required' => __('This claim is below the progress already recorded for the project. Say why it has been revised down — re-measurement, defective work removed — so the correction is on the record.'),
            'narrative_work_done.min' => __('Describe the work actually done in this period. A reviewer cannot verify “ongoing”.'),
        ];
    }

    /** Whether the claim would take the project's recorded progress backwards. */
    public function claimIsBelowRecordedProgress(): bool
    {
        $report = $this->report();

        if ($report === null || ! is_numeric($this->physical_progress_claimed)) {
            return false;
        }

        // Integer basis points — the comparison never touches floating point.
        $claimed = (int) round(((float) $this->physical_progress_claimed) * 100);
        $current = (int) round(((float) $report->loadMissing('project')->project->physical_progress) * 100);

        return $claimed < $current;
    }

    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step));
        $this->saveDraft();

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function back(): void
    {
        // Never back past step 2: step 1 chose the window, and the draft row
        // for it already exists.
        $this->step = max($this->step - 1, $this->reportUlid === null ? 1 : 2);
    }

    public function goToStep(int $step): void
    {
        // Backwards only — skipping ahead past unvalidated fields is how a
        // wizard fails on its last screen.
        if ($step < $this->step && $step >= ($this->reportUlid === null ? 1 : 2)) {
            $this->step = $step;
        }
    }

    /**
     * File it. Everything that makes a submission legal is asserted by the
     * Action; this only stops a doomed round trip and surfaces the refusal.
     */
    public function submit(): mixed
    {
        $report = $this->report();

        if ($report === null) {
            $this->failure = __('Start the report before filing it.');

            return null;
        }

        $this->authorize('submit', $report);

        $this->validate(array_merge($this->rulesForStep(2), $this->rulesForStep(3)));
        $this->saveDraft();

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            app(SubmitProgressReport::class)($report->refresh(), $actor);
        } catch (ReportRuleViolation|InvalidReportTransition $exception) {
            // The Action's own words, not the form's paraphrase of them: the
            // officer needs to know it was the window, or the claim, or the
            // narrative — a generic "could not submit" sends them guessing.
            $this->failure = $exception->getMessage();

            return null;
        }

        session()->flash('status', __('Progress report filed for :window.', [
            'window' => $report->reportingPeriod->label,
        ]));

        // route(), not a hand-built string: a named route fails loudly if the
        // path or the binding key ever changes, where a string would silently
        // land the author on a 404 immediately after filing.
        return $this->redirect($this->reportUrl($report), navigate: true);
    }

    /**
     * The workspace-qualified link to a filed return. The tenant surface lives
     * on a {tenant} subdomain, so the slug is passed explicitly rather than
     * relying on a URL default set by request middleware — this method is also
     * reached from a Livewire update, and a link is not worth a dependency on
     * which middleware stack happened to run.
     */
    private function reportUrl(ProgressReport $report): string
    {
        return route('tenant.reports.show', [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            'report' => $report->ulid,
        ]);
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Projects this user may report on. `visibleTo()` is the single definition
     * of project visibility, so a consultant is offered exactly the projects
     * they are assigned to — and the same list validates the submission.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        $reportable = app(SettingsRepository::class)->strings(
            'reporting',
            'obligation_statuses',
            ['mobilized', 'in_progress', 'completed'],
        );

        // WHOLE models, not a column list. These are not display rows: the
        // chosen one is handed straight to StartProgressReport, and an Action
        // that reads `status` from a model selected without it gets null. A
        // partial select is an optimisation on a list that never leaves the
        // screen — this one does.
        return Project::query()
            ->visibleTo($user)
            ->whereIn('status', $reportable)
            ->orderBy('title')
            ->get();
    }

    /**
     * Windows open for submission. A closed window is offered only where the
     * instance accepts late returns — otherwise it is an option that can only
     * produce a refusal.
     *
     * @return Collection<int, ReportingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        $allowLate = app(SettingsRepository::class)->bool('reporting', 'allow_late_submission', true);

        return ReportingPeriod::query()
            ->where('opens_at', '<=', now())
            ->when(! $allowLate, fn (Builder $q) => $q
                ->where(fn (Builder $inner) => $inner->whereNull('closes_at')->orWhere('closes_at', '>', now())))
            ->orderByDesc('period_start')
            ->limit(24)
            // Whole models for the same reason as projects() above — the
            // chosen window is handed to an Action, not merely rendered.
            ->get();
    }

    /**
     * The obligation this window/project pair answers, if the deadline engine
     * has generated one — shown so the author knows what they are answering.
     */
    #[Computed]
    public function obligation(): ?ReportObligation
    {
        $report = $this->report();

        return $report?->loadMissing('obligation')->obligation;
    }

    /** @return list<array{label: string, description: string}> */
    public function steps(): array
    {
        return [
            ['label' => __('Window & project'), 'description' => __('What you are reporting on')],
            ['label' => __('Work & figures'), 'description' => __('Progress and spend')],
            ['label' => __('Challenges'), 'description' => __('Issues and mitigation')],
            ['label' => __('Review & file'), 'description' => __('Check, then submit')],
        ];
    }

    public function render(): View
    {
        return view('livewire.tenant.reporting.report-form');
    }
}
