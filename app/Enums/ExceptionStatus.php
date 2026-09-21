<?php

namespace App\Enums;

/**
 * The life of an exception report. Deliberately shorter than the issue chain:
 * an exception report is a NOTICE ("this project has deviated"), not a piece of
 * work. The work it provokes is an Issue, linked to it — which is why there is
 * no `in_progress` here and why `resolved` means "the deviation no longer
 * stands", not "somebody is on it".
 *
 * App\Actions\Issues\TransitionExceptionStatus is the only writer of
 * ExceptionReport::$status.
 */
enum ExceptionStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Straight to resolved is allowed: a deviation an officer can
            // close out on sight should not need a ceremonial acknowledgement
            // step first.
            self::Open => [self::Acknowledged, self::Resolved],
            self::Acknowledged => [self::Resolved],
            self::Resolved => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether this report still stands against the project. The duplicate gate
     * in RaiseExceptionReport asks exactly this: an acknowledged report is
     * still an open condition, so the engine must not raise a second one.
     */
    public function isLive(): bool
    {
        return $this !== self::Resolved;
    }

    /**
     * Which <x-ui.badge> tone this status borrows — see the note on
     * IssueStatus::badgeStatus().
     *
     * `open` is WARNING rather than critical: an unanswered deviation needs
     * attention, but the severity column is what says how bad it is, and
     * colouring every open row red would make the critical ones invisible.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Open => 'behind',            // warning
            self::Acknowledged => 'submitted', // info
            self::Resolved => 'approved',      // positive
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'exclamation-circle',
            self::Acknowledged => 'eye',
            self::Resolved => 'check-circle',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Acknowledged => __('Acknowledged'),
            self::Resolved => __('Resolved'),
        };
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
