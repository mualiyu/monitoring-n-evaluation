<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Exceptions\Inspections\InvalidInspectionTransition;
use App\Jobs\Inspections\NotifyInspectionChain;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of SiteInspection::$status and of the whole lifecycle
 * chain. Nothing else in the codebase assigns those columns — which is why
 * none of them is fillable, why this class is greppable as the one chokepoint,
 * and why the separation guard below cannot be routed around by a form payload
 * or by a second entry path.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the lifecycle table (an impossible move is impossible for everyone),
 *   2. authorization (per TARGET status — see abilityFor()),
 *   3. domain preconditions (a reason was given; findings and a verdict exist;
 *      the required checklist items are answered; photo evidence exists where
 *      the instance demands it),
 *   4. SEPARATION OF DUTIES — the inspector never reviews their own inspection,
 *   5. the write + the typed ledger row, in one transaction under a row lock,
 *   6. the queued, tenant-aware notification, once the transaction has closed.
 *
 * On the separation guard specifically. The permission matrix already stops a
 * FieldMonitor reviewing anything — they hold `inspections.conduct` and never
 * `inspections.review`. That is NOT sufficient, and this guard is not
 * belt-and-braces: an M&E officer holds BOTH permissions, routinely conducts
 * visits themselves in a small MDA, and would otherwise file and sign off the
 * same report. The whole evidentiary value of an inspection is that a second
 * person looked. The guard is therefore a domain rule here, not a permission
 * question there, and a state cannot switch it off in config — unlike the
 * reporting module's `require_separate_approver`, because a progress report is
 * the MDA's own claim while an inspection is the assurance over it.
 */
class TransitionInspectionStatus
{
    /**
     * @param  array<string, mixed>  $extraChanges  columns the calling Action
     *                                              owns (the verdict at
     *                                              submission, notes at
     *                                              review); never `status`.
     */
    public function __invoke(
        SiteInspection $inspection,
        InspectionStatus $to,
        User $actor,
        ?string $reason = null,
        array $extraChanges = [],
    ): SiteInspection {
        $from = $inspection->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidInspectionTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($to), $inspection);

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($inspection, $to, $reason, $extraChanges);
        $this->assertSeparation($inspection, $to, $actor);

        $changes = [
            ...$extraChanges,
            ...$this->chainStamps($inspection, $to, $actor, $reason),
            'status' => $to,
        ];

