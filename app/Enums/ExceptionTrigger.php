<?php

namespace App\Enums;

/**
 * Why an exception report exists (manual Table 5.2 — the Exception Report is
 * filed "on critical incidence / high deviation"; plan §8 feature cue 4 —
 * threshold-based deviation alerts).
 *
 * Three of the five are raised by App\Actions\Issues\EvaluateProjectThresholds
 * with no human in the loop, which is the whole point: a deviation that only
 * gets reported when somebody notices it is a deviation that gets reported
 * late, or never. The other two are the human paths — a critical incident on
 * site, and a considered manual deviation report.
 */
enum ExceptionTrigger: string
{
    case ScheduleSlippage = 'schedule_slippage';
    case ExpenditureVariance = 'expenditure_variance';
    case ReportingOverdue = 'reporting_overdue';
    case CriticalIncident = 'critical_incident';
    case Manual = 'manual';

    /**
     * Whether the threshold engine owns this trigger. Automatic triggers are
     * the ones the duplicate gate applies to: the engine may not raise a
     * second `schedule_slippage` for a project that already has one open,
     * while a human may always file another critical-incident report because
     * a second incident is a second fact.
     */
    public function isAutomatic(): bool
    {
        return in_array($this, [
            self::ScheduleSlippage,
            self::ExpenditureVariance,
            self::ReportingOverdue,
        ], true);
    }

    /**
     * The unit the measured figure is expressed in — percentage points for the
     * two deviation triggers, days for the compliance one. Stored with the
     * record so the row still explains itself when the policy number behind it
     * has since been retuned.
     */
    public function unit(): string
    {
        return match ($this) {
            self::ScheduleSlippage, self::ExpenditureVariance => __('percentage points'),
            self::ReportingOverdue => __('days'),
            self::CriticalIncident, self::Manual => __(''),
        };
    }

    /**
     * Where the engine starts a severity. A human raising one chooses their
     * own; a critical incident is critical by definition — that is what the
     * word means in the manual's report table.
     */
    public function defaultSeverity(): IssueSeverity
    {
        return match ($this) {
            self::CriticalIncident => IssueSeverity::Critical,
            self::ScheduleSlippage, self::ExpenditureVariance, self::ReportingOverdue => IssueSeverity::High,
            self::Manual => IssueSeverity::Medium,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ScheduleSlippage => 'clock',
            self::ExpenditureVariance => 'banknotes',
            self::ReportingOverdue => 'document-text',
            self::CriticalIncident => 'exclamation-triangle',
            self::Manual => 'pencil-square',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ScheduleSlippage => __('Schedule slippage'),
            self::ExpenditureVariance => __('Expenditure variance'),
            self::ReportingOverdue => __('Reporting overdue'),
            self::CriticalIncident => __('Critical incident'),
            self::Manual => __('Manual deviation report'),
        };
    }

    /**
     * What the engine writes as the narrative's opening — the sentence that
     * has to still make sense to somebody reading the record in two years.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::ScheduleSlippage => __('Physical progress has fallen behind the elapsed contract schedule by more than the configured tolerance.'),
            self::ExpenditureVariance => __('Expenditure has run ahead of physical progress by more than the configured tolerance.'),
            self::ReportingOverdue => __('A statutory progress return for this project has been outstanding past the configured tolerance.'),
            self::CriticalIncident => __('A critical incident was reported on this project.'),
            self::Manual => __('A deviation was reported manually.'),
        };
    }

    /** Triggers a person may choose on the manual form — never an automatic one. */
    /** @return array<string, string> */
    public static function manualOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if (! $case->isAutomatic()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
