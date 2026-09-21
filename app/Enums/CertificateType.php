<?php

namespace App\Enums;

/**
 * The two completion certificates a works contract produces (digest §8, step
 * 5). They are not interchangeable and a state needs both:
 *
 *  - **Practical completion** hands the works over for use and STARTS the
 *    defects-liability period. The contractor is still on the hook.
 *  - **Final completion** closes that period and releases retention. It is the
 *    last thing the state signs, and it is the one that has no "after".
 *
 * Collapsing them into one "completed" artifact would erase the months in
 * between — which is precisely the window in which a state can still compel a
 * contractor to come back and fix a road.
 */
enum CertificateType: string
{
    case PracticalCompletion = 'practical_completion';
    case FinalCompletion = 'final_completion';

    public function label(): string
    {
        return match ($this) {
            self::PracticalCompletion => __('Certificate of practical completion'),
            self::FinalCompletion => __('Certificate of final completion'),
        };
    }

    /** The short form for a table cell or a badge, where the row says the rest. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::PracticalCompletion => __('Practical completion'),
            self::FinalCompletion => __('Final completion'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PracticalCompletion => __('Hands the works over for use and starts the defects-liability period. The contractor remains responsible for making good.'),
            self::FinalCompletion => __('Closes the defects-liability period and releases retention. Nothing follows it.'),
        };
    }

    /**
     * The segment used when a reference number is minted, e.g. WKS/PC/2026/0007.
     * Kept short because it is read aloud over a phone.
     */
    public function referenceSegment(): string
    {
        return match ($this) {
            self::PracticalCompletion => 'PC',
            self::FinalCompletion => 'FC',
        };
    }

    /**
     * Whether a defects-liability end date belongs on this certificate. Only
     * practical completion opens that window; requiring it on a final
     * certificate would ask an officer to date something that has just ended.
     */
    public function opensDefectsLiability(): bool
    {
        return $this === self::PracticalCompletion;
    }

    /**
     * Whether issuing this type moves the PROJECT to `certified`.
     *
     * Both do. The project lifecycle has one certification state, and the
     * second certificate is issued against an already-certified project — so
     * IssueCompletionCertificate asks for the transition only when the project
     * is not there yet, rather than assuming a move is always due.
     */
    public function certifiesTheProject(): bool
    {
        return true;
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
