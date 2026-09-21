<?php

namespace App\Actions\Issues;

use App\Enums\ExceptionTrigger;
use App\Models\Project;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The threshold engine (plan §8 feature cue 4; manual Table 5.2 — the
 * Exception Report is filed "on high deviation").
 *
 * Three tolerances, all policy numbers read through SettingsRepository so a
 * state can retune them from a settings screen without a release:
 *
 *   schedule_slippage_points     work behind the clock, in percentage points
 *   expenditure_variance_points  money ahead of the work, in percentage points
 *   reporting_overdue_days       a statutory return outstanding this long
 *
 * IDEMPOTENCE IS NOT THIS CLASS'S JOB — it is RaiseExceptionReport's duplicate
 * gate, checked and written under a row lock. That split is deliberate: the
 * sweep is the thing most likely to be re-run by hand, by a retry, or by two
 * overlapping schedules, and a guard that lives in the sweep protects only the
 * sweep. Put it at the write and every door is covered.
 *
 * Iterates tenants through CurrentTenant::runAs() so tenancy travels into
 * every queued notification — the sweep itself binds no tenant, and the
 * fail-closed TenantScope would refuse the first query if it tried to read
 * without one.
 */
class EvaluateProjectThresholds
{
    /**
     * @return array{evaluated: int, raised: int}
     */
    public function __invoke(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $totals = ['evaluated' => 0, 'raised' => 0];

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $tenantTotals = $current->runAs($tenant, fn (): array => $this->forTenant($asOf));

            $totals['evaluated'] += $tenantTotals['evaluated'];
            $totals['raised'] += $tenantTotals['raised'];
        }

