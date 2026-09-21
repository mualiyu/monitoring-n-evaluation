<?php

namespace App\Exceptions\Indicators;

use App\Enums\FrameworkLevel;
use App\Enums\IndicatorReadingStatus;
use App\Enums\IndicatorTier;
use DomainException;

/**
 * A domain precondition refused the write. One class with named constructors,
 * as in the projects and reporting modules: the rules are testable by message,
 * greppable in one place, and every Action states its guard in the same
 * vocabulary.
 *
 * These are *domain* failures, not authorization failures — a state admin with
 * every permission still cannot validate a figure they recorded themselves.
 * Authorization denials surface as Laravel's AuthorizationException.
 */
class IndicatorRuleViolation extends DomainException
{
    /* ------------------------------------------------------------------ */
    /* The results framework */
    /* ------------------------------------------------------------------ */

    public static function levelCannotNest(FrameworkLevel $parent, FrameworkLevel $child): self
    {
        $accepted = array_map(
            fn (FrameworkLevel $level): string => $level->value,
            $parent->allowedChildLevels(),
        );

        return new self(sprintf(
            'A [%s] statement cannot sit under a [%s] one. A logframe runs impact → outcome → output; [%s] accepts: %s.',
            $child->value,
            $parent->value,
            $parent->value,
            $accepted === [] ? 'nothing — an output is the bottom of the chain' : implode(', ', $accepted),
        ));
    }

    public static function rootMustBeImpact(FrameworkLevel $level): self
    {
        return new self(
            "A [{$level->value}] statement needs a parent — only an impact statement stands on its own. "
            .'An outcome with nothing above it is a result nobody asked for.'
        );
    }

    public static function parentInAnotherFramework(): self
    {
        return new self(
            'A result statement must sit in the same framework as its parent — one project, or the programme tree, '
            .'never a limb grafted across two.'
        );
    }

    public static function frameworkNotEmpty(): self
    {
        return new self(
            'This result statement still carries indicators or statements beneath it. A framework is dismantled '
            .'leaf-first, deliberately — deleting a branch would silently orphan the figures hanging off it.'
        );
    }

    public static function tierDoesNotFitLevel(IndicatorTier $tier, FrameworkLevel $level): self
    {
        $levels = array_map(
            fn (FrameworkLevel $each): string => $each->value,
            $tier->measurableLevels(),
        );

        return new self(sprintf(
            'A [%s] indicator does not measure a [%s] statement — it measures: %s. Nailing an output measure to '
            .'an impact statement is how a logframe quietly becomes a task list.',
            $tier->value,
            $level->value,
            implode(', ', $levels),
        ));
    }

    /* ------------------------------------------------------------------ */
    /* The library */
    /* ------------------------------------------------------------------ */

    public static function definitionRetired(string $code): self
    {
        return new self(
            "Library indicator [{$code}] has been retired and cannot be instantiated. Retired entries stay "
            .'readable so published figures keep their definition, but nothing new is measured against them.'
        );
    }

    public static function definitionInUse(string $code): self
    {
        return new self(
            "Library indicator [{$code}] is in use by at least one MDA. Retire it instead — deleting the "
            .'definition of a figure already published leaves the figure meaning nothing.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Readings */
    /* ------------------------------------------------------------------ */

    public static function indicatorNotActive(string $name): self
    {
        return new self(
            "Indicator [{$name}] is not active: it has no agreed baseline yet, so there is nothing to measure "
            .'movement from. Activate it first.'
        );
    }

    public static function readingNotEditable(IndicatorReadingStatus $status): self
    {
        return new self(
            "A [{$status->value}] reading is no longer editable — a figure freezes at submission. Ask the reviewer "
            .'to send it back if it is wrong.'
        );
    }

    public static function periodInverted(): self
    {
        return new self('A measurement period ends after it starts.');
    }

    public static function duplicateReadingForPeriod(): self
    {
        return new self(
            'This indicator already has a live reading for that period. Two actuals for one period is how the same '
            .'delivery gets counted twice — open the existing one instead.'
        );
    }

    public static function rejectionRequiresReason(): self
    {
        return new self(
            'Sending a reading back requires a stated reason — the person who measured it has to know what to fix, '
            .'and the rejection goes on the data-quality record.'
        );
    }

    public static function validatorIsOriginator(): self
    {
        return new self(
            'The person who recorded or filed a figure cannot validate it. Separating measurement from assurance '
            .'is the entire point of the Data Quality Reviewer role — a self-validated number is an unchecked one.'
        );
    }

    public static function publisherIsValidator(): self
    {
        return new self(
            'The reviewer who validated a figure cannot also publish it while `indicators.require_separate_publisher` '
            .'is on — publication is a second pair of eyes or it is nothing.'
        );
    }
}
