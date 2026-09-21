<?php

namespace App\Enums;

/**
 * How one checklist item is answered. The shape of the answer decides which
 * column on `site_inspection_responses` carries it, and whether a "no" counts
 * as a finding — which is the whole reason the type is stored rather than
 * inferred from whatever the inspector typed.
 *
 * Deliberately four, and deliberately no "multiple choice": a state that needs
 * one adds a case here and a column read; a JSON blob of arbitrary answer
 * shapes would make "how many sites failed the drainage question this quarter"
 * unanswerable in SQL, which is the only question the checklist exists for.
 */
enum ChecklistResponseType: string
{
    case YesNo = 'yes_no';
    case Rating = 'rating';
    case Numeric = 'numeric';
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::YesNo => __('Yes / No'),
            self::Rating => __('Rating'),
            self::Numeric => __('Number'),
            self::Text => __('Written answer'),
        };
    }

    /** Which column on the response row holds an answer of this shape. */
    public function column(): string
    {
        return match ($this) {
            self::YesNo => 'value_boolean',
            self::Rating, self::Numeric => 'value_number',
            self::Text => 'value_text',
        };
    }

    /**
     * Whether an answer of this shape can be judged automatically against the
     * item's finding rule. A written answer never can — an inspector's prose
     * is read by a person, and a keyword match would quietly turn "no defects
     * of any kind" into a finding.
     */
    public function isGradable(): bool
    {
        return $this !== self::Text;
    }
}