        return $totals;
    }

    /**
     * @return array{evaluated: int, raised: int}
     */
    private function forTenant(CarbonImmutable $asOf): array
    {
        $settings = app(SettingsRepository::class);

        $slippageTolerance = (float) $settings->int('exceptions', 'schedule_slippage_points', 15);
        $varianceTolerance = (float) $settings->int('exceptions', 'expenditure_variance_points', 20);
        $overdueTolerance = $settings->int('exceptions', 'reporting_overdue_days', 14);

        $measure = app(MeasureProjectDeviation::class);
        $raise = app(RaiseExceptionReport::class);

        $totals = ['evaluated' => 0, 'raised' => 0];

        foreach ($this->projectsUnderDelivery()->cursor() as $project) {
            $totals['evaluated']++;

            $deviation = $measure($project, $asOf);

            $totals['raised'] += $this->raiseIfTripped(
                $raise,
                $project,
                ExceptionTrigger::ScheduleSlippage,
                $deviation['schedule_slippage'],
                $slippageTolerance,
                $deviation,
            );

            $totals['raised'] += $this->raiseIfTripped(
                $raise,
                $project,
                ExceptionTrigger::ExpenditureVariance,
                $deviation['expenditure_variance'],
                $varianceTolerance,
                $deviation,
            );

            $totals['raised'] += $this->raiseIfOverdue($raise, $project, $overdueTolerance, $asOf, $deviation);
        }

        return $totals;
    }

    /**
     * Projects a deviation can meaningfully be measured on.
     *
     * The status list is `reporting.obligation_statuses` — the platform's
     * existing definition of "a project under active delivery", reused rather
     * than restated so the register and the deadline engine can never disagree
     * about which projects are live.
     *
     * Projects with an actual end date are excluded on top of that: finished
     * work cannot slip, and a project sitting in `completed` through its
     * retention period would otherwise raise a fresh slippage exception the
     * moment its (unmoved) contract end date passed.
     *
     * @return Builder<Project>
     */
    private function projectsUnderDelivery(): Builder
    {
        $statuses = app(SettingsRepository::class)->strings(
            'reporting',
            'obligation_statuses',
            ['mobilized', 'in_progress', 'completed'],
        );

        return Project::query()
            ->whereIn('status', $statuses)
            ->whereNull('actual_end_date')
            ->orderBy('id');
    }

    /**
     * @param  array{physical_progress: float, schedule_elapsed: float|null, financial_progress: float|null, schedule_slippage: float|null, expenditure_variance: float|null, measured_at: CarbonImmutable}  $deviation
     */
    private function raiseIfTripped(
        RaiseExceptionReport $raise,
        Project $project,
        ExceptionTrigger $trigger,
        ?float $measured,
        float $tolerance,
        array $deviation,
    ): int {
        // Null is "not measurable", never "zero": a project with no schedule
        // is not a project perfectly on schedule, and treating it as one would
        // paper over exactly the records that need attention most.
        if ($measured === null || $measured < $tolerance) {
            return 0;
        }

        $report = $raise(
            $project,
            $trigger,
            [
                'narrative' => $this->narrative($trigger, $project, $measured, $tolerance),
                'measured_value' => $measured,
                'threshold_value' => $tolerance,
                'physical_progress' => $deviation['physical_progress'],
                'schedule_elapsed' => $deviation['schedule_elapsed'],
                'financial_progress' => $deviation['financial_progress'],
                'measured_at' => $deviation['measured_at'],
            ],
        );

        return $report === null ? 0 : 1;
    }

    /**
     * The compliance tolerance: a statutory return that has been outstanding
     * past `reporting_overdue_days` stops being a reminder problem and becomes
     * a deviation on the project's record.
     *
     * The deadline engine already nags and escalates the obligation itself
     * (FlagOverdueObligations); this is the different statement that the
     * PROJECT is now off-track for want of reporting, which is what an
     * oversight board reads.
     *
     * @param  array{physical_progress: float, schedule_elapsed: float|null, financial_progress: float|null, schedule_slippage: float|null, expenditure_variance: float|null, measured_at: CarbonImmutable}  $deviation
     */
    private function raiseIfOverdue(
        RaiseExceptionReport $raise,
        Project $project,
        int $toleranceDays,
        CarbonImmutable $asOf,
        array $deviation,
    ): int {
        $cutoff = $asOf->subDays($toleranceDays);

        $worst = ReportObligation::query()
            ->outstanding()
            ->where('project_id', $project->id)
            ->where('due_at', '<', $cutoff)
            ->orderBy('due_at')
            ->first();

        if ($worst === null) {
            return 0;
        }

        $daysLate = abs($worst->daysToDue($asOf));

        $report = $raise(
            $project,
            ExceptionTrigger::ReportingOverdue,
            [
                'narrative' => __(
                    ':explanation The :window return was due on :due and is :days day(s) outstanding, against a tolerance of :tolerance day(s).',
                    [
                        'explanation' => ExceptionTrigger::ReportingOverdue->explanation(),
                        'window' => $worst->loadMissing('reportingPeriod')->reportingPeriod->label,
                        'due' => $worst->due_at->toDateString(),
                        'days' => $daysLate,
                        'tolerance' => $toleranceDays,
                    ],
                ),
                'measured_value' => $daysLate,
                'threshold_value' => $toleranceDays,
                'physical_progress' => $deviation['physical_progress'],
                'schedule_elapsed' => $deviation['schedule_elapsed'],
                'financial_progress' => $deviation['financial_progress'],
                'measured_at' => $deviation['measured_at'],
            ],
        );

        return $report === null ? 0 : 1;
    }

    /**
     * The sentence that has to still make sense to somebody reading this
     * record in two years, when the tolerance has been retuned twice and the
     * project's figures have moved on. Every number it quotes is also stored
     * in its own column — the prose is for the reader, the columns are for the
     * query.
     */
    private function narrative(
        ExceptionTrigger $trigger,
        Project $project,
        float $measured,
        float $tolerance,
    ): string {
        return __(
            ':explanation Measured at :measured percentage points against a tolerance of :tolerance, '
            .'with physical progress at :physical%.',
            [
                'explanation' => $trigger->explanation(),
                'measured' => number_format($measured, 2),
                'tolerance' => number_format($tolerance, 2),
                'physical' => $project->physical_progress,
            ],
        );
    }
}
