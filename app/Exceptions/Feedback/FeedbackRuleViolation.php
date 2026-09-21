<?php

namespace App\Exceptions\Feedback;

use App\Enums\FeedbackStatus;
use DomainException;

/**
 * A domain precondition refused a feedback write. One class with named
 * constructors, the way App\Exceptions\Projects\ProjectRuleViolation does it:
 * the rules stay greppable in one place and every Action states its guard in
 * the same vocabulary.
 *
 * These are DOMAIN failures, not authorization failures. A moderator with
 * every permission still cannot publish something already marked as spam
 * without first un-marking it; authorization denials surface as Laravel's
 * AuthorizationException through FeedbackPolicy.
 */
class FeedbackRuleViolation extends DomainException
{
    public static function rateLimited(int $availableInSeconds): self
    {
        return new self(sprintf(
            'Too many submissions from this address. Try again in about %d minute(s).',
            max(1, (int) ceil($availableInSeconds / 60)),
        ));
    }

    public static function emptySubmission(): self
    {
        return new self('Feedback needs a subject and a message — there is nothing here to act on.');
    }

    public static function invalidTransition(FeedbackStatus $from, FeedbackStatus $to): self
    {
        return new self(
            "Feedback cannot move from [{$from->value}] to [{$to->value}]."
            .($from === FeedbackStatus::Spam && $to === FeedbackStatus::Published
                ? ' Reclassify it as not-published first: publishing junk to a government site is never one click.'
                : '')
        );
    }

    public static function reasonRequired(FeedbackStatus $to): self
    {
        return new self(
            "Moving feedback to [{$to->value}] requires a stated reason — refusing to publish a citizen's "
            .'complaint is a decision that goes on the record.'
        );
    }

    public static function responseRequiresPublishedParent(): self
    {
        return new self(
            'A public response can only be attached to published feedback. Publish the feedback first, '
            .'or mark the response internal.'
        );
    }

    public static function emptyResponse(): self
    {
        return new self('A response needs a body.');
    }
}
