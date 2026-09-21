<?php

namespace App\Actions\Consolidation;

use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use App\Exceptions\Consolidation\ConsolidationRuleViolation;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidatedReportSection;
use App\Models\ConsolidationEvent;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Open a roll-up for a window (manual digest §4 — the Secretariat consolidates
 * MDA returns for the Commissioner, and the APR is consolidated against the
 * predetermined indicator list).
 *
 * Two guards, both structural:
 *
 *  1. THE WINDOW MUST MATCH THE TYPE. An Annual Performance Report compiled
 *     against a monthly window would take its denominator — who owed a return
 *     — from one month and print it as a year. Every rate in the document
 *     would then be wrong in the same direction, which is the kind of error
 *     nobody catches because everything is internally consistent.
 *
 *  2. ONE OF EACH PER WINDOW. A state issues one APR for a year; two would be
 *     two answers to one question. Enforced inside a locked transaction rather
 *     than by a unique index, because soft deletes make such an index either
 *     block reopening after a discard or enforce nothing at all — the same
 *     reasoning StartProgressReport applies to the one-live-report rule.
 *     Thematic reports are exempt: a state may study three questions at once.
 */
class OpenConsolidation
{
    public function __invoke(
        User $actor,
        ReportingPeriod $period,
        ConsolidatedReportType $type,
        ?string $title = null,
    ): ConsolidatedReport {
        Gate::forUser($actor)->authorize('create', ConsolidatedReport::class);

        $expected = $type->expectedCadence();

        if ($expected !== null && $period->cadence !== $expected) {
            throw ConsolidationRuleViolation::cadenceMismatch(
                $type->label(),
                $expected->label(),
                $period->cadence->label(),
            );
        }

        return DB::transaction(function () use ($actor, $period, $type, $title): ConsolidatedReport {
            if ($type->isUniquePerPeriod()) {
                $existing = ConsolidatedReport::query()
                    ->where('reporting_period_id', $period->id)
                    ->where('type', $type)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ConsolidationRuleViolation::windowAlreadyConsolidated($type->label(), $period->label);
                }
            }

            $report = ConsolidatedReport::query()->create([
                'reference' => $this->reference($type, $period),
                // White-label: the title names the TYPE and the WINDOW, never
                // a state, a ministry or a product. Branding arrives from
                // instance config at render time (rules/architecture.md).
                'title' => $title !== null && trim($title) !== ''
                    ? trim($title)
                    : $type->label().' — '.$period->label,
                'type' => $type,
                'reporting_period_id' => $period->id,
                'created_by_id' => $actor->id,
            ]);

            // Not fillable, so assigned explicitly here rather than left to
            // the column default: `status` is the chokepoint's column, and
            // leaning on the database for the opening value would hand every
            // caller a model whose status is NULL until something re-reads the
            // row — which the policy then asks isEditable() of. Same reasoning
            // as StartProgressReport and CommissionEvaluation.
            $report->forceFill(['status' => ConsolidationStatus::Draft])->save();

            $this->seedSections($report, $type);

            // The opening row of the ledger. from_status is null because
            // nothing preceded it — every later row is written by the
            // chokepoint, and between them they are the complete history.
            ConsolidationEvent::query()->create([
                'consolidated_report_id' => $report->id,
                'from_status' => null,
                'to_status' => ConsolidationStatus::Draft,
                'actor_id' => $actor->id,
                'reason' => null,
                'occurred_at' => now(),
            ]);

            return $report;
        });
    }

    /**
     * The narrative skeleton for the type, in order. Seeded empty and never
     * regenerated: a chapter may hold no text, but the reader is entitled to
     * see that it is empty rather than to not know it was expected.
     */
    private function seedSections(ConsolidatedReport $report, ConsolidatedReportType $type): void
    {
        $position = 0;

        foreach ($type->sectionSkeleton() as $key => $heading) {
            ConsolidatedReportSection::query()->create([
                'consolidated_report_id' => $report->id,
                'key' => $key,
                'heading' => $heading,
                'body' => null,
                'position' => $position++,
            ]);
        }
    }

    /**
     * A human handle for the artifact: type prefix + window code, e.g.
     * APR-2026A1. Unique by column, so a window reopened after a discard takes
     * the next free suffix rather than colliding with the soft-deleted row
     * that still holds the name.
     */
    private function reference(ConsolidatedReportType $type, ReportingPeriod $period): string
    {
        $prefix = match ($type) {
            ConsolidatedReportType::AnnualApr => 'APR',
            ConsolidatedReportType::Biannual => 'BIA',
            ConsolidatedReportType::Quarterly => 'QTR',
            ConsolidatedReportType::Thematic => 'THM',
        };

        $base = $prefix.'-'.Str::upper(Str::slug($period->code, ''));
        $candidate = $base;
        $suffix = 1;

        while (ConsolidatedReport::withTrashed()->where('reference', $candidate)->exists()) {
            $candidate = $base.'-'.(++$suffix);
        }

        return Str::limit($candidate, 40, '');
    }
}
