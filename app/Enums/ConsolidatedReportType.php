<?php

namespace App\Enums;

/**
 * What kind of roll-up this is (manual digest §4): the statutory six-monthly
 * consolidation the Secretariat prepares for the Commissioner, the Annual
 * Performance Report consolidated against the predetermined indicator list,
 * the quarterly review pack, and the ad-hoc thematic study.
 *
 * The narrative skeleton belongs to the TYPE, not to the screen: an APR has a
 * fixed shape a state is judged on, and an officer must not be able to ship
 * one missing its recommendations chapter because the form let them.
 */
enum ConsolidatedReportType: string
{
    case Quarterly = 'quarterly';
    case Biannual = 'biannual';
    case AnnualApr = 'annual_apr';
    case Thematic = 'thematic';

    public function label(): string
    {
        return match ($this) {
            self::Quarterly => __('Quarterly review'),
            self::Biannual => __('Biannual consolidation'),
            self::AnnualApr => __('Annual Performance Report'),
            self::Thematic => __('Thematic report'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Quarterly => __('The quarterly progress pack rolled up from every entity’s returns.'),
            self::Biannual => __('The statutory six-monthly consolidation prepared for the Commissioner.'),
            self::AnnualApr => __('The Annual Performance Report: the year’s delivery measured against the predetermined indicator list.'),
            self::Thematic => __('A study of one sector, programme or question, drawing on the same figures.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Quarterly => 'calendar-days',
            self::Biannual => 'document-text',
            self::AnnualApr => 'flag',
            self::Thematic => 'magnifying-glass',
        };
    }

    /**
     * The reporting cadence a consolidation of this type is normally opened
     * against. Thematic reports answer a question rather than a calendar, so
     * they accept any window — hence the null.
     */
    public function expectedCadence(): ?ReportingCadence
    {
        return match ($this) {
            self::Quarterly => ReportingCadence::Quarterly,
            self::Biannual => ReportingCadence::Biannual,
            self::AnnualApr => ReportingCadence::Annual,
            self::Thematic => null,
        };
    }

    /**
     * Only ONE of each of these may exist per window: a state has one APR for
     * a year and one six-monthly consolidation for a half. Thematic reports
     * are deliberately unbounded — a state may study three questions in one
     * quarter.
     */
    public function isUniquePerPeriod(): bool
    {
        return $this !== self::Thematic;
    }

    /**
     * Whether this type renders through the State APR template rather than the
     * general consolidated-report template.
     */
    public function usesAnnualTemplate(): bool
    {
        return $this === self::AnnualApr;
    }

    /**
     * The narrative skeleton, in order: key => heading. Seeded by
     * OpenConsolidation and thereafter fixed — a section may be left empty,
     * but it cannot be quietly dropped, because the omission is the finding.
     *
     * The APR outline follows the manual's report structure (digest §4 and
     * the 11-section evaluation format it shares an ancestry with).
     *
     * @return array<string, string>
     */
    public function sectionSkeleton(): array
    {
        $common = [
            'executive_summary' => __('Executive summary'),
            'introduction' => __('Introduction and background'),
            'portfolio_performance' => __('Portfolio performance'),
            'reporting_compliance' => __('Reporting compliance'),
            'challenges' => __('Challenges and mitigation'),
            'recommendations' => __('Recommendations'),
        ];

        return match ($this) {
            self::AnnualApr => [
                'executive_summary' => __('Executive summary'),
                'introduction' => __('Introduction and background'),
                'methodology' => __('Methodology and limitations'),
                'portfolio_performance' => __('Portfolio performance'),
                'indicator_performance' => __('Performance against the indicator list'),
                'reporting_compliance' => __('Reporting compliance'),
                'findings' => __('Key findings'),
                'challenges' => __('Challenges and mitigation'),
                'recommendations' => __('Recommendations'),
                'lessons_learned' => __('Lessons learned'),
                'outlook' => __('Conclusion and outlook'),
            ],
            self::Thematic => [
                'executive_summary' => __('Executive summary'),
                'scope' => __('Scope and evaluation questions'),
                'methodology' => __('Methodology and limitations'),
                'findings' => __('Findings'),
                'recommendations' => __('Recommendations'),
            ],
            default => $common,
        };
    }

    /**
     * The section that must carry text before the roll-up may be sent up the
     * chain. Every type has one, and it is always the summary — a reviewer
     * who is handed figures with no argument around them has been handed a
     * spreadsheet, not a report.
     */
    public function requiredSectionKey(): string
    {
        return 'executive_summary';
    }
}
