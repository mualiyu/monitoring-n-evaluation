<?php

namespace App\Exceptions\Feedback;

/**
 * The hidden honeypot field came back filled in.
 *
 * Its own class rather than a message on FeedbackRuleViolation, because the
 * portal treats it differently from every other refusal: a human is told what
 * went wrong, a bot is told nothing at all and gets the ordinary thank-you
 * page. That branch has to be a `catch` on a type — comparing exception
 * strings is how a silent control quietly stops being silent.
 */
class HoneypotTripped extends FeedbackRuleViolation
{
    public static function make(): self
    {
        return new self('Submission rejected: the honeypot field was completed.');
    }
}
