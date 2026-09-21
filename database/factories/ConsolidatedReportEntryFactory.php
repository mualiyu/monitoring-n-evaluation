<?php

namespace Database\Factories;

use App\Models\ConsolidatedReport;
use App\Models\ConsolidatedReportEntry;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One MDA's figures inside a state roll-up. GLOBAL, like its parent.
 *
 * ⚠ `subject_tenant_id` is PROVENANCE, not a scope key, so this factory sets
 * it EXPLICITLY and needs no bound tenant — unlike every tenant-owned factory
 * in the platform, which must never set a tenant_id.
 *
 * The row is DERIVED: CompileConsolidatedFigures rewrites it wholesale on
 * every run. A fixture built here states what a compile WOULD have produced,
 * which is exactly what a snapshot test needs — and what a test must then
 * change underneath an approved report to prove the snapshot holds.
 *
 * Money attributes are decimal strings ("8500000.00"), never floats.
 *
 * @extends Factory<ConsolidatedReportEntry>
 */
class ConsolidatedReportEntryFactory extends Factory
{
    protected $model = ConsolidatedReportEntry::class;

    public function definition(): array
    {
        return [
            'consolidated_report_id' => ConsolidatedReport::factory(),
            'subject_tenant_id' => Tenant::factory(),
            'projects_total' => 4,
            'projects_by_status' => ['in_progress' => 3, 'completed' => 1],
            'contract_value_total' => '480000000.00',
            'expenditure_total' => '210000000.00',
            'physical_progress_avg' => '54.50',
            'obligations_expected' => 4,
            'obligations_submitted' => 3,
            'obligations_on_time' => 2,
            'obligations_missed' => 1,
            'obligations_waived' => 0,
            'reports_filed' => 3,
            'reports_approved' => 2,
            'period_expenditure_total' => '38000000.00',
            'indicators_reported' => 6,
            'indicators_on_track' => 4,
            'indicators_at_risk' => 1,
            'indicators_off_track' => 1,
            'readings_validated' => 5,
            'figures' => null,
            'compiled_at' => CarbonImmutable::now(),
        ];
    }

    public function forReport(ConsolidatedReport $report): static
    {
        return $this->state(['consolidated_report_id' => $report->id]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['subject_tenant_id' => $tenant->id]);
    }

    /** An entity that owed returns and filed none — the finding, not a gap. */
    public function silent(): static
    {
        return $this->state([
            'projects_total' => 2,
            'obligations_submitted' => 0,
            'obligations_on_time' => 0,
            'obligations_missed' => 4,
            'reports_filed' => 0,
            'reports_approved' => 0,
            'period_expenditure_total' => '0.00',
            'indicators_reported' => 0,
            'indicators_on_track' => 0,
            'indicators_at_risk' => 0,
            'indicators_off_track' => 0,
            'readings_validated' => 0,
        ]);
    }

    /** Everything owed, filed on time. */
    public function compliant(): static
    {
        return $this->state([
            'obligations_submitted' => 4,
            'obligations_on_time' => 4,
            'obligations_missed' => 0,
            'reports_filed' => 4,
            'reports_approved' => 4,
        ]);
    }

    /** Excused from reporting — out of the base, never scored as a failure. */
    public function waived(): static
    {
        return $this->state([
            'obligations_expected' => 2,
            'obligations_waived' => 2,
            'obligations_submitted' => 0,
            'obligations_on_time' => 0,
            'obligations_missed' => 0,
        ]);
    }
}
