<?php

namespace App\Actions\Feedback;

use App\Enums\FeedbackChannel;
use App\Enums\FeedbackStatus;
use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Exceptions\Feedback\HoneypotTripped;
use App\Models\Feedback;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The portal's ONLY write.
 *
 * Everything else a visitor can reach is a read of published data; this is the
 * single place an anonymous stranger puts a row in a government database, so
 * it carries every control the security rules ask for, in this order:
 *
 *  1. HONEYPOT — a field hidden from sight and from screen readers, labelled
 *     "leave this empty". Only automation fills it. Rejected before the rate
 *     limiter is even touched, so a bot storm cannot exhaust a real visitor's
 *     allowance from a shared NAT address.
 *  2. RATE LIMIT — per IP, through the named `portal-feedback` limiter that
 *     routes/portal.php registers. Both the HTTP route and this Action share
 *     MAX_PER_HOUR and throttleKey(), so the limiter is one limiter however
 *     the submission arrives (a Livewire update POST does not travel through
 *     the portal route's middleware — see the note in routes/portal.php).
 *  3. SPAM HEURISTIC — marks, never rejects. A filter that silently ate a
 *     genuine complaint about a stalled clinic would be a worse failure than a
 *     moderator seeing one line of junk, so obvious spam still lands in the
 *     queue, flagged, and a human rules on it.
 *  4. MODERATION — everything lands `pending`. Nothing a member of the public
 *     types is visible on the portal until a moderator publishes it.
 *
 * The forensic columns (ip_address, user_agent) are read from the request by
 * the caller and written by forceFill, never filled: they are not fillable
 * precisely so a crafted payload cannot forge the address a ban would be
 * issued against.
 */
class SubmitFeedback
{
    /** The named limiter registered in routes/portal.php. */
    public const LIMITER = 'portal-feedback';

    /**
     * Submissions per IP per hour. Generous enough for a community meeting
     * where a dozen people report from one phone hotspot, tight enough that a
     * script cannot fill a moderation queue overnight.
     */
    public const MAX_PER_HOUR = 5;

    public const DECAY_SECONDS = 3600;

    /** Below this, a "complaint" is not actionable — it is a test message. */
    public const MIN_BODY_LENGTH = 20;

    /** Links in a feedback body above this count are advertising, not feedback. */
    public const MAX_LINKS = 1;

    /** Longest user agent we keep; the column is 512 and the header is attacker-controlled. */
    private const MAX_USER_AGENT = 500;

    /**
     * The throttle key. Hashed, so the rate-limiter cache (which is not a
     * government record store and may live on a shared Redis) never holds a
     * readable list of the IP addresses that contacted the state about public
     * projects.
     */
    public static function throttleKey(?string $ip): string
    {
        return self::LIMITER.':'.sha1((string) $ip);
    }

    /**
     * @param  array{project_id?: int|null, subject?: string|null, body?: string|null, submitter_name?: string|null, submitter_email?: string|null, submitter_phone?: string|null}  $attributes
     * @param  string|null  $honeypot  the hidden field's submitted value
     */
    public function __invoke(
        array $attributes,
        ?string $honeypot = null,
        ?string $ip = null,
        ?string $userAgent = null,
        FeedbackChannel $channel = FeedbackChannel::Portal,
    ): Feedback {
        if (trim((string) $honeypot) !== '') {
            throw HoneypotTripped::make();
        }

        $key = self::throttleKey($ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            throw FeedbackRuleViolation::rateLimited(RateLimiter::availableIn($key));
        }

        $subject = trim((string) ($attributes['subject'] ?? ''));
        $body = trim((string) ($attributes['body'] ?? ''));

        if ($subject === '' || $body === '') {
            throw FeedbackRuleViolation::emptySubmission();
        }

        // Hit only once the submission is real: a validation failure must not
        // spend an honest visitor's allowance.
        RateLimiter::hit($key, self::DECAY_SECONDS);

        $spamReason = self::spamReason($body);

        $feedback = new Feedback;

        // Explicit whitelist, never ->fill($request->all()): the fillable list
        // is the second gate, and this array is the first.
        $feedback->fill([
            'project_id' => $attributes['project_id'] ?? null,
            'subject' => $subject,
            'body' => $body,
            'submitter_name' => self::optional($attributes['submitter_name'] ?? null),
            'submitter_email' => self::optional($attributes['submitter_email'] ?? null),
            'submitter_phone' => self::optional($attributes['submitter_phone'] ?? null),
            'channel' => $channel,
        ]);

        // Guarded-by-omission: none of these is fillable, so no payload can
        // self-publish, forge an IP, or clear its own spam flag.
        $feedback->forceFill([
            'status' => FeedbackStatus::Pending,
            'flagged_as_spam' => $spamReason !== null,
            'spam_reason' => $spamReason,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, self::MAX_USER_AGENT, ''),
        ])->save();

        return $feedback;
    }

    /**
     * The heuristic: length and link count, nothing cleverer. It exists to
     * sort a queue, not to make a decision — a false positive costs a
     * moderator two seconds, and a false negative costs nothing at all
     * because the submission was never going to be public without them.
     */
    public static function spamReason(string $body): ?string
    {
        $links = preg_match_all('~(https?://|www\.)~i', $body);

        if (is_int($links) && $links > self::MAX_LINKS) {
            return __('Contains :count links.', ['count' => $links]);
        }

        if (Str::length($body) < self::MIN_BODY_LENGTH) {
            return __('Shorter than :length characters.', ['length' => self::MIN_BODY_LENGTH]);
        }

        return null;
    }

    private static function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
