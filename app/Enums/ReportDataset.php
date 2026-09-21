<?php

namespace App\Enums;

/**
 * The four things the ad-hoc report builder can be pointed at, and the
 * contract each one carries: which oversight permission it costs, which
 * columns exist, and which groupings make sense.
 *
 * THE HONESTY RULE. Every dataset here resolves through an existing
 * app/Actions/Oversight/ Action — the same one the matching dashboard calls
 * (App\Support\Exporting\DatasetRows makes the mapping). A builder with its own
 * SQL would eventually print a number the dashboard disagrees with, and a
 * government report that contradicts the screen it was exported from is worse
 * than no report at all.
 *
 * Column KEYS are part of that contract: they are what a saved export's
 * `columns` array holds, so renaming one is a data migration, not a rename.
 */
enum ReportDataset: string
{
    case Projects = 'projects';
    case Reports = 'reports';
    case Compliance = 'compliance';
    case Indicators = 'indicators';
    /**
     * The per-entity figures of ONE state consolidation. Not offered in the
     * builder (see builderOptions) because it answers a different question:
     * "what did this signed report say", not "what does the register hold".
     * It is here so that a consolidation's spreadsheet annex and its PDF land
     * in the same register, under the same audit columns, as every other
     * artifact this platform hands out.
     */
    case Consolidation = 'consolidation';

    public function label(): string
    {
        return match ($this) {
            self::Projects => __('Projects'),
            self::Reports => __('Progress returns'),
            self::Compliance => __('Reporting compliance'),
            self::Indicators => __('Indicator performance'),
            self::Consolidation => __('Consolidated report figures'),
        };
    }

    /**
     * The datasets the ad-hoc builder offers. Consolidation is excluded: it is
     * reached from a consolidation, against that consolidation's frozen
     * figures, and offering it as a free-standing choice would invite an
     * export of "a consolidation" with no consolidation named.
     *
     * @return list<self>
     */
    public static function builderOptions(): array
    {
        return [self::Projects, self::Reports, self::Compliance, self::Indicators];
    }

    public function isBuilderSelectable(): bool
    {
        return in_array($this, self::builderOptions(), true);
    }

