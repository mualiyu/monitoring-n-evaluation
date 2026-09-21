<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\ReportingCadence;

/**
 * The declarative map of everything a state may configure.
 *
 * WHY A MAP AND NOT A FORM. Every number in config/platform.php is a policy
 * decision a state can take differently — one gives MDAs 14 days after month
 * end where its neighbour gives 7; one calls its entities "MDAs" and another
 * "Agencies". Hand-writing a field per number would mean two screens (instance
 * and per-MDA override) drifting apart the first time one is edited, and a
 * validator per screen deciding independently what "70" means for an indicator
 * band. One map drives the instance screen, the MDA override screen, the
 * validator inside SaveSetting, and the round-trip test — so a new setting is
 * one entry here and appears, validated, on both screens.
 *
 * `group` + `key` are deliberately the exact path of the config fallback, so
 * App\Support\SettingsRepository's chain (tenant override → instance setting →
 * config default) resolves without a translation table. Adding a setting whose
 * group/key does not exist in config/platform.php is fine: the `default` below
 * is the last resort the repository is handed.
 *
 * `tenantOverridable` is the honest division of authority. Terminology and
 * instance identity are state-wide by definition — an MDA renaming "MDA" for
 * everyone else is not a workspace preference. Deadlines, inspection policy,
 * exception thresholds, indicator bands and evaluation scoring ARE things a
 * ministry may legitimately run tighter than the state floor.
 */
