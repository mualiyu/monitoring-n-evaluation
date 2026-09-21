<?php

namespace App\Exceptions\Consolidation;

use DomainException;

/**
 * A domain precondition refused the write. One class with named constructors,
 * as in every other module here: the rules are testable by message, greppable
 * in one place, and every Action states its guard in the same vocabulary.
 *
 * These are DOMAIN failures, not authorization failures — a State Admin with
 * every permission in the instance still cannot sign off figures they compiled
 * themselves. Authorization denials surface as Laravel's AuthorizationException.
 */
class ConsolidationRuleViolation extends DomainException
{
    public static function windowAlreadyConsolidated(string $type, string $window): self
    {
        return new self(
            "A [{$type}] consolidation already exists for [{$window}]. A state issues ONE of each per window — "
            .'reopen the existing one, or discard it first.'
        );
    }

    public static function noFiguresCompiled(): self
    {
        return new self(
            'This consolidation has no figures yet. Compile the entities\' returns before sending it up the chain — '
            .'a roll-up with nothing rolled up is a title page.'
        );
    }

    public static function summaryRequired(string $heading): self
    {
        return new self(
            "The [{$heading}] section is empty. A reviewer handed figures with no argument around them has been "
            .'handed a spreadsheet, not a report.'
        );
    }

    public static function notEditable(string $status): self
    {
        return new self(
            "This consolidation is [{$status}] and its narrative is closed to editing. "
            .'Return it for rework if the text has to change.'
        );
    }

    public static function approverIsCompiler(): self
    {
        return new self(
            'The officer who compiled these figures cannot also sign them off. Separation of compilation and '
            .'approval is what makes a state report an assurance rather than an assertion.'
        );
    }

    public static function approverIsSubmitter(): self
    {
        return new self(
            'The officer who sent this consolidation up the chain cannot also approve it — '
            .'sending it up and signing it off are two different people\'s acts.'
        );
    }

    public static function reasonRequired(): self
    {
        return new self(
            'Returning a consolidation requires a stated reason — the secretariat has to know what to fix.'
        );
    }

    public static function cadenceMismatch(string $type, string $cadence, string $windowCadence): self
    {
        return new self(
            "A [{$type}] consolidation is compiled against a [{$cadence}] window; [{$windowCadence}] was chosen. "
            .'The window sets the denominator, so the wrong one makes every rate in the report wrong.'
        );
    }

    public static function snapshotMissing(): self
    {
        return new self(
            'This consolidation was approved without a frozen snapshot, which should be impossible. '
            .'Refusing to publish figures that could still move.'
        );
    }
}
