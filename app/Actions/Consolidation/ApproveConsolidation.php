<?php

namespace App\Actions\Consolidation;

use App\Enums\ConsolidationStatus;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidatedReportEntry;
use App\Models\User;

/**
 * Sign off the roll-up — AND FREEZE IT.
 *
 * THE SNAPSHOT IS THE POINT OF THIS ACTION. Up to this moment the report's
 * figures are live: recompiling picks up whatever the MDAs have filed since.
 * At approval that has to stop, because an Annual Performance Report whose
 * numbers silently change when an MDA edits last quarter's return is not an
 * APR — it is a dashboard with a title page, and every copy already printed,
 * quoted in a budget defence or cited by the House becomes unverifiable.
 *
 * So the signature copies everything the signature covers onto the row itself:
 * the window, the totals, every entity's figures and every narrative chapter
 * as they read at this instant. ConsolidatedReport::figures() and every export
 * template then read the SNAPSHOT once the report is frozen, never the live
 * tables — one accessor, because a screen reading live totals off an approved
 * report while the PDF reads the snapshot is precisely the defect the snapshot
 * exists to prevent.
 *
 * The separation guard — the compiler and the submitter may not be the
 * approver — is enforced in TransitionConsolidationStatus, where the chain
 * history can be read.
 */
class ApproveConsolidation
{
    /** Bumped when the snapshot's shape changes, so an old artifact stays readable. */
    public const SNAPSHOT_VERSION = 1;

    public function __invoke(ConsolidatedReport $report, User $actor): ConsolidatedReport
    {
        $snapshot = $this->snapshot($report, $actor);

        return app(TransitionConsolidationStatus::class)(
            $report,
            ConsolidationStatus::Approved,
            $actor,
            null,
            [
                'snapshot' => $snapshot,
                'snapshot_taken_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ConsolidatedReport $report, User $actor): array
    {
        // loadMissing, not the bare accessors: preventLazyLoading is on
        // outside production, and the one place that must never depend on its
        // caller's eager set is the one that freezes the record.
        $report->loadMissing(['reportingPeriod', 'sections', 'entries.subject']);
        $period = $report->reportingPeriod;

        return [
            'version' => self::SNAPSHOT_VERSION,
            'taken_at' => now()->toIso8601String(),
            'taken_by' => ['id' => $actor->id, 'name' => $actor->name],
            'report' => [
                'reference' => $report->reference,
                'title' => $report->title,
                'type' => $report->type->value,
                'type_label' => $report->type->label(),
            ],
            'period' => [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'cadence' => $period->cadence->value,
                'period_start' => $period->period_start->toDateString(),
                'period_end' => $period->period_end->toDateString(),
                'due_at' => $period->due_at->toIso8601String(),
            ],
            'totals' => $report->totals ?? [],
            'entity_count' => $report->entity_count,
            'denominator' => $report->denominator,
            'coverage_rate' => $report->coverageRate(),
            'compiled_at' => $report->compiled_at?->toIso8601String(),
            'compiled_by_id' => $report->compiled_by_id,
            'entities' => $report->entries
                ->map(fn (ConsolidatedReportEntry $entry): array => [
                    'subject_tenant_id' => $entry->subject_tenant_id,
                    // The NAME is copied, not just the id: an MDA that is
                    // later renamed or merged must not silently retitle a
                    // report that was signed about the entity as it was.
                    'entity' => $entry->subject?->name,
                    'entity_slug' => $entry->subject?->slug,
                    'projects_total' => $entry->projects_total,
                    'projects_by_status' => $entry->projects_by_status ?? [],
                    'contract_value_total' => $entry->contract_value_total->toDecimalString(),
                    'expenditure_total' => $entry->expenditure_total->toDecimalString(),
                    'physical_progress_avg' => $entry->physical_progress_avg,
                    'obligations_expected' => $entry->obligations_expected,
                    'obligations_submitted' => $entry->obligations_submitted,
                    'obligations_on_time' => $entry->obligations_on_time,
                    'obligations_missed' => $entry->obligations_missed,
                    'obligations_waived' => $entry->obligations_waived,
                    'on_time_rate' => $entry->onTimeRate(),
                    'reports_filed' => $entry->reports_filed,
                    'reports_approved' => $entry->reports_approved,
                    'period_expenditure_total' => $entry->period_expenditure_total->toDecimalString(),
                    'indicators_reported' => $entry->indicators_reported,
                    'indicators_on_track' => $entry->indicators_on_track,
                    'indicators_at_risk' => $entry->indicators_at_risk,
                    'indicators_off_track' => $entry->indicators_off_track,
                    'readings_validated' => $entry->readings_validated,
                    'figures' => $entry->figures,
                ])
                ->values()
                ->all(),
            'sections' => $report->sections
                ->map(fn ($section): array => [
                    'key' => $section->key,
                    'heading' => $section->heading,
                    'body' => $section->body,
                ])
                ->values()
                ->all(),
        ];
    }
}
