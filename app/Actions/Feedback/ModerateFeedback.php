<?php

namespace App\Actions\Feedback;

use App\Enums\FeedbackStatus;
use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of Feedback::$status — which is why that column is not
 * fillable and why this class is greppable as the one chokepoint.
 *
 * Order fails closed at the cheapest question first, exactly as
 * TransitionProjectStatus does it:
 *   1. the transition table (an impossible move is impossible for everyone),
 *   2. authorization (`feedback.moderate`, tenant-aware through the project),
 *   3. the domain precondition (refusing to publish needs a stated reason),
 *   4. the write, with the moderator and the moment stamped on the row.
 *
 * There is no separate event-ledger table here, unlike projects and progress
 * reports. The reason is that the moderation history of one short public
 * comment is fully carried by activitylog — Feedback logs `status`,
 * `moderated_by_id`, `moderated_at` and `moderation_reason` on every change,
 * append-only, with actor and IP. A typed ledger buys ordering and referential
 * queries that no screen on this module asks for.
 */
class ModerateFeedback
{
    public function __invoke(
        Feedback $feedback,
        FeedbackStatus $to,
        User $actor,
        ?string $reason = null,
    ): Feedback {
        $from = $feedback->status;

        if ($from === $to) {
            return $feedback;
        }

        if (! $from->canTransitionTo($to)) {
            throw FeedbackRuleViolation::invalidTransition($from, $to);
        }

        Gate::forUser($actor)->authorize('moderate', $feedback);

        $reason = $reason === null ? null : trim($reason);

        if ($to->requiresReason() && ($reason === null || $reason === '')) {
            throw FeedbackRuleViolation::reasonRequired($to);
        }

        // forceFill: every column here is deliberately not fillable, so the
        // assignment is explicit and no ->update($validated) can reach it.
        $feedback->forceFill([
            'status' => $to,
            'moderated_by_id' => $actor->id,
            'moderated_at' => now(),
            'moderation_reason' => $reason,
        ])->save();

        return $feedback;
    }
}