final class SettingDefinitions
{
    /**
     * Every definition, keyed by "group.key".
     *
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        static $definitions = null;

        if ($definitions === null) {
            $definitions = [];

            foreach (self::build() as $definition) {
                $definitions[$definition->id()] = $definition;
            }
        }

        /** @var array<string, SettingDefinition> $definitions */
        return $definitions;
    }

    public static function find(string $group, string $key): ?SettingDefinition
    {
        return self::all()[$group.'.'.$key] ?? null;
    }

    /**
     * The definitions of one group, in declaration order.
     *
     * @return list<SettingDefinition>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (SettingDefinition $definition): bool => $definition->group === $group,
        ));
    }

    /**
     * Groups an MDA may override, in the order the workspace screen shows them.
     *
     * @return list<string>
     */
    public static function tenantGroups(): array
    {
        $groups = [];

        foreach (self::all() as $definition) {
            if ($definition->tenantOverridable && ! in_array($definition->group, $groups, true)) {
                $groups[] = $definition->group;
            }
        }

        return $groups;
    }

    /**
     * Every group, in the order the instance screen shows them.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::all() as $definition) {
            if (! in_array($definition->group, $groups, true)) {
                $groups[] = $definition->group;
            }
        }

        return $groups;
    }

    /** Heading for a group of settings. */
    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'instance' => __('Instance identity'),
            'terminology' => __('Terminology'),
            'branding' => __('Branding'),
            'reporting' => __('Reporting deadlines'),
            'inspections' => __('Site inspection policy'),
            'exceptions' => __('Exception thresholds'),
            'workplans' => __('Annual work plans'),
            'indicators' => __('Indicator achievement bands'),
            'evaluation' => __('Evaluation criteria'),
            'monitoring' => __('Monitoring lifecycle'),
            default => ucfirst(str_replace('_', ' ', $group)),
        };
    }

    /** One sentence explaining why the group exists. */
    public static function groupDescription(string $group): string
    {
        return match ($group) {
            'instance' => __('What this deployment calls itself, and the currency and clock it reports in.'),
            'terminology' => __('The vocabulary of this state. Screens read these words, so "MDA" can become "Agency" everywhere at once.'),
            'branding' => __('The instance lockup and colour. Each workspace may override its own on its entity record.'),
            'reporting' => __('The statutory reporting calendar: how long an MDA has after a period ends, when it is reminded, and when lateness escalates.'),
            'inspections' => __('How often projects are inspected, how quickly the visit must be written up, and what the evidence must prove.'),
            'exceptions' => __('How far delivery may deviate before the platform raises an exception report against a project.'),
            'workplans' => __('What an annual work plan must satisfy before it can be approved.'),
            'indicators' => __('The achievement bands behind every traffic light on the dashboards.'),
            'evaluation' => __('The criteria an evaluation is scored against, and the top of the scale.'),
            'monitoring' => __('The lifecycle clock: notice periods, the first inspection, the mid-term trigger and the post-completion window.'),
            default => '',
        };
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function build(): array
    {
        return [
            /* ---------------------------------------------------------- */
            /* Instance identity — state-wide by definition */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'instance',
                key: 'name',
                label: __('Instance name'),
                hint: __('Shown in the browser title, on emails and in the sidebar lockup.'),
                type: SettingDefinition::TYPE_STRING,
                default: 'M&E Platform',
                tenantOverridable: false,
                rules: ['required', 'max:120'],
            ),
            new SettingDefinition(
                group: 'instance',
                key: 'short_name',
                label: __('Short name'),
                hint: __('Used where space is tight — mobile headers, chips, exports.'),
                type: SettingDefinition::TYPE_STRING,
                default: 'M&E',
                tenantOverridable: false,
                rules: ['required', 'max:40'],
            ),
            new SettingDefinition(
                group: 'instance',
                key: 'currency',
                label: __('Reporting currency'),
                hint: __('Every budget, contract sum and expenditure figure is shown in this currency.'),
                type: SettingDefinition::TYPE_SELECT,
                default: 'NGN',
                tenantOverridable: false,
                options: ['NGN' => 'NGN — Naira', 'USD' => 'USD — US Dollar', 'GBP' => 'GBP — Pound Sterling', 'EUR' => 'EUR — Euro'],
            ),
            new SettingDefinition(
                group: 'instance',
                key: 'timezone',
                label: __('Instance timezone'),
                hint: __('Dates are stored in UTC and displayed here. Deadlines are judged against this clock.'),
                type: SettingDefinition::TYPE_SELECT,
                default: 'Africa/Lagos',
                tenantOverridable: false,
                options: [
                    'Africa/Lagos' => 'Africa/Lagos (WAT)',
                    'Africa/Accra' => 'Africa/Accra (GMT)',
                    'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
                    'UTC' => 'UTC',
                ],
            ),

            /* ---------------------------------------------------------- */
            /* Terminology — the "MDA" vs "Agency" vocabulary */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'terminology',
                key: 'tenant',
                label: __('Word for one entity'),
                hint: __('"MDA", "Agency", "Ministry"… Used in the singular throughout the app.'),
                type: SettingDefinition::TYPE_STRING,
                default: 'MDA',
                tenantOverridable: false,
                rules: ['required', 'max:40'],
            ),
            new SettingDefinition(
                group: 'terminology',
                key: 'tenant_plural',
                label: __('Word for several entities'),
                hint: __('The plural of the word above.'),
                type: SettingDefinition::TYPE_STRING,
                default: 'MDAs',
                tenantOverridable: false,
                rules: ['required', 'max:40'],
            ),
            new SettingDefinition(
                group: 'terminology',
                key: 'oversight',
                label: __('Word for the oversight body'),
                hint: __('"State Secretariat", "Ministry of Budget & Planning", "Governor\'s Office"…'),
                type: SettingDefinition::TYPE_STRING,
                default: 'State oversight',
                tenantOverridable: false,
                rules: ['required', 'max:60'],
            ),
            new SettingDefinition(
                group: 'terminology',
                key: 'project',
                label: __('Word for one delivery record'),
                hint: __('"Project", "Programme", "Intervention"…'),
                type: SettingDefinition::TYPE_STRING,
                default: 'Project',
                tenantOverridable: false,
                rules: ['required', 'max:40'],
            ),

            /* ---------------------------------------------------------- */
            /* Branding */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'branding',
                key: 'primary_color',
                label: __('Instance brand colour'),
                hint: __('Six-digit hex. Leave blank to keep the platform default palette.'),
                type: SettingDefinition::TYPE_STRING,
                default: '',
                tenantOverridable: false,
                rules: ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'support_email',
                label: __('Support address'),
                hint: __('Where a user is told to write when something is wrong.'),
                type: SettingDefinition::TYPE_STRING,
                default: '',
                tenantOverridable: false,
                rules: ['nullable', 'email:rfc', 'max:160'],
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'footer_note',
                label: __('Footer note'),
                hint: __('A line shown at the foot of exported reports and PDFs.'),
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                tenantOverridable: false,
            ),

            /* ---------------------------------------------------------- */
            /* Reporting deadlines */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'reporting',
                key: 'default_frequency',
                label: __('Default reporting cadence'),
                hint: __('The rhythm a project reports on when it names none of its own.'),
                type: SettingDefinition::TYPE_SELECT,
                default: ReportingCadence::Monthly->value,
                tenantOverridable: true,
                options: self::cadenceOptions(),
            ),
            new SettingDefinition(
                group: 'reporting',
                key: 'monthly_due_days',
                label: __('Monthly return due (days after month end)'),
                hint: __('Day 7 means the January return is due on 7 February.'),
                type: SettingDefinition::TYPE_INT,
                default: 7,
                tenantOverridable: true,
                rules: ['min:1', 'max:60'],
            ),
            new SettingDefinition(
                group: 'reporting',
                key: 'quarterly_due_days',
                label: __('Quarterly return due (days after quarter end)'),
                hint: __('The six-monthly and annual returns follow statutory calendar rules instead.'),
                type: SettingDefinition::TYPE_INT,
                default: 14,
                tenantOverridable: true,
                rules: ['min:1', 'max:90'],
            ),
            new SettingDefinition(
                group: 'reporting',
                key: 'reminder_days_before',
                label: __('Reminder ladder (days before due)'),
                hint: __('Comma separated, e.g. 7, 3, 1. Each rung fires exactly once per obligation.'),
                type: SettingDefinition::TYPE_INTS,
                default: [7, 3, 1],
                tenantOverridable: true,
            ),
            new SettingDefinition(
                group: 'reporting',
                key: 'overdue_escalation_days',
                label: __('Escalation ladder (days past due)'),
                hint: __('Comma separated, e.g. 1, 7 — first the entity administrator, then state oversight.'),
                type: SettingDefinition::TYPE_INTS,
                default: [1, 7],
                tenantOverridable: true,
            ),
            new SettingDefinition(
                group: 'reporting',
                key: 'allow_late_submission',
                label: __('Accept late returns'),
                hint: __('On: a late return is accepted and flagged. Off: the window hard-closes and a blocked entity simply never reports, which is worse data.'),
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                tenantOverridable: true,
            ),
            new SettingDefinition(
                group: 'reporting',
                key: 'require_separate_approver',
                label: __('Approver must differ from reviewer'),
                hint: __('Turn off only for a single-officer entity where nobody else can sign.'),
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                tenantOverridable: true,
            ),

            /* ---------------------------------------------------------- */
            /* Inspections */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'inspections',
                key: 'routine_interval_months',
                label: __('Months between routine inspections'),
                hint: __('Applies to a project that is in progress.'),
                type: SettingDefinition::TYPE_INT,
                default: 1,
                tenantOverridable: true,
                rules: ['min:1', 'max:24'],
            ),
            new SettingDefinition(
                group: 'inspections',
                key: 'report_due_days',
                label: __('Days to file an inspection report'),
                hint: __('After that the visit is flagged as outstanding field work.'),
                type: SettingDefinition::TYPE_INT,
                default: 3,
                tenantOverridable: true,
                rules: ['min:1', 'max:60'],
            ),
            new SettingDefinition(
                group: 'inspections',
                key: 'geofence_metres',
                label: __('Geofence tolerance (metres)'),
                hint: __('How far a geotagged photograph may sit from the recorded site before the evidence is flagged. 0 disables the check.'),
                type: SettingDefinition::TYPE_INT,
                default: 2000,
                tenantOverridable: true,
                rules: ['min:0', 'max:50000'],
            ),
            new SettingDefinition(
                group: 'inspections',
                key: 'require_photo_evidence',
                label: __('Require a photograph on every inspection'),
                hint: __('An inspection with no picture is one person\'s word.'),
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                tenantOverridable: true,
            ),

            /* ---------------------------------------------------------- */
            /* Annual work plans */
            /* ---------------------------------------------------------- */
            // Both of these were already READ by TransitionWorkplanStatus and
            // defined nowhere, so they silently resolved to their hard-coded
            // fallbacks and SaveSetting rejected them as "Unknown setting" —
            // the manual's own output-indicator rule could not be switched on.
            new SettingDefinition(
                group: 'workplans',
                key: 'require_output_indicator',
                label: __('Every activity must name an output indicator'),
                hint: __('The M&E manual requires it. Leave off while the results framework is still being built — otherwise no plan can be approved.'),
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                tenantOverridable: true,
            ),
            new SettingDefinition(
                group: 'workplans',
                key: 'require_separate_approver',
                label: __('The approver must not be the person who submitted the plan'),
                hint: __('Turn off only for a single-officer entity where nobody else can sign.'),
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                tenantOverridable: true,
            ),

            /* ---------------------------------------------------------- */
            /* Exception thresholds */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'exceptions',
                key: 'schedule_slippage_points',
                label: __('Schedule slippage tolerance (percentage points)'),
                hint: __('How far physical progress may fall behind elapsed time before an exception is raised.'),
                type: SettingDefinition::TYPE_INT,
                default: 15,
                tenantOverridable: true,
                rules: ['min:1', 'max:100'],
            ),
            new SettingDefinition(
                group: 'exceptions',
                key: 'expenditure_variance_points',
                label: __('Expenditure variance tolerance (percentage points)'),
                hint: __('How far spend may run ahead of physical progress before an exception is raised.'),
                type: SettingDefinition::TYPE_INT,
                default: 20,
                tenantOverridable: true,
                rules: ['min:1', 'max:100'],
            ),
            new SettingDefinition(
                group: 'exceptions',
                key: 'reporting_overdue_days',
                label: __('Days overdue before a compliance exception'),
                hint: __('An unmet reporting obligation older than this raises an exception against the project.'),
                type: SettingDefinition::TYPE_INT,
                default: 14,
                tenantOverridable: true,
                rules: ['min:1', 'max:180'],
            ),

            /* ---------------------------------------------------------- */
            /* Indicator bands */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'indicators',
                key: 'on_track_percent',
                label: __('On track at or above (% of target)'),
                hint: __('Green on every dashboard traffic light.'),
                type: SettingDefinition::TYPE_INT,
                default: 90,
                tenantOverridable: true,
                rules: ['min:1', 'max:100'],
            ),
            new SettingDefinition(
                group: 'indicators',
                key: 'at_risk_percent',
                label: __('At risk at or above (% of target)'),
                hint: __('Amber. Below this is red. Must sit under the on-track band.'),
                type: SettingDefinition::TYPE_INT,
                default: 70,
                tenantOverridable: true,
                rules: ['min:1', 'max:100'],
            ),
            new SettingDefinition(
                group: 'indicators',
                key: 'require_validation_for_dashboards',
                label: __('Only validated readings count on dashboards'),
                hint: __('On: a figure must pass data-quality review before it is quotable.'),
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                tenantOverridable: true,
            ),

            /* ---------------------------------------------------------- */
            /* Evaluation */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'evaluation',
                key: 'criteria',
                label: __('Evaluation criteria'),
                hint: __('Comma separated. The OECD-DAC five by default; add your own, e.g. gender responsiveness.'),
                type: SettingDefinition::TYPE_STRINGS,
                default: ['relevance', 'efficiency', 'effectiveness', 'impact', 'sustainability'],
                tenantOverridable: true,
            ),
            new SettingDefinition(
                group: 'evaluation',
                key: 'score_max',
                label: __('Top of the scoring scale'),
                hint: __('Every criterion is scored from 1 to this number.'),
                type: SettingDefinition::TYPE_INT,
                default: 5,
                tenantOverridable: true,
                rules: ['min:2', 'max:10'],
            ),

            /* ---------------------------------------------------------- */
            /* Monitoring lifecycle */
            /* ---------------------------------------------------------- */
            new SettingDefinition(
                group: 'monitoring',
                key: 'commencement_notice_days',
                label: __('Days to serve the commencement notice'),
                hint: __('From award to the contractor receiving notice to begin.'),
                type: SettingDefinition::TYPE_INT,
                default: 3,
                tenantOverridable: true,
                rules: ['min:1', 'max:60'],
            ),
            new SettingDefinition(
                group: 'monitoring',
                key: 'initial_inspection_days',
                label: __('Days from commencement to first inspection'),
                hint: __('The first time anyone from the state stands on the site.'),
                type: SettingDefinition::TYPE_INT,
                default: 10,
                tenantOverridable: true,
                rules: ['min:1', 'max:120'],
            ),
            new SettingDefinition(
                group: 'monitoring',
                key: 'mid_term_trigger_percent',
                label: __('Physical progress that flags a mid-term evaluation (%)'),
                hint: __('An event, never a lifecycle status.'),
                type: SettingDefinition::TYPE_INT,
                default: 50,
                tenantOverridable: true,
                rules: ['min:1', 'max:99'],
            ),
            new SettingDefinition(
                group: 'monitoring',
                key: 'post_completion_review_months',
                label: __('Post-completion monitoring window (months)'),
                hint: __('How long after the actual end date a certified project stays under review before it may be closed.'),
                type: SettingDefinition::TYPE_INT,
                default: 6,
                tenantOverridable: true,
                rules: ['min:1', 'max:60'],
            ),
            new SettingDefinition(
                group: 'monitoring',
                key: 'require_final_inspection_for_certification',
                label: __('Require a final inspection before certification'),
                hint: __('Turn on once site inspections are in routine use.'),
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                tenantOverridable: true,
            ),
        ];
    }

    /** @return array<string, string> */
    private static function cadenceOptions(): array
    {
        $options = [];

        foreach (ReportingCadence::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