    public function description(): string
    {
        return match ($this) {
            self::Projects => __('Every project in the state register, across all entities.'),
            self::Reports => __('Returns the state has been sent — filed, reviewed and approved.'),
            self::Compliance => __('Who owed a return for a window, who filed, and who filed on time.'),
            self::Indicators => __('Readings against targets for a window, with achievement bands.'),
            self::Consolidation => __('Every entity’s figures inside one state roll-up, as they were signed.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Projects => 'folder',
            self::Reports => 'document-text',
            self::Compliance => 'clipboard-check',
            self::Indicators => 'chart-bar',
            self::Consolidation => 'flag',
        };
    }

    /**
     * The GLOBAL-team permission this dataset costs. Deliberately the same
     * permission the corresponding dashboard costs — the builder is a
     * different presentation of data a user can already see, never a side door
     * to data they cannot.
     */
    public function permission(): string
    {
        return match ($this) {
            self::Projects => 'oversight.portfolio.view',
            self::Reports => 'oversight.reports.view',
            self::Compliance => 'oversight.compliance.view',
            self::Indicators => 'oversight.reports.view',
            self::Consolidation => 'oversight.reports.view',
        };
    }

    /** Whether this dataset is meaningless without a reporting window. */
    public function requiresPeriod(): bool
    {
        return $this === self::Compliance || $this === self::Indicators;
    }

    /**
     * Every column the dataset can print: key => heading.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Projects => [
                'entity' => __('Entity'),
                'reference' => __('Reference'),
                'title' => __('Project'),
                'sector' => __('Sector'),
                'status' => __('Status'),
                'contract_value' => __('Contract sum'),
                'expenditure' => __('Expenditure to date'),
                'physical_progress' => __('Physical progress %'),
                'start_date' => __('Start date'),
                'expected_end_date' => __('Expected completion'),
                'lga' => __('Local government area'),
            ],
            self::Reports => [
                'entity' => __('Entity'),
                'window' => __('Window'),
                'reference' => __('Reference'),
                'project' => __('Project'),
                'status' => __('Status'),
                'physical_progress' => __('Progress claimed %'),
                'period_expenditure' => __('Period spend'),
                'submitted_at' => __('Filed on'),
                'submitted_by' => __('Filed by'),
                'submitted_late' => __('Filed late'),
            ],
            self::Compliance => [
                'entity' => __('Entity'),
                'expected' => __('Returns expected'),
                'submitted' => __('Filed'),
                'on_time' => __('Filed on time'),
                'missed' => __('Missed'),
                'waived' => __('Waived'),
                'compliance_rate' => __('Compliance rate %'),
                'on_time_rate' => __('On-time rate %'),
            ],
            self::Indicators => [
                'entity' => __('Entity'),
                'indicator' => __('Indicator'),
                'tier' => __('Tier'),
                'unit' => __('Unit'),
                'project' => __('Project'),
                'window' => __('Window'),
                'baseline' => __('Baseline'),
                'target' => __('Target'),
                'actual' => __('Actual'),
                'achievement' => __('Achievement %'),
                'band' => __('Band'),
                'reading_status' => __('Reading status'),
            ],
            self::Consolidation => [
                'entity' => __('Entity'),
                'projects_total' => __('Projects'),
                'contract_value_total' => __('Contract sum'),
                'expenditure_total' => __('Expenditure to date'),
                'physical_progress_avg' => __('Average physical progress %'),
                'obligations_expected' => __('Returns expected'),
                'obligations_submitted' => __('Returns filed'),
                'obligations_on_time' => __('Filed on time'),
                'obligations_missed' => __('Missed'),
                'on_time_rate' => __('On-time rate %'),
                'reports_filed' => __('Progress returns'),
                'reports_approved' => __('Returns approved'),
                'period_expenditure_total' => __('Spend in window'),
                'indicators_reported' => __('Indicators reported'),
                'indicators_on_track' => __('Indicators on track'),
                'indicators_at_risk' => __('Indicators at risk'),
                'indicators_off_track' => __('Indicators off track'),
            ],
        };
    }

    /**
     * The columns a fresh builder session starts with — the ones a secretariat
     * asks for nine times out of ten.
     *
     * @return list<string>
     */
    public function defaultColumns(): array
    {
        return match ($this) {
            self::Projects => ['entity', 'reference', 'title', 'status', 'contract_value', 'physical_progress'],
            self::Reports => ['entity', 'window', 'project', 'status', 'physical_progress', 'submitted_at', 'submitted_late'],
            self::Compliance => ['entity', 'expected', 'submitted', 'on_time', 'missed', 'on_time_rate'],
            self::Indicators => ['entity', 'indicator', 'window', 'target', 'actual', 'achievement', 'band'],
            self::Consolidation => [
                'entity', 'projects_total', 'contract_value_total', 'expenditure_total',
                'obligations_expected', 'obligations_submitted', 'on_time_rate',
                'indicators_reported', 'indicators_on_track',
            ],
        };
    }

    /**
     * Columns this dataset may be grouped by in the preview and the export.
     * Grouping is presentational — it buckets the rows that were already
     * fetched; it never changes the figures.
     *
     * @return list<string>
     */
    public function groupableColumns(): array
    {
        return match ($this) {
            self::Projects => ['entity', 'sector', 'status'],
            self::Reports => ['entity', 'window', 'status'],
            self::Compliance => [],
            self::Indicators => ['entity', 'tier', 'band'],
            self::Consolidation => [],
        };
    }

    /**
     * Columns whose values are numbers, for right-alignment in the preview and
     * the spreadsheet. A count that reads left in a column of counts is a
     * column somebody will misread.
     *
     * @return list<string>
     */
    public function numericColumns(): array
    {
        return match ($this) {
            self::Projects => ['contract_value', 'expenditure', 'physical_progress'],
            self::Reports => ['physical_progress', 'period_expenditure'],
            self::Compliance => ['expected', 'submitted', 'on_time', 'missed', 'waived', 'compliance_rate', 'on_time_rate'],
            self::Indicators => ['baseline', 'target', 'actual', 'achievement'],
            self::Consolidation => [
                'projects_total', 'contract_value_total', 'expenditure_total',
                'physical_progress_avg', 'obligations_expected', 'obligations_submitted',
                'obligations_on_time', 'obligations_missed', 'on_time_rate',
                'reports_filed', 'reports_approved', 'period_expenditure_total',
                'indicators_reported', 'indicators_on_track', 'indicators_at_risk',
                'indicators_off_track',
            ],
        };
    }
}
