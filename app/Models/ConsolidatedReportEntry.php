<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ConsolidatedReportEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One MDA's figures inside a state consolidation — GLOBAL, like its parent.
 *
 * ⚠ `subject_tenant_id` is PROVENANCE, not a scope key, and this model
 * therefore does NOT use BelongsToTenant. See the migration for the full
 * argument; in one line: a tenancy key here would hide 39 of 40 rows of the
 * state's own report from the state.
 *
 * The row is DERIVED. CompileConsolidatedFigures rewrites it in place on every
 * run (updateOrCreate on the unique key), which is what makes the compiler
 * idempotent — a replayed job, an overlapping worker and a manual recompile
 * all converge on the same numbers. The permanent record of what was SIGNED is
 * the frozen snapshot on the parent, never these working rows.
 *
 * @property int $id
 * @property int $consolidated_report_id
 * @property int $subject_tenant_id
 * @property int $projects_total
 * @property array<string, int>|null $projects_by_status
 * @property Money $contract_value_total
 * @property Money $expenditure_total
 * @property string|null $physical_progress_avg
 * @property int $obligations_expected
 * @property int $obligations_submitted
 * @property int $obligations_on_time
 * @property int $obligations_missed
 * @property int $obligations_waived
 * @property int $reports_filed
 * @property int $reports_approved
 * @property Money $period_expenditure_total
 * @property int $indicators_reported
 * @property int $indicators_on_track
 * @property int $indicators_at_risk
 * @property int $indicators_off_track
 * @property int $readings_validated
 * @property array<string, mixed>|null $figures
 * @property CarbonImmutable|null $compiled_at
 */
#[Fillable([
    'consolidated_report_id', 'subject_tenant_id', 'projects_total', 'projects_by_status',
    'contract_value_total', 'expenditure_total', 'physical_progress_avg',
    'obligations_expected', 'obligations_submitted', 'obligations_on_time',
    'obligations_missed', 'obligations_waived', 'reports_filed', 'reports_approved',
    'period_expenditure_total', 'indicators_reported', 'indicators_on_track',
    'indicators_at_risk', 'indicators_off_track', 'readings_validated', 'figures',
    'compiled_at',
])]
class ConsolidatedReportEntry extends Model
{
    /** @use HasFactory<ConsolidatedReportEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'projects_total' => 'integer',
            'projects_by_status' => 'array',
            'contract_value_total' => MoneyCast::class,
            'expenditure_total' => MoneyCast::class,
            'physical_progress_avg' => 'decimal:2',
            'obligations_expected' => 'integer',
            'obligations_submitted' => 'integer',
            'obligations_on_time' => 'integer',
            'obligations_missed' => 'integer',
            'obligations_waived' => 'integer',
            'reports_filed' => 'integer',
            'reports_approved' => 'integer',
            'period_expenditure_total' => MoneyCast::class,
            'indicators_reported' => 'integer',
            'indicators_on_track' => 'integer',
            'indicators_at_risk' => 'integer',
            'indicators_off_track' => 'integer',
            'readings_validated' => 'integer',
            'figures' => 'array',
            'compiled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ConsolidatedReport, $this> */
    public function consolidatedReport(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedReport::class);
    }

    /**
     * The MDA these figures describe. Named `subject`, not `tenant`, so that
     * nothing reads as though this row were owned by that workspace.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'subject_tenant_id');
    }

    /**
     * On-time filing as a percentage, with waived obligations out of the base
     * — the same rule BuildComplianceLeagueTable applies, because an entity
     * excused from reporting must not be scored as though it failed to report.
     */
    public function onTimeRate(): ?float
    {
        $scored = $this->obligations_expected - $this->obligations_waived;

        return $scored > 0 ? round($this->obligations_on_time * 100 / $scored, 1) : null;
    }

    /** Indicators with a reading in the window that met their target band. */
    public function indicatorAchievementRate(): ?float
    {
        return $this->indicators_reported > 0
            ? round($this->indicators_on_track * 100 / $this->indicators_reported, 1)
            : null;
    }
}
