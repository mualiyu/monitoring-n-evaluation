<?php

namespace App\Actions\Reporting;

use App\Enums\ReportingCadence;
use App\Models\ReportingPeriod;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * Builds a year of the statutory calendar (progress-reporting.md §0.1, §3).
 *
 * IDEMPOTENT BY CONSTRUCTION: every window is upserted on its `code`
 * (`2026-M03`), so the yearly cron, an installer and an operator running it
 * twice all converge on the same 19 rows rather than accumulating duplicates.
 *
 * The due-date rules come from the manual digest §4 and are read through
 * SettingsRepository, never as literals:
 *   monthly    period_end + `monthly_due_days`
 *   quarterly  period_end + `quarterly_due_days`
 *   biannual   END OF THE MONTH FOLLOWING the half — H1 → 31 Jul, H2 → 31 Jan
 *              of the next year (`biannual_due_rule = end_of_following_month`)
 *   annual     within Q1 of the FOLLOWING year → 31 Mar (`annual_due_rule =
 *              end_of_q1`); a due date inside the period it reports on would
 *              be nonsense.
 *
 * `opens_at` is the START of the window, not its end: an MDA that finishes its
 * return early should be able to file it. `closes_at` is null while the
 * instance accepts late returns (the default) — a null close is what makes
 * "late but filed" possible; when late submission is off, the window closes at
 * its deadline and reporting:close-periods can mark the rest missed.
 */
class GenerateReportingPeriods
{
    /**
     * @return int the number of windows created or refreshed
     */
    public function __invoke(int $year, string $generatedBy = 'system'): int
    {
        $settings = app(SettingsRepository::class);
        $allowLate = $settings->bool('reporting', 'allow_late_submission', true);
        $count = 0;

        foreach (ReportingCadence::cases() as $cadence) {
            foreach (range(1, $cadence->periodsPerYear()) as $ordinal) {
                $start = $cadence->startOfPeriod($year, $ordinal);
                $end = $cadence->endOfPeriod($year, $ordinal);
                $dueAt = $this->dueAt($cadence, $end, $settings);

                ReportingPeriod::query()->updateOrCreate(
                    ['code' => $cadence->codeFor($year, $ordinal)],
                    [
                        'cadence' => $cadence,
                        'label' => $cadence->labelFor($year, $ordinal),
                        'period_start' => $start,
                        'period_end' => $end,
                        'opens_at' => $start,
                        'due_at' => $dueAt,
                        'closes_at' => $allowLate ? null : $dueAt,
                        'generated_by' => $generatedBy,
                    ],
                );

                $count++;
            }
        }

        return $count;
    }

    private function dueAt(ReportingCadence $cadence, CarbonImmutable $periodEnd, SettingsRepository $settings): CarbonImmutable
    {
        return match ($cadence) {
            ReportingCadence::Monthly => $periodEnd
                ->addDays($settings->int('reporting', 'monthly_due_days', 7))
                ->endOfDay(),
            ReportingCadence::Quarterly => $periodEnd
                ->addDays($settings->int('reporting', 'quarterly_due_days', 14))
                ->endOfDay(),
            ReportingCadence::Biannual => $this->biannualDueAt($periodEnd, $settings),
            ReportingCadence::Annual => $this->annualDueAt($periodEnd, $settings),
        };
    }

    /**
     * The statutory six-monthly rule (digest §4): the return is due at the end
     * of the month FOLLOWING the half — H1 (ends 30 Jun) → 31 Jul, H2 (ends
     * 31 Dec) → 31 Jan of the next year.
     *
     * An unrecognised override falls back to the statutory rule rather than to
     * an offset nobody legislated: a wrong setting must not silently invent a
     * deadline MDAs will be sanctioned against.
     */
    private function biannualDueAt(CarbonImmutable $periodEnd, SettingsRepository $settings): CarbonImmutable
    {
        return match ($settings->string('reporting', 'biannual_due_rule', 'end_of_following_month')) {
            'end_of_period' => $periodEnd->endOfDay(),
            default => $periodEnd->startOfMonth()->addMonth()->endOfMonth()->endOfDay(),
        };
    }

    /**
     * The annual return is due within the first quarter — of the FOLLOWING
     * year, necessarily: the period it reports on only ends on 31 December.
     */
    private function annualDueAt(CarbonImmutable $periodEnd, SettingsRepository $settings): CarbonImmutable
    {
        return match ($settings->string('reporting', 'annual_due_rule', 'end_of_q1')) {
            'end_of_period' => $periodEnd->endOfDay(),
            default => CarbonImmutable::createStrict($periodEnd->year + 1, 3, 1)->endOfMonth()->endOfDay(),
        };
    }
}
