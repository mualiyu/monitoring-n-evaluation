<?php

namespace App\Enums;

/**
 * The follow-up loop (digest §7.9 "knowledge management: lessons learned,
 * recommendations feeding decisions"). This enum is the reason the evaluation
 * module is worth building: an evaluation nobody acts on is a document, and
 * the only thing separating the two is a register that can answer "what did we
 * say, who owns it, and did it happen".
 *
 * App\Actions\Evaluation\TransitionRecommendationStatus is the only writer of
 * Recommendation::$status.
 */
enum RecommendationStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Implemented = 'implemented';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /**
     * States reachable from this one.
     *
     * `rejected` is deliberately reachable ONLY from `open`: an addressee
     * declines a recommendation when it lands, with a reason, or they own it.
     * Accepting it and then quietly rejecting it months later is precisely the
     * move the register exists to make visible — that path is a supersession,
     * which names the recommendation that replaced it.
     *
     * `implemented` is terminal. Evidence of implementation is attached before
     * the move (the chokepoint requires it), and a claim that later proves
     * wrong is corrected by a fresh recommendation, not by editing history.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Accepted, self::Rejected, self::Superseded],
            self::Accepted => [self::InProgress, self::Implemented, self::Superseded],
            self::InProgress => [self::Implemented, self::Superseded],
            self::Implemented, self::Rejected, self::Superseded => [],
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
     * Whether this recommendation is still owed. THE definition of outstanding
     * for the register, the overdue sweep and the stat row — one method, so a
     * screen and a nightly job can never disagree about what "not done" means.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Open || $this === self::Accepted || $this === self::InProgress;
    }

    /**
     * Whether the move needs a stated reason. Declining and superseding both
     * do: a recommendation that was simply dropped, with nothing on the record
     * explaining why, is how an evaluation stops mattering.
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected || $this === self::Superseded;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Accepted => __('Accepted'),
            self::InProgress => __('Being implemented'),
            self::Implemented => __('Implemented'),
            self::Rejected => __('Declined'),
            self::Superseded => __('Superseded'),
        };
    }

    /**
     * The <x-ui.badge> key this status borrows its TONE from. The badge
     * component belongs to the shared design system, which this module does
     * not own, so every call site passes :status (tone) together with an
     * explicit :label and :icon rather than adding keys to someone else's map.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Open => 'pending',
            self::Accepted => 'submitted',
            self::InProgress => 'in_progress',
            self::Implemented => 'approved',
            self::Rejected => 'rejected',
            self::Superseded => 'cancelled',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'inbox',
            self::Accepted => 'check',
            self::InProgress => 'arrow-path',
            self::Implemented => 'check-circle',
            self::Rejected => 'x-circle',
            self::Superseded => 'arrows-right-left',
        };
    }

    /**
     * Options for a <x-ui.form.select> — value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