        DB::transaction(function () use ($inspection, $from, $to, $actor, $reason, $changes): void {
            // Re-read under the lock before writing. Two officers with the
            // detail screen open can both press "Sign off"; the second must
            // find the row already moved rather than overwrite the first
            // signature. A stale in-memory status would otherwise pass the
            // table check above and then clobber the real one.
            $locked = SiteInspection::query()->lockForUpdate()->find($inspection->getKey());

            if ($locked === null || $locked->status !== $from) {
                throw InvalidInspectionTransition::between($locked->status ?? $from, $to);
            }

            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $inspection->forceFill($changes)->save();

            $event = new SiteInspectionEvent([
                'site_inspection_id' => $inspection->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            // Explicit property write (tenant_id is not fillable): the
            // inspection itself is always the authority on which MDA this
            // belongs to, and a console/oversight caller may hold no bound
            // tenant.
            $event->tenant_id = $inspection->tenant_id;
            $event->save();
        });

        // Dispatched after the write closes, never inside it. The job re-reads
        // the row and is idempotent.
        NotifyInspectionChain::dispatch($inspection->id, $to->value, $actor->id, $reason);

        return $inspection;
    }

    /**
     * Who did this step and when — the CURRENT chain state that lists and the
     * detail header read. The history lives in site_inspection_events.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(
        SiteInspection $inspection,
        InspectionStatus $to,
        User $actor,
        ?string $reason,
    ): array {
        $now = now();

        return match ($to) {
            InspectionStatus::InProgress => [
                'started_at' => $now,
                // The visit happened NOW, whatever the diary said. A field
                // monitor who arrives three days late has still inspected the
                // site on the day they were there, and a report dated by the
                // plan rather than by the visit is a falsified record.
                'conducted_at' => $now,
                // The report clock starts at the visit, not at the plan, and
                // is snapshotted so a later policy change cannot retroactively
                // make a filed report late.
                'report_due_at' => $now->copy()->addDays($this->reportDueDays())->endOfDay(),
            ],
            InspectionStatus::Submitted => [
                'submitted_by_id' => $actor->id,
                'submitted_at' => $now,
                // Judged once, at filing, and never recomputed.
                'report_late' => $inspection->report_due_at !== null
                    && $inspection->report_due_at->isBefore($now),
            ],
            InspectionStatus::Reviewed => [
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => $now,
            ],
            InspectionStatus::Cancelled => [
                'cancelled_by_id' => $actor->id,
                'cancelled_at' => $now,
                'cancellation_reason' => $reason,
            ],
            InspectionStatus::Scheduled => [],
        };
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: opening the form and filing the report are
     * the inspector's acts, signing off is the M&E officer's, and cancelling a
     * visit is a scheduling decision.
     */
    private function abilityFor(InspectionStatus $to): string
    {
        return match ($to) {
            InspectionStatus::InProgress, InspectionStatus::Submitted => 'conduct',
            InspectionStatus::Reviewed => 'review',
            InspectionStatus::Cancelled => 'cancel',
            InspectionStatus::Scheduled => 'update',
        };
    }

    /**
     * @param  array<string, mixed>  $extraChanges
     */
    private function assertPreconditions(
        SiteInspection $inspection,
        InspectionStatus $to,
        ?string $reason,
        array $extraChanges,
    ): void {
        if ($to === InspectionStatus::Cancelled && ($reason === null || $reason === '')) {
            throw InspectionRuleViolation::reasonRequired();
        }

        if ($to !== InspectionStatus::Submitted) {
            return;
        }

        // The verdict may arrive in this call (SubmitInspectionReport passes
        // it) or already be on the row from an autosave — either is fine, but
        // one of them must be true.
        $outcome = $extraChanges['outcome'] ?? $inspection->outcome;

        if (! $outcome instanceof InspectionOutcome) {
            throw InspectionRuleViolation::outcomeRequired();
        }

        if (trim((string) ($extraChanges['findings'] ?? $inspection->findings)) === '') {
            throw InspectionRuleViolation::findingsRequired();
        }

        $this->assertChecklistComplete($inspection);
        $this->assertPhotoEvidence($inspection);
    }

    /**
     * Every REQUIRED item on the instrument answered. Optional items are
     * genuinely optional — an instrument where everything is mandatory is one
     * inspectors learn to fill with zeroes.
     */
    private function assertChecklistComplete(SiteInspection $inspection): void
    {
        $template = $inspection->loadMissing('template.items')->template;

        if ($template === null) {
            return;
        }

        $answered = $inspection->responses()
            ->pluck('inspection_checklist_template_item_id')
            ->all();

        $missing = $template->items
            ->filter(fn ($item): bool => $item->is_required && ! in_array($item->id, $answered, true))
            ->count();

        if ($missing > 0) {
            throw InspectionRuleViolation::requiredResponsesMissing($missing);
        }
    }

    /**
     * `inspections.require_photo_evidence` — read through SettingsRepository,
     * so a state that inspects sites with no mobile coverage can turn it off
     * without a release.
     */
    private function assertPhotoEvidence(SiteInspection $inspection): void
    {
        if (! app(SettingsRepository::class)->bool('inspections', 'require_photo_evidence', true)) {
            return;
        }

        if ($inspection->getMedia('inspection_photos')->isEmpty()) {
            throw InspectionRuleViolation::photoEvidenceRequired();
        }
    }

    /**
     * Separation of duties. The lead inspector and whoever actually filed the
     * report are both barred from signing it off — those can differ when a
     * team member files for the lead, and either identity is disqualifying.
     */
    private function assertSeparation(SiteInspection $inspection, InspectionStatus $to, User $actor): void
    {
        if ($to !== InspectionStatus::Reviewed) {
            return;
        }

        if ($inspection->lead_inspector_id === $actor->id || $inspection->submitted_by_id === $actor->id) {
            throw InspectionRuleViolation::reviewerIsInspector();
        }
    }

    private function reportDueDays(): int
    {
        return app(SettingsRepository::class)->int('inspections', 'report_due_days', 3);
    }
}
