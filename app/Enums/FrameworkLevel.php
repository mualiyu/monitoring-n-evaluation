<?php

namespace App\Enums;

/**
 * A level in the results chain (manual digest §2: Inputs → Activities →
 * Outputs → Outcomes → Impacts).
 *
 * Only the three RESULT levels are modelled. Inputs and activities are not
 * results — they are what an annual work plan and a budget line carry, and
 * putting them in the logframe is the classic mistake that turns a results
 * framework back into a task list.
 *
 * The tree is strictly three deep: an impact statement is served by outcomes,
 * an outcome by outputs. Same-level nesting is deliberately impossible; the
 * manual's "intermediate outcome per component" is expressed by the INDICATOR
 * tier (App\Enums\IndicatorTier), not by a fourth level of statements.
 */
enum FrameworkLevel: string
{
    case Impact = 'impact';
    case Outcome = 'outcome';
    case Output = 'output';

    /** Depth in the tree, 0 at the root — the builder orders on this. */
    public function depth(): int
    {
        return match ($this) {
            self::Impact => 0,
            self::Outcome => 1,
            self::Output => 2,
        };
    }

    /**
     * The levels a statement at this level may contain.
     *
     * @return list<self>
     */
    public function allowedChildLevels(): array
    {
        return match ($this) {
            self::Impact => [self::Outcome],
            self::Outcome => [self::Output],
            self::Output => [],
        };
    }

    public function accepts(self $child): bool
    {
        return in_array($child, $this->allowedChildLevels(), true);
    }

    /** The level a statement must sit under, or null for a root statement. */
    public function requiredParentLevel(): ?self
    {
        return match ($this) {
            self::Impact => null,
            self::Outcome => self::Impact,
            self::Output => self::Outcome,
        };
    }

    /**
     * The indicator tier a statement at this level is normally measured by
     * (manual digest §2: PDO at the top, intermediate per component, output
     * per work-plan activity). A default offered by the builder, not a rule —
     * IndicatorTier::fitsLevel() is what is actually enforced.
     */
    public function defaultTier(): IndicatorTier
    {
        return match ($this) {
            self::Impact => IndicatorTier::Pdo,
            self::Outcome => IndicatorTier::Intermediate,
            self::Output => IndicatorTier::Output,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Impact => __('Impact'),
            self::Outcome => __('Outcome'),
            self::Output => __('Output'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Impact => __('The long-term change this programme exists to bring about.'),
            self::Outcome => __('The change in behaviour, access or condition the outputs are expected to produce.'),
            self::Output => __('What delivery actually produces — the goods, works and services handed over.'),
        };
    }

    /** Icon paired with the label, so a level is never conveyed by colour alone. */
    public function icon(): string
    {
        return match ($this) {
            self::Impact => 'flag',
            self::Outcome => 'arrow-trending-up',
            self::Output => 'squares',
        };
    }
}
