<?php

namespace App\Enums;

/**
 * The inspector's verdict on the site, recorded at submission. Four levels,
 * because three collapse "there are defects" and "stop work" into one word and
 * a state that cannot tell those apart cannot act on either.
 *
 * The top two rungs are not decoration: `major_issues` and `work_stopped`
 * notify the MDA admin the moment the report is filed (design §5). That is the
 * manual's exception-reporting reflex — the person who can halt a payment
 * hears about a failing site from the system, not from the next monthly
 * review meeting.
 */
enum InspectionOutcome: string
{
    case Satisfactory = 'satisfactory';
    case MinorIssues = 'minor_issues';
    case MajorIssues = 'major_issues';
    case WorkStopped = 'work_stopped';

    public function label(): string
    {
        return match ($this) {
            self::Satisfactory => __('Satisfactory'),
            self::MinorIssues => __('Minor issues'),
            self::MajorIssues => __('Major issues'),
            self::WorkStopped => __('Work stopped'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Satisfactory => __('Work is proceeding to specification; nothing requires action.'),
            self::MinorIssues => __('Defects or delays the contractor can correct within the normal cycle.'),
            self::MajorIssues => __('Serious deviation from specification, schedule or safety. Escalated on filing.'),
            self::WorkStopped => __('Work has halted or been halted on site. Escalated on filing.'),
        };
    }

    /**
     * Whether filing this verdict alerts the MDA admin immediately. The rule
     * lives on the enum rather than in the notifying job so the screen can
     * warn the inspector, before they submit, that this answer will be read
     * tonight by a permanent secretary.
     */
    public function requiresEscalation(): bool
    {
        return $this === self::MajorIssues || $this === self::WorkStopped;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Satisfactory => 'on_track',
            self::MinorIssues => 'behind',
            self::MajorIssues, self::WorkStopped => 'overdue',
        };
    }
}
