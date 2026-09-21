<?php

namespace App\Enums;

/**
 * The life of a commencement notice, as a three-state machine whose only
 * writers are App\Actions\Lifecycle\IssueCommencementNotice and
 * AcknowledgeCommencementNotice.
 *
 *   pending ──issue──▶ issued ──contractor signs──▶ acknowledged
 *
 * `pending` is a real state, not a placeholder: the award creates the
 * OBLIGATION to serve a notice, and a notice that has not been served within
 * `monitoring.commencement_notice_days` is the thing the overdue sweep exists
 * to flag. Modelling only served notices would make lateness unrepresentable.
 *
 * Acknowledgement is terminal. A contractor can only confirm receipt once, and
 * un-receiving a served notice is not a thing a system should offer.
 */
enum CommencementNoticeStatus: string
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Acknowledged = 'acknowledged';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Issued],
            self::Issued => [self::Acknowledged],
            self::Acknowledged => [],
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

    /** Whether the contractor has actually been served. */
    public function isServed(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Not yet issued'),
            self::Issued => __('Issued'),
            self::Acknowledged => __('Acknowledged by contractor'),
        };
    }

    /**
     * The badge key from the design system's vocabulary. `pending` and
     * `submitted` already exist there with the right tone and icon; passing an
     * explicit :label keeps the wording of THIS domain.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Issued => 'submitted',
            self::Acknowledged => 'approved',
        };
    }

    /** @return array<string, string> value => label, for a <select>. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
