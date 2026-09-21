<?php

namespace App\Enums;

/**
 * The six-step monitoring lifecycle of the Nasarawa BPP guideline
 * (digest §8), minus the commencement notice that precedes it: an initial
 * compliance inspection days after award, routine visits on the configured
 * interval, a mid-term quality assessment at half completion, a final
 * inspection that gates the completion certificate, and post-completion
 * monitoring six to twelve months later.
 *
 * Only ONE of these is produced by machine. `routine` is what
 * ProposeRoutineInspections generates, because it is the only type whose
 * trigger is a clock rather than an event — the others are raised by an award,
 * a progress figure, a completion claim or a handover date, and inventing them
 * on a timer would fill a ministry's diary with visits nobody asked for.
 */
enum InspectionType: string
{
    case Initial = 'initial';
    case Routine = 'routine';
    case MidTerm = 'mid_term';
    case Final = 'final';
    case PostCompletion = 'post_completion';

    public function label(): string
    {
        return match ($this) {
            self::Initial => __('Initial inspection'),
            self::Routine => __('Routine monitoring visit'),
            self::MidTerm => __('Mid-term quality assessment'),
            self::Final => __('Final inspection'),
            self::PostCompletion => __('Post-completion monitoring'),
        };
    }

    /**
     * The one-line reason this visit happens, shown under the type on the
     * schedule form. Field monitors are not M&E specialists and the manual's
     * distinctions are not obvious from the names alone.
     */
    public function purpose(): string
    {
        return match ($this) {
            self::Initial => __('Compliance review shortly after mobilisation: is the site what the contract describes?'),
            self::Routine => __('Periodic verification of reported progress against what is on the ground.'),
            self::MidTerm => __('Quality assessment around half completion, before the point of no return.'),
            self::Final => __('Verification that the works are complete, ahead of certification.'),
            self::PostCompletion => __('Are the works still standing and in use, months after handover?'),
        };
    }

    /** Whether the scheduling engine may propose this type on a timer. */
    public function isAutomatable(): bool
    {
        return $this === self::Routine;
    }
}
