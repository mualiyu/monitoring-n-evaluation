<?php

namespace App\Actions\Consolidation;

use App\Actions\Oversight\AggregateForConsolidation;
use App\Enums\ConsolidationStatus;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidatedReportEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * THE cross-MDA roll-up, written into consolidated_report_entries.
 *
 * IDEMPOTENT BY CONSTRUCTION, not by defence. Every entry is an
 * updateOrCreate on the unique (consolidated_report_id, subject_tenant_id)
 * key, and the totals are recomputed wholesale from the same source — so a
 * replayed job, two overlapping workers and an officer hitting Recompile
 * twice all converge on identical numbers. There is no "have I already
 * compiled this?" flag to get out of step with reality.
 *
 * CHUNKED, because a state deployment is 40 MDAs today and the same code runs
 * a federal instance with 400. The aggregate itself is a handful of grouped
 * queries (see AggregateForConsolidation — the database does the counting);
 * what is chunked is the WRITE, so the transaction log never holds hundreds of
 * rows at once and a failure part-way leaves a partially refreshed roll-up
 * that the next run completes rather than a locked table.
 *
 * ⚠ The cross-tenant read is NOT here. It lives in
 * app/Actions/Oversight/AggregateForConsolidation, with the authority check
 * that justifies it — this Action never touches a tenant-owned model.
 */
class CompileConsolidatedFigures
{
    /** Entries written per batch. */
    public const CHUNK_SIZE = 25;

    public function __invoke(ConsolidatedReport $report, User $actor): ConsolidatedReport
    {
        Gate::forUser($actor)->authorize('compile', $report);

        // Opening the compile IS the draft → compiling move. Running it again
        // on an already-compiling report is a recompile, not a transition —
        // which is what makes "press it twice" harmless.
        if ($report->status === ConsolidationStatus::Draft) {
            app(TransitionConsolidationStatus::class)($report, ConsolidationStatus::Compiling, $actor);
        }

        $period = $report->loadMissing('reportingPeriod')->reportingPeriod;
        $rollUp = app(AggregateForConsolidation::class)($actor, $period);

        $compiledAt = now();
        $subjects = [];

        foreach (array_chunk($rollUp['entities'], self::CHUNK_SIZE, true) as $batch) {
            foreach ($batch as $tenantId => $figures) {
                $subjects[] = (int) $tenantId;

                ConsolidatedReportEntry::query()->updateOrCreate(
                    [
                        'consolidated_report_id' => $report->id,
                        'subject_tenant_id' => (int) $tenantId,
                    ],
                    [
                        'projects_total' => $figures['projects_total'],
                        'projects_by_status' => $figures['projects_by_status'],
                        'contract_value_total' => $figures['contract_value_total'],
                        'expenditure_total' => $figures['expenditure_total'],
                        'physical_progress_avg' => $figures['physical_progress_avg'],
                        'obligations_expected' => $figures['obligations_expected'],
                        'obligations_submitted' => $figures['obligations_submitted'],
                        'obligations_on_time' => $figures['obligations_on_time'],
                        'obligations_missed' => $figures['obligations_missed'],
                        'obligations_waived' => $figures['obligations_waived'],
                        'reports_filed' => $figures['reports_filed'],
                        'reports_approved' => $figures['reports_approved'],
                        'period_expenditure_total' => $figures['period_expenditure_total'],
                        'indicators_reported' => $figures['indicators_reported'],
                        'indicators_on_track' => $figures['indicators_on_track'],
                        'indicators_at_risk' => $figures['indicators_at_risk'],
                        'indicators_off_track' => $figures['indicators_off_track'],
                        'readings_validated' => $figures['readings_validated'],
                        // The extension point: modules that land after this
                        // one (inspections, issues, evaluations, workplans)
                        // add their per-MDA counts here without a migration
                        // and without touching the consolidation screens.
                        'figures' => null,
                        'compiled_at' => $compiledAt,
                    ],
                );
            }
        }

        // An entity that has left the instance leaves the roll-up with it.
        // Without this, a recompile after a workspace is removed would carry
        // its stale figures into the signed snapshot for ever.
        ConsolidatedReportEntry::query()
            ->where('consolidated_report_id', $report->id)
            ->whereNotIn('subject_tenant_id', $subjects === [] ? [0] : $subjects)
            ->delete();

        // forceFill: the roll-up columns are deliberately not fillable, so
        // only this Action and the chokepoint can move them.
        $report->forceFill([
            'totals' => $this->jsonSafe($rollUp['totals']),
            'entity_count' => $rollUp['totals']['entities_reporting'],
            'denominator' => $rollUp['denominator'],
            'compiled_by_id' => $actor->id,
            'compiled_at' => $compiledAt,
        ])->save();

        return $report;
    }

    /**
     * Money is a value object that JSON-encodes to `{}` — the decimal string
     * is what belongs in a stored figure, exactly as the activity log's
     * useAttributeRawValues() does it for a logged money column. The
     * conversion happens once, here, at the boundary.
     *
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function jsonSafe(array $totals): array
    {
        foreach ($totals as $key => $value) {
            if ($value instanceof Money) {
                $totals[$key] = $value->toDecimalString();
            }
        }

        return $totals;
    }
}
