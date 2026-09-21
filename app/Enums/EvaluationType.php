<?php

namespace App\Enums;

/**
 * The four evaluation formats the manual recognises (digest §3, plan §4),
 * distinguished by WHEN they are conducted and therefore by what question they
 * can honestly answer:
 *
 *  - mid_term  — while delivery is still changeable; asks "is this working, and
 *                what should we change now". The Nasarawa lifecycle raises it
 *                at 50% physical completion (`monitoring.mid_term_trigger_percent`).
 *  - terminal  — at completion; asks "what did this deliver against its targets".
 *  - ex_post   — some months after handover; asks "did the delivery hold".
 *  - impact    — attributional; asks "what changed for people because of this",
 *                and is the only one that needs a counterfactual.
 *
 * The type is a property of the commission and never changes afterwards: an
 * evaluation that set out to be a mid-term review cannot be relabelled an
 * impact study once its findings are inconvenient.
 */
enum EvaluationType: string
{
    case MidTerm = 'mid_term';
    case Terminal = 'terminal';
    case ExPost = 'ex_post';
    case Impact = 'impact';

    public function label(): string
    {
        return match ($this) {
            self::MidTerm => __('Mid-term evaluation'),
            self::Terminal => __('Terminal evaluation'),
            self::ExPost => __('Ex-post evaluation'),
            self::Impact => __('Impact evaluation'),
        };
    }

    /** One line on what this format is for — shown next to the choice, not in a tooltip. */
    public function description(): string
    {
        return match ($this) {
            self::MidTerm => __('Conducted while delivery can still change course — typically at the half-way point.'),
            self::Terminal => __('Conducted at completion, against the targets the intervention set itself.'),
            self::ExPost => __('Conducted months after handover, to see whether the results held.'),
            self::Impact => __('Attributional study of what changed for people, against a counterfactual.'),
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
